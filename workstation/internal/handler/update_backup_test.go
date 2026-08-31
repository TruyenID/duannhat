package handler

// #2659 (lỗ 2) — the pre-update database copy is a PRECONDITION of installing.
//
// Every test here asserts the install itself, never merely the error: the
// failure mode being prevented is "backup failed and the binary got swapped
// anyway", which an error-only assertion cannot see.
//
// Failure is injected through the free-space probe — a seam BELOW the copy and
// ABOVE nothing, so the guard being measured (snapshot → Apply, in the two
// handlers) is real code in both directions. Deleting the backup directory
// instead would land the error somewhere else entirely: MkdirAll recreates it.

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// wireUpdateBackups gives the server a real Maintenance pointed at a temp
// backup dir — the same construction server.New does, minus the schedule.
func wireUpdateBackups(t *testing.T, s *Server) string {
	t.Helper()
	backupDir := filepath.Join(filepath.Dir(s.db.Path()), "backups")
	s.maintenance = service.NewMaintenance(s.db, service.MaintenanceConfig{
		BackupDir: backupDir,
	})
	return backupDir
}

func preUpdateCopies(t *testing.T, backupDir string) []string {
	t.Helper()
	entries, err := os.ReadDir(backupDir)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		t.Fatalf("read backup dir: %v", err)
	}
	var out []string
	for _, e := range entries {
		if strings.HasPrefix(e.Name(), "pre-update-") {
			out = append(out, e.Name())
		}
	}
	return out
}

// starveDisk makes the free-space precondition fail the way a full shop PC
// does, without needing a full disk.
func starveDisk(s *Server) {
	s.maintenance.SetDiskFreeFunc(func(string) (uint64, error) { return 1024, nil })
}

// --- manual path (operator's button) ----------------------------------------

