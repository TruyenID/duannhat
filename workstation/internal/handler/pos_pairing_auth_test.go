package handler

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/config"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// newConfigManager builds a real config.Manager rooted in a temp dir with an
// explicit cloud URL. WS_APP_CLOUD_URL is the per-machine override an operator
// sets before the workstation has ever paired — exactly the state that used to
// produce a 503 (#2431).
func newConfigManager(t *testing.T, cloudURL string) *config.Manager {
	t.Helper()
	t.Setenv("WS_APP_CONFIG_DIR", t.TempDir())
	t.Setenv("WS_APP_CLOUD_URL", cloudURL)
	m, err := config.NewManager()
	if err != nil {
		t.Fatalf("config.NewManager: %v", err)
	}
	if got := m.Get().CloudAPIURL; got != cloudURL {
		t.Fatalf("config manager CloudAPIURL = %q, want %q", got, cloudURL)
	}
	return m
}

func setSetting(t *testing.T, s *Server, key, value string) {
	t.Helper()
	if _, err := s.db.Exec(
		"INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
		key, value,
	); err != nil {
		t.Fatalf("set setting %s: %v", key, err)
	}
}

// ─── cloudAPIURL: the 503 "auth verification unavailable" root cause ──────────

// #2431 root cause 2: a fresh (unpaired) workstation has no settings.cloud_api_url
// row, so cloudAPIURL() returned "" → CloudVerifier had no base URL →
// ErrCloudNotConfig → every POS request got 503 even though WS_APP_CLOUD_URL
// pointed at a perfectly reachable Cloud.
func TestCloudAPIURL_FallsBackToConfigWhenSettingsRowMissing(t *testing.T) {
	s := &Server{db: newTestDB(t), config: newConfigManager(t, "https://cloud.example.test")}

	if got := s.cloudAPIURL(); got != "https://cloud.example.test" {
		t.Fatalf("cloudAPIURL on fresh DB = %q, want the config fallback", got)
	}
}

// An empty-string settings row is the same condition as a missing one — some
// code paths write "" rather than deleting the key.
func TestCloudAPIURL_FallsBackToConfigWhenSettingsRowEmpty(t *testing.T) {
	s := &Server{db: newTestDB(t), config: newConfigManager(t, "https://cloud.example.test")}
	setSetting(t, s, "cloud_api_url", "")

	if got := s.cloudAPIURL(); got != "https://cloud.example.test" {
		t.Fatalf("cloudAPIURL with empty settings row = %q, want the config fallback", got)
	}
}

// Once paired (or changed in the Settings UI) the settings row is authoritative
// so an operator can repoint Cloud without restarting the process.
func TestCloudAPIURL_SettingsRowWinsOverConfig(t *testing.T) {
	s := &Server{db: newTestDB(t), config: newConfigManager(t, "https://config.example.test")}
	setSetting(t, s, "cloud_api_url", "https://settings.example.test")

	if got := s.cloudAPIURL(); got != "https://settings.example.test" {
		t.Fatalf("cloudAPIURL = %q, want the settings row to win", got)
	}
}

// No settings row AND no config manager (test servers that wire only the DB)
// must stay empty rather than panic — cloudAPIURL is called on every request.
func TestCloudAPIURL_EmptyWithoutSettingsOrConfig(t *testing.T) {
	s := &Server{db: newTestDB(t)}

	if got := s.cloudAPIURL(); got != "" {
		t.Fatalf("cloudAPIURL without settings or config = %q, want empty", got)
	}
}

// ─── cloudURLForPairing: pre-pair resolution ────────────────────────────────

// Acceptance #2431: a fresh unpaired workstation with WS_APP_CLOUD_URL set must
// be able to relay POST /api/v1/devices/pair to Cloud. This is the only state
// pos-web can pair from, so it must never resolve to "".
func TestCloudURLForPairing_ResolvesOnFreshUnpairedWorkstation(t *testing.T) {
	s := &Server{db: newTestDB(t), config: newConfigManager(t, "https://cloud.example.test")}

	if got := s.cloudURLForPairing(); got != "https://cloud.example.test" {
		t.Fatalf("cloudURLForPairing on fresh DB = %q, want the config URL", got)
	}
}

