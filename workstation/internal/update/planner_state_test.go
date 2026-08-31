package update

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func shaOf(b []byte) string {
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

// newTestPlanner returns a planner pinned to linux/amd64 on v0.2.0 with a real
// (writable) executable dir, so Status() reaches its terminal branches.
func newTestPlanner(t *testing.T) *Planner {
	t.Helper()
	exe := filepath.Join(t.TempDir(), "ws-server")
	if err := os.WriteFile(exe, []byte("old"), 0o755); err != nil {
		t.Fatal(err)
	}
	p := NewPlanner(t.TempDir())
	p.goos, p.goarch = "linux", "amd64"
	p.currentFn = func() string { return "v0.2.0" }
	p.executableFn = func() (string, error) { return exe, nil }
	return p
}

func onePlatformPackage(url, sha string) *Package {
	return &Package{
		Version:   "v0.3.0",
		Platforms: []Platform{{ID: "linux-amd64", URL: url, SHA256: sha, Size: 1}},
	}
}

// HQ flags a newer build but published no artifact for it (manifest gap):
// warn + point at /downloads, never pretend an assisted install is possible.
func TestStatus_NoPackageIsNeedsManual(t *testing.T) {
	p := newTestPlanner(t)
	p.SetExpected("v0.3.0", "security", nil, false)

	st := p.Status()
	if st.State != StateNeedsManual || st.CanApply {
		t.Errorf("state=%s can_apply=%v, want needs_manual/false", st.State, st.CanApply)
	}
	if st.BlockReason != "package_unavailable" {
		t.Errorf("block_reason = %q", st.BlockReason)
	}
	if st.PackageAvailable {
		t.Error("package_available must be false")
	}
}

// A package that lists zero platforms is the same situation as no package.
func TestStatus_EmptyPlatformListIsNeedsManual(t *testing.T) {
	p := newTestPlanner(t)
	p.SetExpected("v0.3.0", "", &Package{Version: "v0.3.0"}, false)

	if st := p.Status(); st.State != StateNeedsManual || st.CanApply {
		t.Errorf("state=%s can_apply=%v, want needs_manual/false", st.State, st.CanApply)
	}
}

// The shop runs an OS/arch HQ does not publish: say so, never invent a URL.
func TestStatus_UnsupportedPlatform(t *testing.T) {
	p := newTestPlanner(t)
	p.goos, p.goarch = "plan9", "mips"
	p.SetExpected("v0.3.0", "", onePlatformPackage("http://example.invalid/x", strings.Repeat("a", 64)), false)

	st := p.Status()
	if st.State != StateUnsupported || st.CanApply {
		t.Errorf("state=%s can_apply=%v, want unsupported/false", st.State, st.CanApply)
	}
	if st.BlockReason != "unsupported_platform" {
		t.Errorf("block_reason = %q", st.BlockReason)
	}
}

// A dev build must never be told to update itself — that is how a developer
// loses their working tree binary.
func TestStatus_DevBuildIsInert(t *testing.T) {
	p := newTestPlanner(t)
	p.currentFn = func() string { return "dev" }
	p.SetExpected("v0.3.0", "", onePlatformPackage("http://example.invalid/x", strings.Repeat("a", 64)), false)

	st := p.Status()
	if st.State != StateIdle || st.CanApply || st.BlockReason != "dev_build" {
		t.Errorf("dev status = %+v", st)
	}
}

// HQ turns the expectation off (rollback of a bad release): the staged build
// and the install CTA must disappear, not linger as a stale offer.
func TestSetExpected_ClearedDropsStagedAndCTA(t *testing.T) {
	p := newTestPlanner(t)
	staged := filepath.Join(p.updatesRoot, "v0.3.0", "ws-server-linux-amd64")
	if err := os.MkdirAll(filepath.Dir(staged), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(staged, []byte("new"), 0o755); err != nil {
		t.Fatal(err)
	}
	p.SetExpected("v0.3.0", "", onePlatformPackage("http://example.invalid/x", strings.Repeat("a", 64)), false)
	p.staged = staged
	p.state = StateReady
	if st := p.Status(); !st.CanApply {
		t.Fatalf("precondition: expected a ready update, got %+v", st)
	}

	p.SetExpected("", "", nil, false)

	st := p.Status()
	if st.CanApply {
		t.Error("can_apply must go false once HQ clears the expected build")
	}
	if st.StagedPath != "" {
		t.Errorf("staged_path = %q, want cleared", st.StagedPath)
	}
}

// Running the expected tag already: no CTA, no download.
func TestStatus_UpToDate(t *testing.T) {
	p := newTestPlanner(t)
	p.currentFn = func() string { return "v0.3.0" }
	p.SetExpected("v0.3.0", "", onePlatformPackage("http://example.invalid/x", strings.Repeat("a", 64)), false)

	if st := p.Status(); st.State != StateUpToDate || st.CanApply {
		t.Errorf("state=%s can_apply=%v, want up_to_date/false", st.State, st.CanApply)
	}
}

// THE ROLLOUT RACE: HQ bumps the expected version while a shop is still
// downloading the previous one (slow shop link, hot-fix re-tag). The finished
// old download must not be offered as if it were the new expectation —
// otherwise the operator clicks "install v0.3.1" and gets v0.3.0.
func TestSetExpected_SupersededDownloadIsNotOfferedAsTheNewVersion(t *testing.T) {
	body := []byte("v0.3.0-binary")
	release := make(chan struct{})
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		<-release
		_, _ = w.Write(body)
	}))
	t.Cleanup(srv.Close)

	p := newTestPlanner(t)
	p.httpClient = srv.Client()
	p.SetExpected("v0.3.0", "", onePlatformPackage(srv.URL+"/ws-server-linux-amd64", shaOf(body)), false)
	p.KickDownload(false)

	// Wait until the download is actually in flight.
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) && p.Status().State != StateDownloading {
		time.Sleep(5 * time.Millisecond)
	}

	// HQ re-tags mid-download; no artifact resolved for the new tag yet.
	p.SetExpected("v0.3.1", "hotfix re-tag", onePlatformPackage(srv.URL+"/ws-server-linux-amd64", shaOf(body)), false)
	close(release)

	deadline = time.Now().Add(time.Second)
	for time.Now().Before(deadline) {
		st := p.Status()
		if st.CanApply {
			if !strings.Contains(filepath.ToSlash(st.StagedPath), "/v0.3.1/") {
				t.Fatalf("offers can_apply for expected=%s but staged=%s", st.ExpectedVersion, st.StagedPath)
			}
			return // staged the new tag — correct
		}
		time.Sleep(20 * time.Millisecond)
	}
	// Not offering anything is also correct: the new tag simply has not
	// downloaded yet.
}

