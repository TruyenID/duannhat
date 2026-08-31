package update

import (
	"context"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/config"
)

// Planner tracks the HQ-expected build, stages a verified binary under
// ~/.ws-app/updates/<version>/, and applies it on an explicit operator click.
//
// It never auto-replaces the running binary — Apply is always explicit, and
// the HTTP layer additionally gates on an open cashier shift.
type Planner struct {
	mu sync.Mutex

	updatesRoot string
	httpClient  *http.Client

	currentFn func() string // defaults to config.Version

	expected string
	reason   string
	pkg      *Package
	// autoApply is HQ's per-build unattended-install flag (#2635). Deliberately
	// NOT a severity level: collapsing them would turn every `critical` build
	// into a 2 AM restart, which is far broader than what was ruled.
	autoApply bool

	state    State
	progress int
	staged   string
	errMsg   string

	downloading bool
	downloadGen uint64 // bumped to cancel a superseded download
	// applying latches on the first Apply and never clears on success: once the
	// binary on disk has been swapped, a second swap would back the NEW binary
	// up over `.bak` and erase the only rollback copy the shop has.
	applying bool

	// Test seams — nil uses production behaviour.
	goos, goarch string
	executableFn func() (string, error)
	replaceFn    func(current, staged string) error
	restartFn    func(exe string, args, env []string) error
	exitFn       func(code int)
	// Supervised-restart seams (#2635).
	spawnFn func(exe string, args, env []string) (Process, error)
	probeFn func(url string) error
}

// NewPlanner stages downloads under <configDir>/updates.
func NewPlanner(configDir string) *Planner {
	return &Planner{
		updatesRoot: filepath.Join(configDir, "updates"),
		httpClient:  &http.Client{Timeout: 15 * time.Minute},
		currentFn:   func() string { return strings.TrimSpace(config.Version) },
		goos:        runtime.GOOS,
		goarch:      runtime.GOARCH,
		state:       StateIdle,
	}
}

// SetExpected pushes the HQ-expected version (+ optional package) into the
// planner. Empty expected clears any prior stale state. autoApply is the feed's
// per-build unattended-install flag — it travels WITH the version so an HQ
// re-tag can never leave a stale flag armed for a build it was not granted to.
func (p *Planner) SetExpected(expected, reason string, pkg *Package, autoApply bool) {
	p.mu.Lock()
	defer p.mu.Unlock()

	expected = strings.TrimSpace(expected)
	// HQ re-tagging mid-rollout is the normal case, not the exotic one: a shop
	// on a slow link is still pulling v0.3.0 when HQ bumps to v0.3.1. Without
	// this, the finished v0.3.0 download lands in `staged` while `expected`
	// already reads v0.3.1 — Settings then offers "install v0.3.1" and installs
	// v0.3.0. Retire the in-flight generation and drop a staged file that
	// belongs to the superseded tag.
	if expected != p.expected {
		p.downloadGen++
		p.downloading = false
		if p.staged != "" && !stagedMatches(p.staged, expected) {
			p.staged = ""
		}
	}
	p.expected = expected
	p.reason = strings.TrimSpace(reason)
	p.pkg = pkg
	// An empty expected build carries no permission to auto-install anything.
	p.autoApply = autoApply && expected != ""

	current := p.currentLocked()
	if expected == "" || current == "" || current == "dev" || current == expected {
		p.state = StateUpToDate
		if expected == "" || current == "dev" {
			p.state = StateIdle
		}
		p.progress = 0
		p.errMsg = ""
		if p.staged != "" && (expected == "" || !stagedMatches(p.staged, expected)) {
			p.staged = ""
		}
		return
	}

	if pkg == nil || len(pkg.Platforms) == 0 {
		p.state = StateNeedsManual
		p.progress = 0
		p.errMsg = ""
		return
	}

	if id := PlatformID(p.goos, p.goarch); id == "" {
		p.state = StateUnsupported
		p.errMsg = "this OS/arch is not published in the download catalog"
		return
	}

	if p.staged != "" && stagedMatches(p.staged, expected) && fileExists(p.staged) {
		p.state = StateReady
		p.progress = 100
		p.errMsg = ""
		return
	}

	if p.downloading {
		return
	}
	p.state = StateIdle
	p.progress = 0
}