// Precedence lock. Since #2431 there is exactly ONE ladder (settings → config)
// and cloudURLForPairing is an alias of cloudAPIURL. A second resolution order
// anywhere means the device pairs against one host and is verified against
// another, which surfaces as an opaque 401/403 nowhere near its cause.
func TestCloudURLForPairing_MatchesCloudAPIURLPrecedence(t *testing.T) {
	s := &Server{db: newTestDB(t), config: newConfigManager(t, "https://config.example.test")}
	setSetting(t, s, "cloud_api_url", "https://settings.example.test")

	if got, want := s.cloudURLForPairing(), s.cloudAPIURL(); got != want {
		t.Fatalf("cloudURLForPairing = %q, cloudAPIURL = %q — the two must agree", got, want)
	}
	if got := s.cloudURLForPairing(); got != "https://settings.example.test" {
		t.Fatalf("cloudURLForPairing = %q, want the settings row", got)
	}
}

// The loopback ws-app pairing call must use that same ladder. It used to run
// its own config → settings order, so repointing Cloud in Settings moved auth
// verify and the pos-web relay but NOT the ws-app window's own pair button.
func TestHandleDevicePair_UsesTheSharedCloudResolver(t *testing.T) {
	var configHits, settingsHits int32
	configCloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&configHits, 1)
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer configCloud.Close()
	settingsCloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&settingsHits, 1)
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer settingsCloud.Close()

	s := &Server{db: newTestDB(t), config: newConfigManager(t, configCloud.URL)}
	setSetting(t, s, "cloud_api_url", settingsCloud.URL)

	body, _ := json.Marshal(map[string]string{"pairing_code": "ABC123"})
	req := httptest.NewRequest("POST", "/api/device/pair", bytes.NewReader(body))
	s.handleDevicePair(httptest.NewRecorder(), req)

	if atomic.LoadInt32(&settingsHits) != 1 {
		t.Errorf("settings-row Cloud hits = %d, want 1", settingsHits)
	}
	if got := atomic.LoadInt32(&configHits); got != 0 {
		t.Errorf("config Cloud hits = %d, want 0 — the settings row must win here too", got)
	}
}

// With neither source set there is nowhere to pair to; say so instead of
// posting to a relative URL.
func TestHandleDevicePair_RejectsWhenNoCloudURLResolves(t *testing.T) {
	s := &Server{db: newTestDB(t)}

	body, _ := json.Marshal(map[string]string{"pairing_code": "ABC123"})
	req := httptest.NewRequest("POST", "/api/device/pair", bytes.NewReader(body))
	rec := httptest.NewRecorder()
	s.handleDevicePair(rec, req)

	if rec.Code != http.StatusBadRequest {
		t.Fatalf("status = %d, want 400 (body=%s)", rec.Code, rec.Body.String())
	}
}

// ─── 403 BRANCH_MISMATCH: stable code on every branch-check path ─────────────

func decodeBody(t *testing.T, rec *httptest.ResponseRecorder) map[string]any {
	t.Helper()
	var body map[string]any
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("decode body %q: %v", rec.Body.String(), err)
	}
	return body
}

func assertBranchMismatchEnvelope(t *testing.T, rec *httptest.ResponseRecorder) {
	t.Helper()
	if rec.Code != http.StatusForbidden {
		t.Fatalf("status = %d, want 403 (body=%s)", rec.Code, rec.Body.String())
	}
	body := decodeBody(t, rec)
	// pos-web's api.ts clears the session on code=BRANCH_MISMATCH. Without the
	// code it falls back to substring-matching the message, so BOTH fields are
	// part of the contract.
	if body["code"] != "BRANCH_MISMATCH" {
		t.Errorf("code = %v, want BRANCH_MISMATCH", body["code"])
	}
	if body["message"] != "device branch mismatch" {
		t.Errorf("message = %v, want \"device branch mismatch\"", body["message"])
	}
}