// #2427 — Settings polls Status every 2s. The writability probe creates and
// deletes a temp file in the directory holding the RUNNING binary, so it must
// only run when there is actually something to install.
func TestStatus_ProbesInstallDirOnlyWhenThereIsSomethingToInstall(t *testing.T) {
	p := newTestPlanner(t)
	exe, err := p.executableFn()
	if err != nil {
		t.Fatal(err)
	}
	probes := 0
	p.executableFn = func() (string, error) {
		probes++
		return exe, nil
	}
	pkg := onePlatformPackage("http://example.invalid/ws-server-linux-amd64", strings.Repeat("a", 64))

	// Nothing staged yet — mid-download, or HQ published no artifact.
	p.SetExpected("v0.3.0", "", pkg, false)
	p.Status()
	p.state = StateDownloading
	p.Status()
	p.SetExpected("v0.3.0", "", nil, false)
	p.Status()
	if probes != 0 {
		t.Errorf("probed the install dir %d times with nothing to install", probes)
	}

	// Staged and ready — now the answer matters, so one probe is expected.
	staged := filepath.Join(p.updatesRoot, "v0.3.0", "ws-server-linux-amd64")
	if err := os.MkdirAll(filepath.Dir(staged), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(staged, []byte("new"), 0o755); err != nil {
		t.Fatal(err)
	}
	p.SetExpected("v0.3.0", "", pkg, false)
	p.staged = staged
	p.state = StateReady
	if st := p.Status(); !st.CanApply {
		t.Fatalf("precondition: want a ready update, got %+v", st)
	}
	if probes != 1 {
		t.Errorf("probes = %d, want exactly 1 for a ready update", probes)
	}
}

// #2427 — the Settings retry button always forces. One flaky download must not
// throw away a staged build that is still on disk and still verifies, or the
// install button vanishes the instant the operator clicks it.
func TestKickDownload_FailedForcedRetryKeepsAVerifiedStagedBuild(t *testing.T) {
	body := []byte("already-staged-binary")
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "upstream down", http.StatusBadGateway)
	}))
	t.Cleanup(srv.Close)

	p := newTestPlanner(t)
	p.httpClient = srv.Client()
	// Staged under the expected version, hash matching the catalog — but the
	// catalog URL now names a different file, so the retry really downloads.
	staged := filepath.Join(p.updatesRoot, "v0.3.0", "ws-server-linux-amd64")
	if err := os.MkdirAll(filepath.Dir(staged), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(staged, body, 0o755); err != nil {
		t.Fatal(err)
	}
	p.SetExpected("v0.3.0", "", onePlatformPackage(srv.URL+"/ws-server-linux-amd64-b", shaOf(body)), false)
	p.staged = staged
	p.state = StateReady

	p.KickDownload(true)

	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		p.mu.Lock()
		downloading := p.downloading
		p.mu.Unlock()
		if !downloading {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}

	st := p.Status()
	if st.StagedPath != staged || !st.CanApply {
		t.Errorf("a verified staged build was dropped by a failed retry: %+v", st)
	}
	if st.Error == "" {
		t.Error("the failed retry must still be reported")
	}
}

