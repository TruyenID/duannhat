package handler

// #2635 — unattended install at 2 AM for builds HQ flagged `auto_apply`.
//
// This is a NARROW exception to the assisted-update ruling S4 (#1806), not its
// repeal. S4 forbids silent binary swaps because a mid-shift workstation holds
// cash and a print queue with nobody standing there; at 2 AM the shop is
// closed and no shift is open, so that concern is gone — and the exception is
// only valid inside that window. Everything the manual path guards, this path
// guards identically:
//
//   - the flag is HQ's per-build `auto_apply`, never derived from severity —
//     otherwise every `critical` build becomes a 2 AM restart;
//   - "2 AM" is the SHOP's clock (#1091): the same shopLocation() ladder the
//     business-day range and shift reports use, so a VN (+07) and a JP (+09)
//     shop on the one UTC backend each restart in their own night;
//   - the window is a RANGE (02:00–04:00), not an instant: a PC that was off
//     at 02:00 sharp still catches the window later, and a night missed
//     entirely is retried the next night;
//   - the open-shift gate stays: a 24h shop, or a cashier who forgot to close,
//     means no install tonight — identical predicate to the manual handler;
//   - the swap goes through the very same Planner.Apply() (staged-hash
//     re-verify, apply latch, dir-writable probe) — no shortcut path.
//
// What the manual path never needed and this one must have: a health check
// AFTER the restart, because nobody is watching the boot. See
// update.RestartSupervised — unhealthy new binary ⇒ rollback to `.bak`, a
// marker consumed on the next boot (alert + audit), and a per-version block so
// a broken build cannot crash-loop the shop every night.

import (
	"context"
	"fmt"
	"log/slog"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/update"
)

// updatePlanner is the assisted-update surface the HTTP handlers and the
// unattended scheduler share. *update.Planner implements it; tests install
// scripted fakes so the scheduler's guards can be exercised without swapping
// the test binary on disk.
type updatePlanner interface {
	SetExpected(expected, reason string, pkg *update.Package, autoApply bool)
	KickDownload(force bool)
	Status() update.Status
	Apply() error
	Restart(shutdown func())
	RestartSupervised(shutdown func(), opts update.SuperviseOptions)
	AutoApplyBlockedVersion() string
	ConsumeAutoUpdateMarker() (*update.AutoUpdateMarker, error)
}

// The unattended window, in the SHOP's wall clock: [02:00, 04:00).
const (
	autoApplyWindowStartHour = 2
	autoApplyWindowEndHour   = 4
)

// autoApplyCheckInterval is how often the loop re-evaluates. One minute keeps
// the worst-case "shift closed at 03:58" miss small without doing filesystem
// probes all day — the tick exits on the window check for 22 hours of every 24.
const autoApplyCheckInterval = time.Minute

// autoApplyWindowContains reports whether `now` falls inside the shop-local
// unattended window. Pure — the caller supplies the shop location — so the
// timezone matrix can be tested against explicit zones, same pattern as
// businessDayRangeIn.
func autoApplyWindowContains(now time.Time, loc *time.Location) bool {
	if loc == nil {
		return false
	}
	h := now.In(loc).Hour()
	return h >= autoApplyWindowStartHour && h < autoApplyWindowEndHour
}

// runAutoUpdateLoop drives the scheduler until ctx is done.
func (s *Server) runAutoUpdateLoop(ctx context.Context) {
	ticker := time.NewTicker(autoApplyCheckInterval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			s.tryScheduledAutoApply(time.Now(), s.shopLocation())
		}
	}
}

