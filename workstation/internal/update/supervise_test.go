package update

import (
	"errors"
	"os"
	"testing"
	"time"
)

// fakeProc records the kill the watchdog owes a child that never got healthy.
type fakeProc struct{ killed bool }

func (f *fakeProc) Kill() error { f.killed = true; return nil }

// superviseFixture stands up a swapped-binary-on-disk scene: `current` already
// holds the NEW build (Apply succeeded), `.bak` holds the one that was running.
func superviseFixture(t *testing.T) (p *Planner, current string, spawned *[]string, exitCodes *[]int, proc *fakeProc) {
	t.Helper()
	dir := t.TempDir()
	current = dir + "/ws-server"
	if err := os.WriteFile(current, []byte("NEW"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(current+".bak", []byte("OLD"), 0o755); err != nil {
		t.Fatal(err)
	}

	p = NewPlanner(t.TempDir())
	p.executableFn = func() (string, error) { return current, nil }

	calls := &[]string{}
	codes := &[]int{}
	proc = &fakeProc{}
	p.spawnFn = func(exe string, args, env []string) (Process, error) {
		*calls = append(*calls, exe)
		return proc, nil
	}
	p.exitFn = func(code int) { *codes = append(*codes, code) }
	return p, current, calls, codes, proc
}

func fastOpts() SuperviseOptions {
	return SuperviseOptions{
		HealthURL:    "http://127.0.0.1:1/api/lan/health",
		Timeout:      10 * time.Millisecond,
		PollInterval: time.Millisecond,
		FromVersion:  "v0.2.0",
		ToVersion:    "v0.3.0",
	}
}

// The biggest #2635 risk: swap OK, restart, new binary dead on boot, nobody
// standing there. The watchdog must kill the child, put `.bak` back on the
// executable path, leave the marker + per-version block, and respawn the old
// binary — or the shop opens in the morning with a dead workstation.
func TestRestartSupervised_UnhealthyChildRollsBackToBak(t *testing.T) {
	p, current, spawned, exitCodes, proc := superviseFixture(t)
	p.probeFn = func(url string) error { return errors.New("connection refused") }

	shutdownCalled := false
	p.RestartSupervised(func() { shutdownCalled = true }, fastOpts())

	if !shutdownCalled {
		t.Fatal("shutdown must run before the spawn — the child needs the LAN port")
	}
	if got, _ := os.ReadFile(current); string(got) != "OLD" {
		t.Fatalf("current binary = %q, want the rolled-back OLD build", got)
	}
	if got, _ := os.ReadFile(current + ".failed"); string(got) != "NEW" {
		t.Fatalf(".failed = %q, want the dead NEW build kept as evidence", got)
	}
	if !proc.killed {
		t.Fatal("the unhealthy child must be killed before the rollback")
	}
	if len(*spawned) != 2 {
		t.Fatalf("spawn calls = %d, want 2 (new binary, then restored old one)", len(*spawned))
	}
	if len(*exitCodes) != 1 || (*exitCodes)[0] != 0 {
		t.Fatalf("exit codes = %v, want [0] after handing over to the restored binary", *exitCodes)
	}

	// The restored binary's next boot consumes the marker (alert + audit)…
	m, err := p.ConsumeAutoUpdateMarker()
	if err != nil || m == nil {
		t.Fatalf("marker = %v, %v — the rollback must leave its one-shot trail", m, err)
	}
	if m.Outcome != "rolled_back" || m.FromVersion != "v0.2.0" || m.ToVersion != "v0.3.0" {
		t.Fatalf("marker = %+v", m)
	}
	// …exactly once.
	if m2, _ := p.ConsumeAutoUpdateMarker(); m2 != nil {
		t.Fatalf("marker consumed twice: %+v", m2)
	}

	// And the broken version is barred from unattended retry — without this
	// the restored binary wakes inside the same window, sees the same staged
	// build, and crash-loops the shop all night.
	if got := p.AutoApplyBlockedVersion(); got != "v0.3.0" {
		t.Fatalf("blocked version = %q, want v0.3.0", got)
	}
}

func TestRestartSupervised_HealthyChildKeepsNewBinary(t *testing.T) {
	p, current, spawned, exitCodes, proc := superviseFixture(t)
	p.probeFn = func(url string) error { return nil }

	p.RestartSupervised(func() {}, fastOpts())

	if got, _ := os.ReadFile(current); string(got) != "NEW" {
		t.Fatalf("current binary = %q, want the NEW build kept", got)
	}
	if proc.killed {
		t.Fatal("a healthy child must not be killed")
	}
	if len(*spawned) != 1 {
		t.Fatalf("spawn calls = %d, want exactly 1", len(*spawned))
	}
	if len(*exitCodes) != 1 || (*exitCodes)[0] != 0 {
		t.Fatalf("exit codes = %v, want [0]", *exitCodes)
	}
	if m, _ := p.ConsumeAutoUpdateMarker(); m != nil {
		t.Fatalf("no marker expected on success, got %+v", m)
	}
	if got := p.AutoApplyBlockedVersion(); got != "" {
		t.Fatalf("no version block expected on success, got %q", got)
	}
}

// Spawn failing outright (exec format error — i.e. a corrupt artifact) is the
// same condition as an unhealthy boot and takes the same rollback.
func TestRestartSupervised_SpawnFailureRollsBack(t *testing.T) {
	p, current, spawned, exitCodes, _ := superviseFixture(t)
	firstSpawn := true
	p.spawnFn = func(exe string, args, env []string) (Process, error) {
		*spawned = append(*spawned, exe)
		if firstSpawn {
			firstSpawn = false
			return nil, errors.New("exec format error")
		}
		return &fakeProc{}, nil
	}
	p.probeFn = func(url string) error { return errors.New("unreachable") }

	p.RestartSupervised(func() {}, fastOpts())

	if got, _ := os.ReadFile(current); string(got) != "OLD" {
		t.Fatalf("current binary = %q, want OLD restored", got)
	}
	if len(*spawned) != 2 {
		t.Fatalf("spawn calls = %d, want 2", len(*spawned))
	}
	if len(*exitCodes) != 1 || (*exitCodes)[0] != 0 {
		t.Fatalf("exit codes = %v, want [0]", *exitCodes)
	}
}
