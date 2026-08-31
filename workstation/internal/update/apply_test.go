package update

import (
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"testing"
)

// stagedPlanner builds a planner already parked in StateReady with a staged
// file, so the tests below exercise Apply itself rather than the download.
// Returns the planner, the fake executable path and the staged path.
func stagedPlanner(t *testing.T, exeBytes, stagedBytes string) (*Planner, string, string) {
	t.Helper()

	exeDir := t.TempDir()
	exe := filepath.Join(exeDir, "ws-server")
	if err := os.WriteFile(exe, []byte(exeBytes), 0o755); err != nil {
		t.Fatal(err)
	}
	staged := filepath.Join(t.TempDir(), "v0.3.0", "ws-server-linux-amd64")
	if err := os.MkdirAll(filepath.Dir(staged), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(staged, []byte(stagedBytes), 0o755); err != nil {
		t.Fatal(err)
	}

	p := NewPlanner(t.TempDir())
	p.goos, p.goarch = "linux", "amd64"
	p.currentFn = func() string { return "v0.2.0" }
	p.executableFn = func() (string, error) { return exe, nil }
	p.expected = "v0.3.0"
	// Real hash: Apply re-verifies the staged bytes right before the swap.
	p.pkg = &Package{
		Version: "v0.3.0",
		Platforms: []Platform{{
			ID:     "linux-amd64",
			URL:    "http://example.invalid/ws-server-linux-amd64",
			SHA256: shaOf([]byte(stagedBytes)),
			Size:   int64(len(stagedBytes)),
		}},
	}
	p.state = StateReady
	p.progress = 100
	p.staged = staged
	return p, exe, staged
}

// The whole point of the feature: the running binary is swapped for the staged
// one and the previous binary survives as `.bak` — the only rollback the shop
// has when a release turns out bad mid-service.
func TestApply_ReplacesBinaryAndKeepsBackup(t *testing.T) {
	p, exe, _ := stagedPlanner(t, "OLD-BINARY", "NEW-BINARY")
	p.restartFn = func(string, []string, []string) error {
		t.Fatal("Apply must not restart — that is Restart's job, after the HTTP response")
		return nil
	}
	p.exitFn = func(int) { t.Fatal("Apply must not exit") }

	if err := p.Apply(); err != nil {
		t.Fatalf("Apply: %v", err)
	}

	assertFileBytes(t, exe, "NEW-BINARY")
	assertFileBytes(t, exe+".bak", "OLD-BINARY")
	if _, err := os.Stat(exe + ".new"); !os.IsNotExist(err) {
		t.Errorf(".new must not survive a successful apply")
	}
	if mode := statMode(t, exe); mode&0o111 == 0 {
		t.Errorf("replaced binary is not executable: %v", mode)
	}
}

// #2425 — a second Apply must be refused. Two overlapping swaps destroy the
// rollback copy: the second `rm .bak` + `rename current→.bak` backs the NEW
// binary up over the old one.
func TestApply_SecondCallIsRefusedAndBackupSurvives(t *testing.T) {
	p, exe, _ := stagedPlanner(t, "OLD-BINARY", "NEW-BINARY")

	if err := p.Apply(); err != nil {
		t.Fatalf("first Apply: %v", err)
	}
	err := p.Apply()
	if err == nil {
		t.Fatal("second Apply must be refused")
	}
	if !IsApplyInFlight(err) {
		t.Errorf("want errApplyInFlight, got %v", err)
	}
	assertFileBytes(t, exe, "NEW-BINARY")
	assertFileBytes(t, exe+".bak", "OLD-BINARY")
}

// #2425 — the staged file is re-hashed at the last moment. Hours can pass
// between staging and the operator's click, and the thing about to be
// overwritten is the binary that runs the till.
func TestApply_RefusesWhenStagedBytesNoLongerMatchSHA256(t *testing.T) {
	p, exe, staged := stagedPlanner(t, "OLD-BINARY", "NEW-BINARY")
	// Corrupt after staging — a truncated write, a bad sector, a tampered file.
	if err := os.WriteFile(staged, []byte("NEW-BINARY-tampered"), 0o755); err != nil {
		t.Fatal(err)
	}
	p.exitFn = func(int) { t.Fatal("must not exit") }

	if err := p.Apply(); err == nil {
		t.Fatal("want a verification failure")
	}
	assertFileBytes(t, exe, "OLD-BINARY")
	if _, err := os.Stat(staged); !os.IsNotExist(err) {
		t.Error("a staged build that failed verification must be deleted, not left to be retried")
	}
	st := p.Status()
	if st.CanApply || st.State != StateError {
		t.Errorf("status after failed verification = %+v", st)
	}
}

// #2426 — Restart must release the LAN port BEFORE spawning the replacement.
// Spawning first (the old behaviour) races the dying parent for :8080, and
// losing that race leaves the shop with no workstation server at all.
func TestRestart_ShutsDownBeforeSpawningThenExits(t *testing.T) {
	p, exe, _ := stagedPlanner(t, "OLD-BINARY", "NEW-BINARY")
	if err := p.Apply(); err != nil {
		t.Fatalf("Apply: %v", err)
	}

	var order []string
	restartedExe := ""
	exitCode := -1
	p.restartFn = func(e string, args, env []string) error {
		order = append(order, "spawn")
		restartedExe = e
		return nil
	}
	p.exitFn = func(code int) {
		order = append(order, "exit")
		exitCode = code
	}

	p.Restart(func() { order = append(order, "shutdown") })

	if len(order) != 3 || order[0] != "shutdown" || order[1] != "spawn" || order[2] != "exit" {
		t.Fatalf("order = %v, want [shutdown spawn exit]", order)
	}
	if restartedExe != exe {
		t.Errorf("spawned %q, want the replaced binary %q", restartedExe, exe)
	}
	if exitCode != 0 {
		t.Errorf("exit code = %d, want 0", exitCode)
	}
}

// If the replacement cannot be spawned the listener is already closed, so
// staying alive serves nobody — exit non-zero so a supervisor restarts us.
func TestRestart_ExitsNonZeroWhenSpawnFails(t *testing.T) {
	p, _, _ := stagedPlanner(t, "OLD-BINARY", "NEW-BINARY")
	if err := p.Apply(); err != nil {
		t.Fatalf("Apply: %v", err)
	}
	exitCode := -1
	p.restartFn = func(string, []string, []string) error { return errors.New("exec format error") }
	p.exitFn = func(code int) { exitCode = code }

	p.Restart(nil)

	if exitCode != 1 {
		t.Errorf("exit code = %d, want 1", exitCode)
	}
}

// Nothing staged → Apply must refuse instead of "replacing" the binary with
// whatever happens to be at the remembered path.
func TestApply_RefusesWhenNothingStaged(t *testing.T) {
	p, exe, _ := stagedPlanner(t, "OLD", "NEW")
	p.state = StateIdle
	p.staged = ""
	p.restartFn = func(string, []string, []string) error {
		t.Fatal("must not restart when nothing is staged")
		return nil
	}
	p.exitFn = func(int) { t.Fatal("must not exit when nothing is staged") }

	if err := p.Apply(); err == nil {
		t.Fatal("want errNotReady, got nil")
	}
	assertFileBytes(t, exe, "OLD")
}

// State says Ready but the staged file was swept (disk cleanup, manual rm).
// Apply must refuse and Status must stop advertising can_apply.
func TestApply_RefusesWhenStagedFileVanished(t *testing.T) {
	p, exe, staged := stagedPlanner(t, "OLD", "NEW")
	if err := os.Remove(staged); err != nil {
		t.Fatal(err)
	}
	p.exitFn = func(int) { t.Fatal("must not exit") }

	if err := p.Apply(); err == nil {
		t.Fatal("want errNotReady for a vanished staged file")
	}
	assertFileBytes(t, exe, "OLD")

	if st := p.Status(); st.CanApply {
		t.Errorf("Status.can_apply = true for a vanished staged file: %+v", st)
	}
}

// A root-owned / read-only install dir is the common Windows-service and
// /usr/local case: fail with the typed error the handler turns into a
// dir_not_writable 409 + manual download link, and leave the binary alone.
func TestApply_DirNotWritableIsTypedAndLeavesBinary(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("directory permission bits are not enforced the same way on Windows")
	}
	if os.Geteuid() == 0 {
		t.Skip("root ignores the write bit")
	}
	p, exe, _ := stagedPlanner(t, "OLD", "NEW")
	dir := filepath.Dir(exe)
	if err := os.Chmod(dir, 0o555); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = os.Chmod(dir, 0o755) })

	p.exitFn = func(int) { t.Fatal("must not exit when the dir is not writable") }

	err := p.Apply()
	if err == nil {
		t.Fatal("want dir-not-writable error")
	}
	if !IsDirNotWritable(err) {
		t.Errorf("IsDirNotWritable(%v) = false — the handler would 409 with the wrong code", err)
	}
	assertFileBytes(t, exe, "OLD")

	if st := p.Status(); st.State != StateDirNotWritable || st.CanApply {
		t.Errorf("Status = %+v, want state=dir_not_writable can_apply=false", st)
	}
}