func TestUpdateApply_BackupFailureBlocksTheInstall(t *testing.T) {
	s := newFireTestServer(t)
	backupDir := wireUpdateBackups(t, s)
	f := &fakeUpdatePlanner{st: readyAutoApplyStatus()}
	s.updater = f
	starveDisk(s)

	w := httptest.NewRecorder()
	s.handleUpdateApply(w, httptest.NewRequest(http.MethodPost, "/api/update/apply", nil))

	// The load-bearing assertion: the binary swap did not happen.
	if f.applied != 0 {
		t.Fatalf("Apply ran %d times despite a failed backup — the install must not proceed", f.applied)
	}
	if w.Code != http.StatusConflict {
		t.Fatalf("status = %d, want 409; body=%s", w.Code, w.Body.String())
	}
	var body struct {
		Code    string `json:"code"`
		Message string `json:"message"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &body); err != nil {
		t.Fatal(err)
	}
	if body.Code != "backup_failed" {
		t.Fatalf("code = %q, want backup_failed", body.Code)
	}
	// Out of disk must be readable as such, not as a generic failure — the
	// operator has to know what to clear.
	if !strings.Contains(body.Message, "disk space") {
		t.Fatalf("message must name the disk, got %q", body.Message)
	}
	if got := preUpdateCopies(t, backupDir); len(got) != 0 {
		t.Fatalf("no copy should exist after a failed backup, got %v", got)
	}
}

// A build with no backup subsystem wired at all must refuse just as hard —
// "nothing to snapshot with" is not "nothing to snapshot".
func TestUpdateApply_NoBackupSubsystemBlocksTheInstall(t *testing.T) {
	s := newFireTestServer(t)
	s.maintenance = nil
	f := &fakeUpdatePlanner{st: readyAutoApplyStatus()}
	s.updater = f

	w := httptest.NewRecorder()
	s.handleUpdateApply(w, httptest.NewRequest(http.MethodPost, "/api/update/apply", nil))

	if f.applied != 0 {
		t.Fatalf("Apply ran %d times with no backup subsystem", f.applied)
	}
	if w.Code != http.StatusConflict {
		t.Fatalf("status = %d, want 409", w.Code)
	}
}

func TestUpdateApply_TakesThePreUpdateCopyThenInstalls(t *testing.T) {
	s := newFireTestServer(t)
	backupDir := wireUpdateBackups(t, s)
	f := &fakeUpdatePlanner{st: readyAutoApplyStatus()}
	s.updater = f
	// Park the restart far outside the test: the handler answers first and
	// hands over on a timer, and that timer would tear down a test server.
	prev := updateRestartDelay
	updateRestartDelay = time.Hour
	t.Cleanup(func() { updateRestartDelay = prev })

	w := httptest.NewRecorder()
	s.handleUpdateApply(w, httptest.NewRequest(http.MethodPost, "/api/update/apply", nil))

	if w.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200; body=%s", w.Code, w.Body.String())
	}
	if f.applied != 1 {
		t.Fatalf("Apply calls = %d, want 1", f.applied)
	}
	copies := preUpdateCopies(t, backupDir)
	if len(copies) != 1 {
		t.Fatalf("expected exactly one pre-update copy, got %v", copies)
	}
	if !strings.Contains(copies[0], "v0.3.0") {
		t.Fatalf("copy %q must name the version being installed", copies[0])
	}
	fi, err := os.Stat(filepath.Join(backupDir, copies[0]))
	if err != nil || fi.Size() == 0 {
		t.Fatalf("pre-update copy is missing or empty: %v", err)
	}
}

// --- unattended path (2 AM, #2635) ------------------------------------------

func TestAutoApply_BackupFailureBlocksTheUnattendedInstall(t *testing.T) {
	s, f, restarts := newAutoUpdateTestServer(t)
	backupDir := filepath.Join(filepath.Dir(s.db.Path()), "backups")
	starveDisk(s)

	if s.tryScheduledAutoApply(utcInWindow, time.UTC) {
		t.Fatal("a failed backup must not report an install")
	}
	if f.applied != 0 {
		t.Fatalf("Apply ran %d times despite a failed backup", f.applied)
	}
	select {
	case r := <-restarts:
		t.Fatalf("restart scheduled after a failed backup: %v", r)
	default:
	}
	if got := preUpdateCopies(t, backupDir); len(got) != 0 {
		t.Fatalf("no copy should exist after a failed backup, got %v", got)
	}

	// Nobody is awake at 2 AM: the reason has to survive in the audit trail.
	if n := countAuditRows(t, s, "update.auto_apply_skipped"); n != 1 {
		t.Fatalf("auto_apply_skipped rows = %d, want 1", n)
	}
	if d := auditDetailsFor(t, s, "update.auto_apply_skipped"); !strings.Contains(d, "disk space") {
		t.Fatalf("audit reason must say why, got %q", d)
	}
	// Terminal outcome ⇒ one attempt per night, not a VACUUM every minute
	// until 04:00.
	if s.tryScheduledAutoApply(utcInWindow.Add(time.Minute), time.UTC) {
		t.Fatal("the night must be latched after a backup failure")
	}
	if n := countAuditRows(t, s, "update.auto_apply_skipped"); n != 1 {
		t.Fatalf("re-tick added audit rows: %d", n)
	}
}

func TestAutoApply_TakesThePreUpdateCopyBeforeInstalling(t *testing.T) {
	s, f, restarts := newAutoUpdateTestServer(t)
	backupDir := filepath.Join(filepath.Dir(s.db.Path()), "backups")

	if !s.tryScheduledAutoApply(utcInWindow, time.UTC) {
		t.Fatal("in window, no shift, flag on — must install")
	}
	if f.applied != 1 {
		t.Fatalf("Apply calls = %d, want 1", f.applied)
	}
	select {
	case <-restarts:
	case <-time.After(2 * time.Second):
		t.Fatal("supervised restart was never scheduled")
	}
	copies := preUpdateCopies(t, backupDir)
	if len(copies) != 1 {
		t.Fatalf("expected exactly one pre-update copy, got %v", copies)
	}
	if !strings.Contains(copies[0], "v0.3.0") {
		t.Fatalf("copy %q must name the version being installed", copies[0])
	}
}

// The copy is taken BEFORE the swap, not after it. Ordering is the whole
// feature: a copy taken after the new binary booted has already been migrated.
func TestAutoApply_CopyExistsBeforeApplyRuns(t *testing.T) {
	s, _, _ := newAutoUpdateTestServer(t)
	backupDir := filepath.Join(filepath.Dir(s.db.Path()), "backups")

	var seenAtApply []string
	s.updater = &orderingPlanner{
		fakeUpdatePlanner: fakeUpdatePlanner{st: readyAutoApplyStatus()},
		onApply:           func() { seenAtApply = preUpdateCopies(t, backupDir) },
	}

	if !s.tryScheduledAutoApply(utcInWindow, time.UTC) {
		t.Fatal("must install")
	}
	if len(seenAtApply) != 1 {
		t.Fatalf("the pre-update copy must already exist when Apply runs, saw %v", seenAtApply)
	}
}

type orderingPlanner struct {
	fakeUpdatePlanner
	onApply func()
}

func (o *orderingPlanner) Apply() error {
	o.onApply()
	return o.fakeUpdatePlanner.Apply()
}

// The seam itself must be able to fail — a probe that never errors would make
// every "blocked" assertion above vacuous.
func TestUpdateBackup_ProbeErrorIsSurfaced(t *testing.T) {
	s := newFireTestServer(t)
	wireUpdateBackups(t, s)
	s.maintenance.SetDiskFreeFunc(func(string) (uint64, error) {
		return 0, errors.New("statfs exploded")
	})

	if _, err := s.snapshotBeforeUpdate("v0.3.0"); err == nil {
		t.Fatal("an unusable free-space probe must block the copy")
	}
}
