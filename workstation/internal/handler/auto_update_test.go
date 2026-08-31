package handler

// #2635 — the unattended 2 AM installer. Each test here is one real way this
// can go wrong on a shop PC with nobody standing there.

import (
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/update"
)

// fakeUpdatePlanner scripts the planner surface so the scheduler's guards can
// be exercised without swapping the test binary on disk.
type fakeUpdatePlanner struct {
	st      update.Status
	blocked string
	marker  *update.AutoUpdateMarker

	applied int
}

func (f *fakeUpdatePlanner) SetExpected(string, string, *update.Package, bool) {}
func (f *fakeUpdatePlanner) KickDownload(bool)                                 {}
func (f *fakeUpdatePlanner) Status() update.Status                             { return f.st }
func (f *fakeUpdatePlanner) Apply() error                                      { f.applied++; return nil }
func (f *fakeUpdatePlanner) Restart(func())                                    {}
func (f *fakeUpdatePlanner) RestartSupervised(func(), update.SuperviseOptions) {}
func (f *fakeUpdatePlanner) AutoApplyBlockedVersion() string                   { return f.blocked }
func (f *fakeUpdatePlanner) ConsumeAutoUpdateMarker() (*update.AutoUpdateMarker, error) {
	m := f.marker
	f.marker = nil
	return m, nil
}

// readyAutoApplyStatus is a staged, installable, HQ-flagged build — the state
// in which every guard below is the ONLY thing standing between the scheduler
// and a restart.
func readyAutoApplyStatus() update.Status {
	return update.Status{
		CurrentVersion:  "v0.2.0",
		ExpectedVersion: "v0.3.0",
		AutoApply:       true,
		State:           update.StateReady,
		CanApply:        true,
	}
}

func newAutoUpdateTestServer(t *testing.T) (*Server, *fakeUpdatePlanner, chan [2]string) {
	t.Helper()
	s := newFireTestServer(t)
	// #2659: no install proceeds without a pre-update database copy, so the
	// backup subsystem is part of a working scheduler, not scenery.
	wireUpdateBackups(t, s)
	f := &fakeUpdatePlanner{st: readyAutoApplyStatus()}
	s.updater = f
	restarts := make(chan [2]string, 4)
	s.autoRestartFn = func(from, to string) { restarts <- [2]string{from, to} }
	return s, f, restarts
}

func countAuditRows(t *testing.T, s *Server, action string) int {
	t.Helper()
	var n int
	if err := s.db.QueryRow(
		"SELECT COUNT(*) FROM audit_log WHERE action = ?", action).Scan(&n); err != nil {
		t.Fatal(err)
	}
	return n
}

func auditDetailsFor(t *testing.T, s *Server, action string) string {
	t.Helper()
	var d string
	if err := s.db.QueryRow(
		"SELECT COALESCE(details,'') FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1",
		action).Scan(&d); err != nil {
		t.Fatal(err)
	}
	return d
}

// inWindow / outOfWindow instants for a loc handed in explicitly — the same
// pure-core-plus-explicit-zones pattern businessDayRangeIn tests use.
var (
	utcInWindow    = time.Date(2026, 8, 13, 2, 30, 0, 0, time.UTC)
	utcOutOfWindow = time.Date(2026, 8, 13, 14, 30, 0, 0, time.UTC)
)