// KickDownload starts a background download when the planner has a package and
// the running binary is stale. force=true re-downloads even if already staged.
// No-op when current version is "dev".
func (p *Planner) KickDownload(force bool) {
	p.mu.Lock()
	current := p.currentLocked()
	if current == "" || current == "dev" {
		p.mu.Unlock()
		return
	}
	if p.expected == "" || p.pkg == nil || len(p.pkg.Platforms) == 0 {
		p.mu.Unlock()
		return
	}
	if current == p.expected {
		p.mu.Unlock()
		return
	}
	if p.downloading {
		p.mu.Unlock()
		return
	}
	if !force && p.staged != "" && stagedMatches(p.staged, p.expected) && fileExists(p.staged) {
		p.state = StateReady
		p.progress = 100
		p.mu.Unlock()
		return
	}

	p.downloading = true
	p.state = StateDownloading
	p.progress = 0
	p.errMsg = ""
	p.downloadGen++
	gen := p.downloadGen
	expected := p.expected
	pkg := clonePackage(p.pkg)
	client := p.httpClient
	root := p.updatesRoot
	goos, goarch := p.goos, p.goarch
	p.mu.Unlock()

	go func() {
		path, err := downloadAndVerify(context.Background(), client, root, expected, pkg, goos, goarch, func(pct int) {
			p.mu.Lock()
			if p.downloadGen == gen {
				p.progress = pct
				p.state = StateDownloading
			}
			p.mu.Unlock()
		})

		p.mu.Lock()
		defer p.mu.Unlock()
		if p.downloadGen != gen {
			return
		}
		p.downloading = false
		if err != nil {
			p.state = StateError
			p.errMsg = err.Error()
			p.progress = 0
			// A failed retry must not throw away a build that is still on disk
			// and still verifies. The Settings retry button always forces, so
			// one flaky moment would otherwise make the install button vanish
			// the instant the operator clicked it. Verified, not just present:
			// keeping a corrupt file staged would be worse than dropping it.
			if stagedMatches(p.staged, p.expected) && p.stagedVerifiesLocked() {
				p.state = StateReady
				p.progress = 100
			} else {
				p.staged = ""
			}
			slog.Warn("assisted update download failed", "expected", expected,
				"kept_staged", p.state == StateReady, "err", err)
			return
		}
		p.staged = path
		p.state = StateReady
		p.progress = 100
		p.errMsg = ""
		slog.Info("assisted update staged", "expected", expected, "path", path)
	}()
}

// Status returns a snapshot for the Settings UI / HTTP API.
// shiftOpen and manualDownloadURL are filled by the handler.
//
// Settings polls this every 2s, so it does NO filesystem work on the common
// path. The install-dir writability probe writes a temp file into the directory
// holding the RUNNING binary — on a shop's Windows box that is the directory
// antivirus watches hardest — so it runs only when there is a staged build to
// install, and it runs OUTSIDE the mutex (the same mutex the download progress
// callback takes every 32KB; a slow AV scan here used to stall the progress bar).
func (p *Planner) Status() Status {
	p.mu.Lock()
	current := p.currentLocked()
	platformID := PlatformID(p.goos, p.goarch)
	st := Status{
		CurrentVersion:   current,
		ExpectedVersion:  p.expected,
		Reason:           p.reason,
		AutoApply:        p.autoApply,
		State:            p.state,
		ProgressPercent:  p.progress,
		StagedPath:       p.staged,
		PackageAvailable: p.pkg != nil && len(p.pkg.Platforms) > 0,
		Error:            p.errMsg,
		PlatformID:       platformID,
	}
	// stagedMatches: never advertise an install for a build that is not the one
	// `expected` names (see the re-tag note in SetExpected).
	staged := p.staged
	stagedIsExpected := p.state == StateReady && staged != "" && stagedMatches(staged, p.expected)
	executableFn := p.executableFn
	expected := p.expected
	state := p.state
	p.mu.Unlock()

	if current == "dev" {
		st.State = StateIdle
		st.BlockReason = "dev_build"
		return st
	}
	if expected == "" || current == expected {
		st.State = StateUpToDate
		return st
	}
	if !st.PackageAvailable {
		st.State = StateNeedsManual
		st.BlockReason = "package_unavailable"
		return st
	}
	if platformID == "" {
		st.State = StateUnsupported
		st.BlockReason = "unsupported_platform"
		return st
	}

	if stagedIsExpected && fileExists(staged) {
		writable, writeErr := executableDirWritable(executableFn)
		if !writable {
			st.State = StateDirNotWritable
			st.CanApply = false
			st.BlockReason = "dir_not_writable"
			if writeErr != "" {
				st.Error = writeErr
			}
			return st
		}
		st.CanApply = true
		return st
	}

	if state == StateError {
		st.BlockReason = "download_failed"
	}
	return st
}