// Path 1 — Cloud-verified identity from a different branch.
func TestBranchMismatch_CloudVerifiedPathCarriesStableCode(t *testing.T) {
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":{"id":"dev-1","type":"pos","branch_id":"branch-OTHER"}}`))
	}))
	defer cloud.Close()

	mw, _ := newTestMiddleware(t, cloud.URL, "branch-OURS", 5*time.Minute)
	req := httptest.NewRequest("GET", "/api/v1/pos/orders", nil)
	req.Header.Set("Authorization", "Bearer any")
	rec := httptest.NewRecorder()
	mw.Wrap(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {
		t.Fatal("handler must not be reached on branch mismatch")
	})).ServeHTTP(rec, req)

	assertBranchMismatchEnvelope(t, rec)
}

// Path 2 — fresh cache hit, no Cloud call at all.
func TestBranchMismatch_FreshCachePathCarriesStableCode(t *testing.T) {
	mw, cache := newTestMiddleware(t, "http://127.0.0.1:1", "branch-OURS", 5*time.Minute)
	if err := cache.Put(service.HashToken("cached"), "dev-1", "pos", "branch-OTHER", ""); err != nil {
		t.Fatalf("seed cache: %v", err)
	}

	req := httptest.NewRequest("GET", "/api/v1/pos/orders", nil)
	req.Header.Set("Authorization", "Bearer cached")
	rec := httptest.NewRecorder()
	mw.Wrap(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {
		t.Fatal("handler must not be reached on branch mismatch")
	})).ServeHTTP(rec, req)

	assertBranchMismatchEnvelope(t, rec)
}

// Path 3 — degraded mode: Cloud unreachable, only a stale cache entry left.
// A branch mismatch is a config error, never transient, so degraded mode must
// still reject it (and still name the reason).
func TestBranchMismatch_StaleCachePathCarriesStableCode(t *testing.T) {
	mw, cache := newTestMiddleware(t, "http://127.0.0.1:1", "branch-OURS", 50*time.Millisecond)
	if err := cache.Put(service.HashToken("stale"), "dev-1", "pos", "branch-OTHER", ""); err != nil {
		t.Fatalf("seed cache: %v", err)
	}
	time.Sleep(120 * time.Millisecond)

	req := httptest.NewRequest("GET", "/api/v1/pos/orders", nil)
	req.Header.Set("Authorization", "Bearer stale")
	rec := httptest.NewRecorder()
	mw.Wrap(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {
		t.Fatal("handler must not be reached on branch mismatch")
	})).ServeHTTP(rec, req)

	assertBranchMismatchEnvelope(t, rec)
}

// The 503 the recovery banner keys on. pos-web keeps the token and shows a
// reconnect banner ONLY for this exact message, so the wording is a contract.
func TestAuthVerificationUnavailable_MessageIsStable(t *testing.T) {
	// Paired: this pins the CLOUD-UNREACHABLE 503, not the #2442 unpaired one.
	mw, _ := newTestMiddleware(t, "http://127.0.0.1:1", "branch-OURS", 5*time.Minute)
	req := httptest.NewRequest("GET", "/api/v1/pos/orders", nil)
	req.Header.Set("Authorization", "Bearer unknown")
	rec := httptest.NewRecorder()
	mw.Wrap(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {
		t.Fatal("handler must not be reached")
	})).ServeHTTP(rec, req)

	if rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("status = %d, want 503 (body=%s)", rec.Code, rec.Body.String())
	}
	if msg := decodeBody(t, rec)["message"]; msg != "auth verification unavailable" {
		t.Errorf("message = %v, want \"auth verification unavailable\" "+
			"(pos-web isAuthVerificationUnavailable matches on this string)", msg)
	}
}

// ─── #2442: an unpaired workstation must not relay LAN tokens to Cloud ──────