// The window is the SHOP's clock (#1091). One and the same UTC instant must
// land inside the window for a VN shop and outside it for a JP shop — and the
// other way around two hours earlier. A machine-clock or APP_TIMEZONE
// implementation passes one country and silently fails the other.
func TestAutoApplyWindow_SameUTCInstantDiffersByShopTimezone(t *testing.T) {
	vn := time.FixedZone("Asia/Ho_Chi_Minh", 7*3600)
	jp := time.FixedZone("Asia/Tokyo", 9*3600)

	// 19:30 UTC = 02:30 VN (in) = 04:30 JP (out).
	at1930 := time.Date(2026, 8, 12, 19, 30, 0, 0, time.UTC)
	if !autoApplyWindowContains(at1930, vn) {
		t.Fatal("19:30 UTC is 02:30 in VN — must be inside the window")
	}
	if autoApplyWindowContains(at1930, jp) {
		t.Fatal("19:30 UTC is 04:30 in JP — must be outside the window")
	}

	// 17:30 UTC = 02:30 JP (in) = 00:30 VN (out).
	at1730 := time.Date(2026, 8, 12, 17, 30, 0, 0, time.UTC)
	if !autoApplyWindowContains(at1730, jp) {
		t.Fatal("17:30 UTC is 02:30 in JP — must be inside the window")
	}
	if autoApplyWindowContains(at1730, vn) {
		t.Fatal("17:30 UTC is 00:30 in VN — must be outside the window")
	}

	// Boundaries: the window is [02:00, 04:00) — a range, not an instant.
	if !autoApplyWindowContains(time.Date(2026, 8, 13, 2, 0, 0, 0, time.UTC), time.UTC) {
		t.Fatal("02:00 sharp is inside the window")
	}
	if autoApplyWindowContains(time.Date(2026, 8, 13, 4, 0, 0, 0, time.UTC), time.UTC) {
		t.Fatal("04:00 sharp is outside the window")
	}
}

func TestAutoApply_OutsideWindowDoesNothing(t *testing.T) {
	s, f, restarts := newAutoUpdateTestServer(t)

	if s.tryScheduledAutoApply(utcOutOfWindow, time.UTC) {
		t.Fatal("14:30 shop time must never install")
	}
	if f.applied != 0 {
		t.Fatalf("Apply called %d times outside the window", f.applied)
	}
	select {
	case r := <-restarts:
		t.Fatalf("restart scheduled outside the window: %v", r)
	default:
	}
}

// 2 AM can still be mid-shift: a 24h shop, or a cashier who forgot to close.
// Open shift ⇒ no install tonight, retry the NEXT night — not a poll-until-
// closed inside the same window.
func TestAutoApply_InWindowButShiftOpenSkipsAndRetriesNextNight(t *testing.T) {
	s, f, restarts := newAutoUpdateTestServer(t)
	seedOpenShift(t, s)

	if s.tryScheduledAutoApply(utcInWindow, time.UTC) {
		t.Fatal("must not install while a shift is in progress")
	}
	if f.applied != 0 {
		t.Fatalf("Apply called %d times with an open shift", f.applied)
	}
	if n := countAuditRows(t, s, "update.auto_apply_skipped"); n != 1 {
		t.Fatalf("skip audit rows = %d, want 1 — an unattended no-op must leave a trail", n)
	}
	if d := auditDetailsFor(t, s, "update.auto_apply_skipped"); !strings.Contains(d, "shift_in_progress") {
		t.Fatalf("skip audit details = %q, want the shift_in_progress reason", d)
	}

	// Same night: latched, no second attempt and no audit spam.
	if s.tryScheduledAutoApply(utcInWindow.Add(30*time.Minute), time.UTC) {
		t.Fatal("second attempt in the same night must be latched")
	}
	if n := countAuditRows(t, s, "update.auto_apply_skipped"); n != 1 {
		t.Fatalf("skip audit rows after re-tick = %d, want still 1", n)
	}

	// Next night, shift closed ⇒ the missed install happens.
	if _, err := s.db.Exec(`UPDATE till_sessions SET status = 'settled'`); err != nil {
		t.Fatal(err)
	}
	if !s.tryScheduledAutoApply(utcInWindow.AddDate(0, 0, 1), time.UTC) {
		t.Fatal("next night with no shift must install")
	}
	if f.applied != 1 {
		t.Fatalf("Apply calls = %d, want 1", f.applied)
	}
	select {
	case <-restarts:
	case <-time.After(2 * time.Second):
		t.Fatal("supervised restart was never scheduled")
	}
}

// The flag is the ONLY thing that arms the unattended path. Severity never
// substitutes — HQ marking a build `critical` (carried in the stale_build
// alert detail) still waits for a human unless auto_apply is set. Folding the
// two together would turn every critical release into a 2 AM restart.
func TestAutoApply_FlagOffNeverInstallsEvenWhenStagedAndReady(t *testing.T) {
	s, f, restarts := newAutoUpdateTestServer(t)
	f.st.AutoApply = false
	f.st.Reason = "vá lỗi tiền — HQ severity critical"

	if s.tryScheduledAutoApply(utcInWindow, time.UTC) {
		t.Fatal("flag off must never install, whatever the severity")
	}
	if f.applied != 0 {
		t.Fatalf("Apply called %d times with auto_apply=false", f.applied)
	}
	select {
	case r := <-restarts:
		t.Fatalf("restart scheduled with auto_apply=false: %v", r)
	default:
	}
}