// Apply swaps the running binary for the staged build. It does NOT restart —
// see Restart, which the caller runs after writing its HTTP response.
//
// The caller (HTTP handler) must already have gated on open shifts.
func (p *Planner) Apply() error {
	p.mu.Lock()
	if p.applying {
		p.mu.Unlock()
		return errApplyInFlight
	}
	if p.state != StateReady || p.staged == "" ||
		!stagedMatches(p.staged, p.expected) || !fileExists(p.staged) {
		p.mu.Unlock()
		return errNotReady
	}
	// Re-verify at the last possible moment. The download checked this hash,
	// but hours can pass between staging and the operator's click, and what is
	// about to happen is "overwrite the binary that runs the till".
	if !p.stagedVerifiesLocked() {
		staged := p.staged
		p.staged = ""
		p.state = StateError
		p.errMsg = "staged build no longer matches the published sha256"
		p.mu.Unlock()
		_ = os.Remove(staged)
		slog.Error("assisted update: staged build failed re-verification", "path", staged)
		return fmt.Errorf("staged build failed sha256 re-verification: %s", staged)
	}
	p.applying = true
	staged := p.staged
	replaceFn := p.replaceFn
	executableFn := p.executableFn
	p.mu.Unlock()

	// Un-latch only while the binary on disk is still the OLD one; once
	// replaceFn has run there is no safe second attempt (see the field comment).
	abort := func(err error) error {
		p.mu.Lock()
		p.applying = false
		p.mu.Unlock()
		return err
	}

	exe, err := resolveExecutable(executableFn)
	if err != nil {
		return abort(err)
	}

	writable, writeErr := executableDirWritable(executableFn)
	if !writable {
		if writeErr != "" {
			return abort(errDirNotWritable(writeErr))
		}
		return abort(errDirNotWritable("executable directory is not writable"))
	}

	if replaceFn == nil {
		replaceFn = replaceBinary
	}
	if err := replaceFn(exe, staged); err != nil {
		return abort(err)
	}
	slog.Info("assisted update: binary replaced, awaiting restart", "exe", exe, "staged", staged)
	return nil
}

// Restart hands the machine over to the binary Apply just installed: run
// `shutdown` to release the LAN port and drain print/sync, spawn the
// replacement, then exit.
//
// Two orderings here are load-bearing:
//
//   - Shutdown BEFORE spawn. The previous code spawned the child and exited in
//     the same breath, so the child raced the dying parent for the LAN port.
//     Losing that race means the replacement dies on "address already in use"
//     and the shop is left with no workstation server at all.
//   - This must run AFTER the HTTP response has been written. A graceful
//     shutdown waits for in-flight requests, and the apply request is one of
//     them — calling this from inside the handler would deadlock until the
//     5s shutdown timeout, and the operator would never receive the 200.
func (p *Planner) Restart(shutdown func()) {
	p.mu.Lock()
	executableFn := p.executableFn
	restartFn := p.restartFn
	exitFn := p.exitFn
	p.mu.Unlock()

	exe, err := resolveExecutable(executableFn)
	if err != nil {
		// Nothing to hand over to — stay up on the old process rather than
		// exiting into a shop with no server.
		slog.Error("assisted update: cannot resolve executable, not restarting", "err", err)
		return
	}
	if shutdown != nil {
		shutdown()
	}
	if restartFn == nil {
		restartFn = restartProcess
	}
	if exitFn == nil {
		exitFn = os.Exit
	}
	if err := restartFn(exe, os.Args, os.Environ()); err != nil {
		// The listener is already closed, so staying alive serves nobody. Exit
		// non-zero so a supervisor treats it as a failure and restarts us.
		slog.Error("assisted update: spawning the replacement failed", "exe", exe, "err", err)
		exitFn(1)
		return
	}
	slog.Info("assisted update: replacement spawned, exiting", "exe", exe)
	exitFn(0)
}

// stagedVerifiesLocked reports whether the staged file still hashes to the
// sha256 the catalog published for this platform. Callers must hold p.mu.
func (p *Planner) stagedVerifiesLocked() bool {
	if p.staged == "" || !fileExists(p.staged) {
		return false
	}
	plat, err := findPlatform(p.pkg, PlatformID(p.goos, p.goarch))
	if err != nil {
		return false
	}
	want := strings.ToLower(strings.TrimSpace(plat.SHA256))
	if want == "" {
		return false
	}
	ok, err := verifyFileSHA256(p.staged, want)
	return err == nil && ok
}

func (p *Planner) currentLocked() string {
	if p.currentFn != nil {
		return strings.TrimSpace(p.currentFn())
	}
	return strings.TrimSpace(config.Version)
}

func stagedMatches(path, version string) bool {
	if version == "" {
		return false
	}
	return strings.Contains(filepath.ToSlash(path), "/"+safeVersion(version)+"/")
}

func fileExists(path string) bool {
	st, err := os.Stat(path)
	return err == nil && !st.IsDir()
}

func clonePackage(pkg *Package) *Package {
	if pkg == nil {
		return nil
	}
	out := &Package{Version: pkg.Version, Platforms: make([]Platform, len(pkg.Platforms))}
	copy(out.Platforms, pkg.Platforms)
	return out
}

func safeVersion(v string) string {
	v = strings.TrimSpace(v)
	if v == "" || strings.Contains(v, "..") || strings.ContainsAny(v, `/\`) {
		return ""
	}
	return v
}