// The harm the gate exists to stop: before it, branchOK() fail-closed only
// AFTER m.verifier.Verify(), so a workstation with no branch of its own handed
// every LAN client's bearer token to whatever cloudAPIURL() resolved — which,
// since the #2431 config fallback, is the compiled-in production default.
func TestUnpairedWorkstation_NeverSendsTheTokenToCloud(t *testing.T) {
	var cloudHits int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&cloudHits, 1)
		w.Write([]byte(`{"data":{"id":"dev-1","type":"pos","branch_id":"branch-A"}}`))
	}))
	defer cloud.Close()

	// wsBranch "" == workstation_branch_id unset == never paired.
	mw, _ := newTestMiddleware(t, cloud.URL, "", 5*time.Minute)
	req := httptest.NewRequest("GET", "/api/v1/pos/orders", nil)
	req.Header.Set("Authorization", "Bearer a-real-pos-token")
	rec := httptest.NewRecorder()
	mw.Wrap(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {
		t.Fatal("handler must not be reached on an unpaired workstation")
	})).ServeHTTP(rec, req)

	if got := atomic.LoadInt32(&cloudHits); got != 0 {
		t.Errorf("Cloud was contacted %d time(s); an unpaired workstation must keep the token on the LAN", got)
	}
	if rec.Code != http.StatusServiceUnavailable {
		t.Fatalf("status = %d, want 503 (body=%s)", rec.Code, rec.Body.String())
	}
}

// The code must NOT be BRANCH_MISMATCH. That one means "this device belongs to
// another shop" and pos-web clears the session on it; an unpaired workstation
// is a workstation-side gap that a perfectly good POS pairing did not cause,
// and wiping the till over it is the same failure shape as the "invalid token"
// prose sniffing removed in da1f101ef.
func TestUnpairedWorkstation_DoesNotMasqueradeAsBranchMismatch(t *testing.T) {
	mw, _ := newTestMiddleware(t, "http://127.0.0.1:1", "", 5*time.Minute)
	req := httptest.NewRequest("GET", "/api/v1/pos/orders", nil)
	req.Header.Set("Authorization", "Bearer any")
	rec := httptest.NewRecorder()
	mw.Wrap(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {})).ServeHTTP(rec, req)

	body := decodeBody(t, rec)
	if body["code"] != "WORKSTATION_NOT_PAIRED" {
		t.Errorf("code = %v, want WORKSTATION_NOT_PAIRED", body["code"])
	}
	if body["code"] == "BRANCH_MISMATCH" {
		t.Error("must not reuse BRANCH_MISMATCH — pos-web clears the session on that code")
	}
	if rec.Code != http.StatusServiceUnavailable {
		t.Errorf("status = %d, want 503 so the client treats it as retryable", rec.Code)
	}
}

// A missing token is still answered first: no point reporting the workstation's
// own state to a caller that presented no credential at all.
func TestUnpairedWorkstation_StillRejectsAMissingTokenAs401(t *testing.T) {
	mw, _ := newTestMiddleware(t, "http://127.0.0.1:1", "", 5*time.Minute)
	rec := httptest.NewRecorder()
	mw.Wrap(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {})).
		ServeHTTP(rec, httptest.NewRequest("GET", "/api/v1/pos/orders", nil))

	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("status = %d, want 401", rec.Code)
	}
}

// A paired workstation is untouched — the gate must cost nothing in the normal case.
func TestPairedWorkstation_StillReachesCloud(t *testing.T) {
	var cloudHits int32
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&cloudHits, 1)
		w.Write([]byte(`{"data":{"id":"dev-1","type":"pos","branch_id":"branch-A"}}`))
	}))
	defer cloud.Close()

	mw, _ := newTestMiddleware(t, cloud.URL, "branch-A", 5*time.Minute)
	req := httptest.NewRequest("GET", "/api/v1/pos/orders", nil)
	req.Header.Set("Authorization", "Bearer good")
	rec := httptest.NewRecorder()
	var got DeviceContext
	mw.Wrap(echoHandler(&got)).ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200 (body=%s)", rec.Code, rec.Body.String())
	}
	if atomic.LoadInt32(&cloudHits) != 1 {
		t.Errorf("cloud hits = %d, want 1", cloudHits)
	}
}
