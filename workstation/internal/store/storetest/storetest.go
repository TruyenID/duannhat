// Package storetest opens migrated SQLite databases for tests, fast.
//
// #1186 — the suite was not slow because of its logic. `store.Open` on a new
// file costs ~831ms, almost all of it executing ~75 migrations' DDL, and
// internal/service alone opens one per test across ~700 tests: 697 × 0.83s is
// essentially the whole 575s runtime. internal/service crossed Go's 600s
// default timeout and went red at random on a busy machine.
//
// Profiling found no hotspot to optimise — the top 15 slowest tests together
// account for 11% of the total — so the fix had to be the per-test cost
// itself. Here the migrations run ONCE per process into a template file, and
// each test gets a byte copy of it.
//
//	full migrate   831ms
//	copy + open     15ms      (measured, 10 iterations, 1 MB template)
//
// The copy is still handed to store.Open, so nothing here reimplements or
// bypasses the real open path: the incremental runner sees every migration
// already recorded and skips it, and repairLegacySchema still runs. A test
// gets exactly the database production would build, only without paying to
// rebuild it.
//
// Production code is untouched. store.Open keeps its behaviour for the real
// application, which opens one database per process and would gain nothing
// from a template it had to build first.
package storetest

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sync"

	"github.com/dxs-platform/workstation-app/internal/store"
)

var (
	templateOnce sync.Once
	templatePath string
	templateErr  error
)

// buildTemplate migrates one database and leaves it on disk for the rest of
// the process to copy.
//
// It lives in the OS temp directory rather than a t.TempDir(), which the Go
// runtime removes when its owning test finishes — the template has to outlive
// every individual test. The directory is small (~1 MB) and the OS reclaims
// it; deleting it here would need a TestMain in every consuming package.
func buildTemplate() {
	dir, err := os.MkdirTemp("", "ws-store-template-")
	if err != nil {
		templateErr = fmt.Errorf("storetest: create template dir: %w", err)

		return
	}

	path := filepath.Join(dir, "template.db")

	db, err := store.Open(path)
	if err != nil {
		templateErr = fmt.Errorf("storetest: migrate template: %w", err)

		return
	}
	if err := db.Close(); err != nil {
		templateErr = fmt.Errorf("storetest: close template: %w", err)

		return
	}

	templatePath = path
}

// Open returns a migrated database at path, copied from the process template.
//
// Signature-compatible with store.Open on purpose: a test that called
// `store.Open(p)` becomes `storetest.Open(p)` with its error handling and its
// choice of path untouched.
//
// An EXISTING file is opened as-is. Several tests simulate a workstation
// restart by closing a database and reopening the same path, and seeding
// unconditionally would silently wipe what the first half of the test wrote —
// the assertion then fails as "not restored after restart", which reads like a
// persistence bug in the code under test. Matching store.Open's semantics here
// is what keeps this helper a speed-up rather than a behaviour change.
func Open(path string) (*store.DB, error) {
	if _, err := os.Stat(path); err == nil {
		return store.Open(path)
	} else if !os.IsNotExist(err) {
		return nil, fmt.Errorf("storetest: stat %s: %w", path, err)
	}

	templateOnce.Do(buildTemplate)
	if templateErr != nil {
		return nil, templateErr
	}

	if err := copyFile(templatePath, path); err != nil {
		return nil, fmt.Errorf("storetest: seed %s: %w", path, err)
	}

	return store.Open(path)
}

// copyFile writes src over dst, creating dst's directory if the caller picked
// a nested path (several tests do).
func copyFile(src, dst string) error {
	if err := os.MkdirAll(filepath.Dir(dst), 0o755); err != nil {
		return err
	}

	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()

	out, err := os.Create(dst)
	if err != nil {
		return err
	}

	if _, err := io.Copy(out, in); err != nil {
		out.Close()

		return err
	}

	return out.Close()
}
