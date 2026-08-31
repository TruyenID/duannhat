package update

// #2635 — unattended 2 AM install: the biggest risk is not the swap, it is the
// boot AFTER the swap. apply.go already restores `.bak` when the swap itself
// fails, but a swap that succeeds and then a new binary that dies on startup
// used to leave the shop opening in the morning with a dead workstation and
// nobody aware until service hours. A manual apply never had this problem —
// the operator was standing there watching it boot.
//
// RestartSupervised is the unattended counterpart of Restart: the OLD process
// (known-good) stays alive as a watchdog. It shuts its own server down to free
// the LAN port, spawns the new binary, then polls the health endpoint. Healthy
// within the deadline → exit and hand over. Not healthy → kill the child, roll
// the binary back to `.bak`, write a marker (the restored binary raises the
// alert + audit on its next boot — this process's DB/server are already torn
// down), persist a per-version block so the scheduler cannot crash-loop the
// same broken build every night, and respawn the old binary.

import (
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// Process is the minimal child-process handle the watchdog needs.
// *os.Process implements it.
type Process interface {
	Kill() error
}

// SuperviseOptions configures the post-restart health check.
type SuperviseOptions struct {
	// HealthURL is polled until it answers 200 (e.g.
	// http://127.0.0.1:8080/api/lan/health).
	HealthURL string
	// Timeout bounds the whole health wait. Zero = DefaultHealthTimeout.
	Timeout time.Duration
	// PollInterval between probes. Zero = DefaultHealthPollInterval.
	PollInterval time.Duration
	// FromVersion/ToVersion feed the rollback marker + block file so the
	// audit trail can say which unattended swap failed.
	FromVersion string
	ToVersion   string
}

// DefaultHealthTimeout is generous on purpose: a boot includes SQLite
// migrations and the listener bind, normally seconds — but the shop is closed
// at 2 AM, so waiting a full minute costs nothing, while declaring a healthy
// build dead because an antivirus scan slowed its first start costs a needless
// rollback and a false alert.
const DefaultHealthTimeout = 60 * time.Second

// DefaultHealthPollInterval keeps the probe cheap while catching a fast boot
// quickly.
const DefaultHealthPollInterval = time.Second

// autoUpdateMarkerName is the one-shot "the unattended update rolled back"
// note, consumed (alert + audit) by the next boot of the restored binary.
const autoUpdateMarkerName = "auto_update_result.json"

// autoApplyBlockName persists "never auto-apply this version again". Without
// it the restored old binary would wake inside the same 02:00–04:00 window,
// see the same staged build still Ready, and crash-loop the shop all night.
const autoApplyBlockName = "auto_apply_block.json"

// AutoUpdateMarker records how an unattended restart ended badly.
type AutoUpdateMarker struct {
	Outcome     string `json:"outcome"` // "rolled_back" | "rollback_failed"
	FromVersion string `json:"from_version"`
	ToVersion   string `json:"to_version"`
	Detail      string `json:"detail"`
	At          string `json:"at"` // RFC3339 UTC
}

// autoApplyBlock persists the version an unattended install must never retry.
type autoApplyBlock struct {
	Version string `json:"version"`
	Reason  string `json:"reason"`
	At      string `json:"at"`
}

// RestartSupervised performs shutdown → spawn → health-poll, rolling back to
// `.bak` when the new binary never becomes healthy. It must run AFTER Apply
// succeeded and never returns on the success path (the process exits).
func (p *Planner) RestartSupervised(shutdown func(), opts SuperviseOptions) {
	p.mu.Lock()
	executableFn := p.executableFn
	spawnFn := p.spawnFn
	probeFn := p.probeFn
	exitFn := p.exitFn
	p.mu.Unlock()

	if spawnFn == nil {
		spawnFn = spawnReplacement
	}
	if probeFn == nil {
		probeFn = probeHealth
	}
	if exitFn == nil {
		exitFn = os.Exit
	}
	if opts.Timeout <= 0 {
		opts.Timeout = DefaultHealthTimeout
	}
	if opts.PollInterval <= 0 {
		opts.PollInterval = DefaultHealthPollInterval
	}

	exe, err := resolveExecutable(executableFn)
	if err != nil {
		// Nothing to hand over to — stay up on the old process rather than
		// exiting into a shop with no server. Same posture as Restart.
		slog.Error("auto update: cannot resolve executable, not restarting", "err", err)
		return
	}
	if shutdown != nil {
		shutdown()
	}

	child, err := spawnFn(exe, os.Args, os.Environ())
	if err != nil {
		p.rollBackAndRespawn(exe, spawnFn, exitFn, opts, "spawn failed: "+err.Error())
		return
	}

	deadline := time.Now().Add(opts.Timeout)
	for {
		if err := probeFn(opts.HealthURL); err == nil {
			slog.Info("auto update: replacement healthy, exiting",
				"exe", exe, "to", opts.ToVersion)
			exitFn(0)
			return
		}
		if time.Now().After(deadline) {
			break
		}
		time.Sleep(opts.PollInterval)
	}

	if child != nil {
		_ = child.Kill()
	}
	p.rollBackAndRespawn(exe, spawnFn, exitFn, opts,
		fmt.Sprintf("health check %s did not answer 200 within %s", opts.HealthURL, opts.Timeout))
}

// rollBackAndRespawn restores `.bak`, leaves the marker + per-version block for
// the next boot, and hands the shop back to the old binary. Marker and block
// are written BEFORE the respawn so the restored process cannot boot ahead of
// the evidence it is supposed to consume.
func (p *Planner) rollBackAndRespawn(exe string, spawnFn func(string, []string, []string) (Process, error), exitFn func(int), opts SuperviseOptions, detail string) {
	slog.Error("auto update: replacement unhealthy, rolling back",
		"exe", exe, "from", opts.FromVersion, "to", opts.ToVersion, "detail", detail)

	outcome := "rolled_back"
	if err := rollbackBinary(exe); err != nil {
		// The broken new binary stays on the path; a supervisor restarting it
		// may still crash-loop, but there is nothing better to hand over to.
		outcome = "rollback_failed"
		detail = detail + "; rollback failed: " + err.Error()
		slog.Error("auto update: rollback failed", "exe", exe, "err", err)
	}

	p.writeAutoUpdateMarker(AutoUpdateMarker{
		Outcome:     outcome,
		FromVersion: opts.FromVersion,
		ToVersion:   opts.ToVersion,
		Detail:      detail,
		At:          time.Now().UTC().Format(time.RFC3339),
	})
	p.blockAutoApply(opts.ToVersion, detail)

	if _, err := spawnFn(exe, os.Args, os.Environ()); err != nil {
		slog.Error("auto update: respawn after rollback failed", "exe", exe, "err", err)
		exitFn(1)
		return
	}
	exitFn(0)
}

// probeHealth is the production health probe: one cheap GET, 200 = alive.
func probeHealth(url string) error {
	if strings.TrimSpace(url) == "" {
		return errors.New("no health url configured")
	}
	client := &http.Client{Timeout: 2 * time.Second}
	resp, err := client.Get(url)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("health returned %d", resp.StatusCode)
	}
	return nil
}