// tryScheduledAutoApply is one scheduler evaluation. It returns true only when
// an install was applied and the supervised restart was handed off.
//
// One attempt per shop-local night: any terminal outcome (applied, shift open,
// blocked version, apply error) latches lastAutoApplyNight and the next try is
// the NEXT night — "còn ca ⇒ không cài, thử lại đêm sau" is the ruling, not
// "poll until the shift closes". A build not staged yet does NOT latch: the
// download may finish later inside the same window.
func (s *Server) tryScheduledAutoApply(now time.Time, loc *time.Location) bool {
	if s.updater == nil {
		return false
	}
	if !autoApplyWindowContains(now, loc) {
		return false
	}
	night := now.In(loc).Format("2006-01-02")
	if s.lastAutoApplyNight == night {
		return false
	}

	st := s.updater.Status()
	// The flag is the ONLY thing that arms this path. Severity never
	// substitutes: a `critical` build without auto_apply waits for a human.
	if !st.AutoApply {
		return false
	}
	if st.ExpectedVersion == "" || st.CurrentVersion == st.ExpectedVersion {
		return false
	}

	if blocked := s.updater.AutoApplyBlockedVersion(); blocked != "" && blocked == st.ExpectedVersion {
		s.lastAutoApplyNight = night
		s.auditAutoUpdate("update.auto_apply_skipped", st, "version already rolled back once — waiting for a re-tag")
		return false
	}

	if st.State != update.StateReady || !st.CanApply {
		// Not staged / not installable yet. Do not latch the night — a download
		// finishing at 03:10 should still get its chance tonight. A persistent
		// blocker (dir not writable) simply never reaches Ready+CanApply and
		// stays a Settings-visible condition, as it is for the manual path.
		return false
	}

	// The open-shift gate is IDENTICAL to the manual handler's: 2 AM can still
	// be mid-shift (24h shop, or a shift nobody closed).
	if s.hasInProgressShift() {
		s.lastAutoApplyNight = night
		s.auditAutoUpdate("update.auto_apply_skipped", st, "shift_in_progress")
		return false
	}

	// The pre-install database copy (#2659). Same precondition as the manual
	// path and it weighs more here: the new binary migrates the DB on its very
	// next boot, with nobody watching, and the binary rollback in supervise.go
	// does not undo a migration. A failure latches the night rather than
	// re-VACUUMing every minute until 04:00 — a full disk at 02:01 is still a
	// full disk at 02:02, and any terminal outcome latches on this path.
	if _, err := s.snapshotBeforeUpdate(st.ExpectedVersion); err != nil {
		s.lastAutoApplyNight = night
		s.auditAutoUpdate("update.auto_apply_skipped", st, err.Error())
		slog.Error("auto update: no pre-update backup, install skipped",
			"to", st.ExpectedVersion, "err", err)
		return false
	}

	// Same guarded path as the operator's button — Apply re-verifies the
	// staged hash and holds the apply latch; no scheduler shortcut.
	if err := s.updater.Apply(); err != nil {
		s.lastAutoApplyNight = night
		s.auditAutoUpdate("update.auto_apply_skipped", st, "apply failed: "+err.Error())
		return false
	}

	s.lastAutoApplyNight = night
	// Audit BEFORE the restart tears this process down: the row must exist
	// even if the new binary never boots.
	s.auditAutoUpdate("update.auto_apply", st, "")
	slog.Info("auto update: applied unattended, restarting supervised",
		"from", st.CurrentVersion, "to", st.ExpectedVersion)

	restart := s.autoRestartFn
	if restart == nil {
		restart = s.superviseAutoUpdateRestart
	}
	go restart(st.CurrentVersion, st.ExpectedVersion)
	return true
}

// superviseAutoUpdateRestart is the production restart: ordered teardown (the
// same one SIGTERM and the manual path run), then spawn + health-watch +
// rollback in the update package.
func (s *Server) superviseAutoUpdateRestart(from, to string) {
	s.updater.RestartSupervised(func() {
		if err := s.Stop(); err != nil {
			slog.Warn("auto update: shutdown before restart", "err", err)
		}
	}, update.SuperviseOptions{
		HealthURL:   fmt.Sprintf("http://127.0.0.1:%d/api/lan/health", s.port),
		FromVersion: from,
		ToVersion:   to,
	})
}

// auditAutoUpdate leaves the unattended trail: when, from which build to
// which, or why it was skipped. Nil-safe like the rest of the audit calls.
func (s *Server) auditAutoUpdate(action string, st update.Status, reason string) {
	if s.audit == nil {
		return
	}
	details := map[string]any{
		"from": st.CurrentVersion,
		"to":   st.ExpectedVersion,
	}
	if reason != "" {
		details["reason"] = reason
	}
	s.audit.Log("system", action, "update", st.ExpectedVersion, auditDetails(details), "")
}

// consumeAutoUpdateOutcome runs once at startup: if the previous run's
// unattended update rolled back (or failed to), surface it — an alert a human
// must ack, and an audit row. Startup is the right moment for the same reason
// SweepStaleReservations runs there: the only process that can report the
// outcome is the one that boots after it.
func (s *Server) consumeAutoUpdateOutcome() {
	if s.updater == nil {
		return
	}
	m, err := s.updater.ConsumeAutoUpdateMarker()
	if err != nil {
		slog.Warn("auto update: reading rollback marker failed", "err", err)
		return
	}
	if m == nil {
		return
	}

	title := "Tự cập nhật ban đêm thất bại — đã tự quay về bản cũ"
	if m.Outcome == "rollback_failed" {
		title = "Tự cập nhật ban đêm thất bại — VÀ quay về bản cũ cũng thất bại"
	}
	s.raiseAlert(service.KindAutoUpdateRollback, "update", title, map[string]any{
		"outcome": m.Outcome,
		"from":    m.FromVersion,
		"to":      m.ToVersion,
		"detail":  m.Detail,
		"at":      m.At,
	})
	if s.audit != nil {
		s.audit.Log("system", "update.auto_rollback", "update", m.ToVersion,
			auditDetails(map[string]any{
				"outcome": m.Outcome,
				"from":    m.FromVersion,
				"to":      m.ToVersion,
				"detail":  m.Detail,
				"at":      m.At,
			}), "")
	}
	slog.Error("auto update: previous unattended update ended badly",
		"outcome", m.Outcome, "from", m.FromVersion, "to", m.ToVersion, "detail", m.Detail)
}