func TestAutoApply_InWindowNoShiftFlagOnInstalls(t *testing.T) {
	s, f, restarts := newAutoUpdateTestServer(t)

	if !s.tryScheduledAutoApply(utcInWindow, time.UTC) {
		t.Fatal("in window, no shift, flag on — must install")
	}
	if f.applied != 1 {
		t.Fatalf("Apply calls = %d, want 1", f.applied)
	}
	if n := countAuditRows(t, s, "update.auto_apply"); n != 1 {
		t.Fatalf("apply audit rows = %d, want 1 — an unattended swap must leave a trail", n)
	}
	if d := auditDetailsFor(t, s, "update.auto_apply"); !strings.Contains(d, "v0.2.0") || !strings.Contains(d, "v0.3.0") {
		t.Fatalf("apply audit details = %q, want from/to versions", d)
	}
	select {
	case r := <-restarts:
		if r != [2]string{"v0.2.0", "v0.3.0"} {
			t.Fatalf("restart versions = %v", r)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("supervised restart was never scheduled")
	}

	// Latched: the same night never installs twice.
	if s.tryScheduledAutoApply(utcInWindow.Add(time.Minute), time.UTC) {
		t.Fatal("same-night re-tick must be latched")
	}
	if f.applied != 1 {
		t.Fatalf("Apply calls after re-tick = %d, want still 1", f.applied)
	}
}

// A version that already died once unattended must not be retried every night
// — but an HQ re-tag to a fixed build makes the block inert.
func TestAutoApply_VersionBlockedAfterRollbackSkipsUntilRetag(t *testing.T) {
	s, f, _ := newAutoUpdateTestServer(t)
	f.blocked = "v0.3.0"

	if s.tryScheduledAutoApply(utcInWindow, time.UTC) {
		t.Fatal("a version that already rolled back must not be auto-retried")
	}
	if f.applied != 0 {
		t.Fatalf("Apply calls = %d, want 0", f.applied)
	}
	if n := countAuditRows(t, s, "update.auto_apply_skipped"); n != 1 {
		t.Fatalf("skip audit rows = %d, want 1", n)
	}

	// HQ re-tags to v0.3.1 — the stale block no longer matches.
	f.st.ExpectedVersion = "v0.3.1"
	if !s.tryScheduledAutoApply(utcInWindow.AddDate(0, 0, 1), time.UTC) {
		t.Fatal("a re-tagged version must install")
	}
}

// Boot-time consumption of the rollback marker: the restored binary is the
// only process that can tell anyone the 2 AM update failed.
func TestConsumeAutoUpdateOutcome_RaisesAlertAndAuditsOnce(t *testing.T) {
	s, f, _ := newAutoUpdateTestServer(t)
	s.alerts = service.NewAlertEmitter(service.NewAlertStore(s.db), nil)
	f.marker = &update.AutoUpdateMarker{
		Outcome:     "rolled_back",
		FromVersion: "v0.2.0",
		ToVersion:   "v0.3.0",
		Detail:      "health check did not answer 200",
		At:          "2026-08-13T02:05:00Z",
	}

	s.consumeAutoUpdateOutcome()

	var alerts int
	if err := s.db.QueryRow(
		"SELECT COUNT(*) FROM alerts WHERE kind = ?", string(service.KindAutoUpdateRollback)).Scan(&alerts); err != nil {
		t.Fatal(err)
	}
	if alerts != 1 {
		t.Fatalf("rollback alerts = %d, want 1", alerts)
	}
	if n := countAuditRows(t, s, "update.auto_rollback"); n != 1 {
		t.Fatalf("rollback audit rows = %d, want 1", n)
	}

	// The marker is one-shot — a second boot must not re-alert.
	s.consumeAutoUpdateOutcome()
	if n := countAuditRows(t, s, "update.auto_rollback"); n != 1 {
		t.Fatalf("rollback audit rows after second boot = %d, want still 1", n)
	}
}