func (p *Planner) markerPath() string { return filepath.Join(p.updatesRoot, autoUpdateMarkerName) }
func (p *Planner) blockPath() string  { return filepath.Join(p.updatesRoot, autoApplyBlockName) }

func (p *Planner) writeAutoUpdateMarker(m AutoUpdateMarker) {
	if err := writeJSONFile(p.markerPath(), m); err != nil {
		slog.Error("auto update: write rollback marker failed", "err", err)
	}
}

// ConsumeAutoUpdateMarker reads and deletes the one-shot rollback marker.
// (nil, nil) when there is none — the overwhelmingly common boot.
func (p *Planner) ConsumeAutoUpdateMarker() (*AutoUpdateMarker, error) {
	path := p.markerPath()
	raw, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	// Remove first: a marker that cannot be parsed must still not re-alert on
	// every boot forever.
	_ = os.Remove(path)
	var m AutoUpdateMarker
	if err := json.Unmarshal(raw, &m); err != nil {
		return nil, fmt.Errorf("parse auto-update marker: %w", err)
	}
	return &m, nil
}

// blockAutoApply persists "this version already failed unattended — never
// auto-retry it". Manual installs are unaffected: an operator standing at the
// machine can watch a boot the scheduler must not gamble on.
func (p *Planner) blockAutoApply(version, reason string) {
	version = strings.TrimSpace(version)
	if version == "" {
		return
	}
	if err := writeJSONFile(p.blockPath(), autoApplyBlock{
		Version: version,
		Reason:  reason,
		At:      time.Now().UTC().Format(time.RFC3339),
	}); err != nil {
		slog.Error("auto update: write auto-apply block failed", "err", err)
	}
}

// AutoApplyBlockedVersion returns the version barred from unattended install,
// or "". The block is keyed by version on purpose: an HQ re-tag to a fixed
// build makes it inert without anyone having to clean a file on a shop PC.
func (p *Planner) AutoApplyBlockedVersion() string {
	raw, err := os.ReadFile(p.blockPath())
	if err != nil {
		return ""
	}
	var b autoApplyBlock
	if err := json.Unmarshal(raw, &b); err != nil {
		return ""
	}
	return strings.TrimSpace(b.Version)
}

func writeJSONFile(path string, v any) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	raw, err := json.Marshal(v)
	if err != nil {
		return err
	}
	return os.WriteFile(path, raw, 0o644)
}
