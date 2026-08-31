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

func TestDownloadAndVerify_Success(t *testing.T) {
	body := []byte("ws-server-binary-v0.3.0")
	sum := sha256.Sum256(body)
	wantSHA := hex.EncodeToString(sum[:])

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
		_, _ = w.Write(body)
	}))
	t.Cleanup(srv.Close)

	root := t.TempDir()
	pkg := &Package{
		Version: "v0.3.0",
		Platforms: []Platform{{
			ID:     "linux-amd64",
			URL:    srv.URL + "/downloads/workstation/v0.3.0/ws-server-linux-amd64",
			SHA256: wantSHA,
			Size:   int64(len(body)),
		}},
	}

	var lastPct int
	path, err := downloadAndVerify(context.Background(), srv.Client(), root, "v0.3.0", pkg, "linux", "amd64", func(pct int) {
		lastPct = pct
	})
	if err != nil {
		t.Fatalf("downloadAndVerify: %v", err)
	}
	got, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if string(got) != string(body) {
		t.Fatalf("staged bytes mismatch")
	}
	if lastPct != 100 {
		t.Fatalf("progress = %d, want 100", lastPct)
	}
	if filepath.Base(path) != "ws-server-linux-amd64" {
		t.Fatalf("filename = %s", filepath.Base(path))
	}
}

func TestDownloadAndVerify_SHA256Mismatch(t *testing.T) {
	body := []byte("tampered-binary")
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write(body)
	}))
	t.Cleanup(srv.Close)

	root := t.TempDir()
	pkg := &Package{
		Version: "v0.3.0",
		Platforms: []Platform{{
			ID:     "linux-amd64",
			URL:    srv.URL + "/ws-server-linux-amd64",
			SHA256: "0000000000000000000000000000000000000000000000000000000000000000",
			Size:   int64(len(body)),
		}},
	}

	_, err := downloadAndVerify(context.Background(), srv.Client(), root, "v0.3.0", pkg, "linux", "amd64", nil)
	if err == nil {
		t.Fatal("expected sha256 mismatch error")
	}
	if !strings.Contains(err.Error(), "sha256 mismatch") {
		t.Fatalf("err = %v, want sha256 mismatch", err)
	}
	// Partial must not remain as a final artifact.
	entries, _ := os.ReadDir(filepath.Join(root, "v0.3.0"))
	for _, e := range entries {
		if e.Name() == "ws-server-linux-amd64" {
			t.Fatal("mismatched binary was left staged")
		}
	}
}

func TestPlanner_KickDownload_StagesReady(t *testing.T) {
	body := []byte("ready-binary")
	sum := sha256.Sum256(body)
	wantSHA := hex.EncodeToString(sum[:])
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write(body)
	}))
	t.Cleanup(srv.Close)

	exeDir := t.TempDir()
	exePath := filepath.Join(exeDir, "ws-server")
	if err := os.WriteFile(exePath, []byte("old"), 0o755); err != nil {
		t.Fatal(err)
	}

	p := NewPlanner(t.TempDir())
	p.goos, p.goarch = "linux", "amd64"
	p.currentFn = func() string { return "v0.2.0" }
	p.executableFn = func() (string, error) { return exePath, nil }
	p.httpClient = srv.Client()
	p.SetExpected("v0.3.0", "security patch", &Package{
		Version: "v0.3.0",
		Platforms: []Platform{{
			ID:     "linux-amd64",
			URL:    srv.URL + "/ws-server-linux-amd64",
			SHA256: wantSHA,
			Size:   int64(len(body)),
		}},
	}, false)
	p.KickDownload(false)

	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		st := p.Status()
		if st.State == StateReady && st.CanApply {
			return
		}
		if st.State == StateError {
			t.Fatalf("download errored: %s", st.Error)
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatalf("timed out waiting for ready; status=%+v", p.Status())
}

func TestPlanner_SkipDownloadForDev(t *testing.T) {
	p := NewPlanner(t.TempDir())
	p.currentFn = func() string { return "dev" }
	called := false
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		called = true
		w.WriteHeader(http.StatusOK)
	}))
	t.Cleanup(srv.Close)
	p.httpClient = srv.Client()
	p.SetExpected("v0.3.0", "", &Package{
		Version: "v0.3.0",
		Platforms: []Platform{{
			ID: PlatformID("linux", "amd64"), URL: srv.URL + "/x", SHA256: "ab", Size: 1,
		}},
	}, false)
	p.KickDownload(true)
	time.Sleep(50 * time.Millisecond)
	if called {
		t.Fatal("dev build must not download")
	}
}