// replaceBinary is the piece that has to survive Windows' "cannot overwrite a
// running exe" rule; assert the rename dance directly.
func TestReplaceBinary_SwapsAndBacksUp(t *testing.T) {
	dir := t.TempDir()
	current := filepath.Join(dir, "ws-server")
	staged := filepath.Join(dir, "staged-bin")
	if err := os.WriteFile(current, []byte("v1"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(staged, []byte("v2"), 0o644); err != nil {
		t.Fatal(err)
	}

	if err := replaceBinary(current, staged); err != nil {
		t.Fatalf("replaceBinary: %v", err)
	}
	assertFileBytes(t, current, "v2")
	assertFileBytes(t, current+".bak", "v1")
	// Staged copy stays put — a failed restart must still have something to
	// re-apply without re-downloading.
	assertFileBytes(t, staged, "v2")
	if mode := statMode(t, current); mode&0o111 == 0 {
		t.Errorf("current not executable after swap: %v", mode)
	}
}

func assertFileBytes(t *testing.T, path, want string) {
	t.Helper()
	got, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read %s: %v", path, err)
	}
	if string(got) != want {
		t.Errorf("%s = %q, want %q", path, got, want)
	}
}

func statMode(t *testing.T, path string) os.FileMode {
	t.Helper()
	st, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	return st.Mode()
}