// The mirror image: when the file backing `staged` is gone, a failed retry must
// clear it rather than advertise an install that cannot happen.
func TestKickDownload_FailedRetryDropsStagedWhenTheFileIsGone(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "upstream down", http.StatusBadGateway)
	}))
	t.Cleanup(srv.Close)

	p := newTestPlanner(t)
	p.httpClient = srv.Client()
	p.SetExpected("v0.3.0", "", onePlatformPackage(srv.URL+"/ws-server-linux-amd64", strings.Repeat("a", 64)), false)
	p.staged = filepath.Join(p.updatesRoot, "v0.3.0", "swept-away")
	p.state = StateReady

	p.KickDownload(true)

	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		if st := p.Status(); st.State == StateError {
			if st.CanApply || st.StagedPath != "" {
				t.Fatalf("stale staged path survived: %+v", st)
			}
			return
		}
		time.Sleep(10 * time.Millisecond)
	}
	t.Fatalf("timed out; status=%+v", p.Status())
}

// A version string is used as a directory name; anything that can climb out of
// the updates root must be refused outright.
func TestSafeVersion_RejectsPathEscapes(t *testing.T) {
	for _, v := range []string{"", "  ", "..", "../etc", "v0.3.0/../..", `v0.3.0\..`, "a/b"} {
		if got := safeVersion(v); got != "" {
			t.Errorf("safeVersion(%q) = %q, want \"\" (rejected)", v, got)
		}
	}
	for _, v := range []string{"v0.3.0", "2026.8.10e", "v1.2.3-rc.1"} {
		if got := safeVersion(v); got != v {
			t.Errorf("safeVersion(%q) = %q, want it kept", v, got)
		}
	}
}

func TestDownloadAndVerify_RejectsTraversalVersion(t *testing.T) {
	root := t.TempDir()
	_, err := downloadAndVerify(context.Background(), http.DefaultClient, root, "../escape",
		onePlatformPackage("http://example.invalid/x", strings.Repeat("a", 64)), "linux", "amd64", nil)
	if err == nil {
		t.Fatal("want a rejection for a traversal version")
	}
	if !strings.Contains(err.Error(), "invalid expected version") {
		t.Errorf("err = %v", err)
	}
}

// The artifact filename is taken from the catalog URL. It names a file inside
// <updates>/<version>/ and must never resolve outside it.
func TestDownloadAndVerify_HostileURLFilenameStaysInsideVersionDir(t *testing.T) {
	body := []byte("payload")
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write(body)
	}))
	t.Cleanup(srv.Close)

	for _, urlPath := range []string{"/downloads/..", "/downloads/../", "/.", "/"} {
		root := t.TempDir()
		got, err := downloadAndVerify(context.Background(), srv.Client(), root, "v0.3.0",
			onePlatformPackage(srv.URL+urlPath, shaOf(body)), "linux", "amd64", nil)
		if err == nil {
			want := filepath.Join(root, "v0.3.0")
			if filepath.Dir(got) != want {
				t.Errorf("url path %q staged at %q, outside %q", urlPath, got, want)
			}
			if st, statErr := os.Stat(got); statErr != nil || st.IsDir() {
				t.Errorf("url path %q produced %q which is not a regular file", urlPath, got)
			}
		}
		// Whether it staged or refused, nothing may be written beside the
		// version directory (a stray `<root>.partial` means Join climbed out).
		entries, readErr := os.ReadDir(root)
		if readErr != nil {
			t.Fatal(readErr)
		}
		for _, e := range entries {
			if e.Name() != "v0.3.0" {
				t.Errorf("url path %q leaked %q into the updates root", urlPath, e.Name())
			}
		}
	}
}
