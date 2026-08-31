package handler

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/gorilla/websocket"
)

// stubVerifier implements AuthVerifier with configurable response.
type stubVerifier struct {
	identity *service.Identity
	stale    bool
	err      error
}

type blockingWSVerifier struct{}

func (blockingWSVerifier) Verify(ctx context.Context, _ string) (*service.Identity, bool, error) {
	<-ctx.Done()
	return nil, false, ctx.Err()
}

func (s *stubVerifier) Verify(_ context.Context, _ string) (*service.Identity, bool, error) {
	if s.err != nil {
		return nil, false, s.err
	}
	return s.identity, s.stale, nil
}

func TestWS_FirstMessageAuth_Success(t *testing.T) {
	hub := NewHub()
	go hub.Run()
	defer hub.Stop()

	verifier := &stubVerifier{
		identity: &service.Identity{
			Type:       "device",
			DeviceID:   "kds-dev-1",
			DeviceType: "kds",
			BranchID:   "branch-1",
		},
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hub.ServeWS(w, r, verifier)
	}))
	defer server.Close()

	wsURL := strings.Replace(server.URL, "http", "ws", 1)
	ws, _, err := websocket.DefaultDialer.Dial(wsURL, nil)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer ws.Close()

	// Send auth message.
	_ = ws.WriteJSON(map[string]any{
		"type":    "auth",
		"payload": map[string]string{"token": "good-token"},
	})

	// Expect auth_ok.
	var reply Message
	ws.SetReadDeadline(time.Now().Add(2 * time.Second))
	if err := ws.ReadJSON(&reply); err != nil {
		t.Fatalf("read auth_ok: %v", err)
	}
	if reply.Type != "auth_ok" {
		t.Fatalf("expected auth_ok, got %s", reply.Type)
	}
}

func TestWS_StaleCacheSurvivesCloud503(t *testing.T) {
	mw, cache := newStatusAuthMWForTest(t, http.StatusServiceUnavailable, time.Millisecond)
	seedStaleAuthIdentity(t, cache, "stale-ws")

	hub := NewHub()
	go hub.Run()
	defer hub.Stop()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hub.ServeWS(w, r, &authMiddlewareVerifier{mw: mw})
	}))
	defer server.Close()

	wsURL := strings.Replace(server.URL, "http", "ws", 1)
	ws, _, err := websocket.DefaultDialer.Dial(wsURL, nil)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer ws.Close()
	if err := ws.WriteJSON(map[string]any{
		"type":    "auth",
		"payload": map[string]string{"token": "stale-ws"},
	}); err != nil {
		t.Fatalf("write auth: %v", err)
	}

	var reply Message
	ws.SetReadDeadline(time.Now().Add(2 * time.Second))
	if err := ws.ReadJSON(&reply); err != nil {
		t.Fatalf("Cloud 503 must not cut a cached LAN WebSocket: %v", err)
	}
	if reply.Type != "auth_ok" {
		t.Fatalf("reply = %q, want auth_ok", reply.Type)
	}
	if got := mw.Metrics().StaleFallbacks; got != 1 {
		t.Fatalf("stale_fallbacks = %d, want 1", got)
	}
}

// A paired `pos` device (pos-web's own token) must be ADMITTED to /ws so the POS
// terminal gets realtime order updates — parity with the /pos/* REST gate fix.
// Regression: policyWS used to reject pos, silently degrading pos-web to polling.
func TestWS_PosDevice_Allowed(t *testing.T) {
	hub := NewHub()
	go hub.Run()
	defer hub.Stop()

	verifier := &stubVerifier{
		identity: &service.Identity{
			Type:       "device",
			DeviceID:   "pos-dev-1",
			DeviceType: "pos",
			BranchID:   "branch-1",
		},
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hub.ServeWS(w, r, verifier)
	}))
	defer server.Close()

	wsURL := strings.Replace(server.URL, "http", "ws", 1)
	ws, _, err := websocket.DefaultDialer.Dial(wsURL, nil)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer ws.Close()

	_ = ws.WriteJSON(map[string]any{
		"type":    "auth",
		"payload": map[string]string{"token": "pos-token"},
	})

	var reply Message
	ws.SetReadDeadline(time.Now().Add(2 * time.Second))
	if err := ws.ReadJSON(&reply); err != nil {
		t.Fatalf("pos device must get auth_ok on /ws: %v", err)
	}
	if reply.Type != "auth_ok" {
		t.Fatalf("expected auth_ok for pos device, got %s", reply.Type)
	}
}

func TestWS_FirstMessageAuth_InvalidToken(t *testing.T) {
	hub := NewHub()
	go hub.Run()
	defer hub.Stop()

	verifier := &stubVerifier{err: service.ErrUnauthorized}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hub.ServeWS(w, r, verifier)
	}))
	defer server.Close()

	wsURL := strings.Replace(server.URL, "http", "ws", 1)
	ws, _, err := websocket.DefaultDialer.Dial(wsURL, nil)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer ws.Close()

	_ = ws.WriteJSON(map[string]any{
		"type":    "auth",
		"payload": map[string]string{"token": "bad-token"},
	})

	// Expect close 4401.
	ws.SetReadDeadline(time.Now().Add(2 * time.Second))
	_, _, err = ws.ReadMessage()
	if err == nil {
		t.Fatalf("expected close error")
	}
	ce, ok := err.(*websocket.CloseError)
	if !ok {
		// Some errors come as net.Error wrapping CloseError — try parsing.
		if strings.Contains(err.Error(), "4401") {
			return // ok
		}
		t.Fatalf("expected CloseError 4401, got %T %v", err, err)
	}
	if ce.Code != 4401 {
		t.Fatalf("expected close 4401, got %d", ce.Code)
	}
}

func TestWS_FirstMessageAuth_VerificationTimeoutCloses4408(t *testing.T) {
	oldTimeout := wsVerifyTimeout
	wsVerifyTimeout = 50 * time.Millisecond
	t.Cleanup(func() { wsVerifyTimeout = oldTimeout })

	hub := NewHub()
	go hub.Run()
	defer hub.Stop()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hub.ServeWS(w, r, blockingWSVerifier{})
	}))
	defer server.Close()

	wsURL := strings.Replace(server.URL, "http", "ws", 1)
	ws, _, err := websocket.DefaultDialer.Dial(wsURL, nil)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer ws.Close()

	_ = ws.WriteJSON(map[string]any{
		"type":    "auth",
		"payload": map[string]string{"token": "slow-token"},
	})
	ws.SetReadDeadline(time.Now().Add(time.Second))
	_, _, err = ws.ReadMessage()
	if err == nil {
		t.Fatal("expected auth timeout close")
	}
	if closeErr, ok := err.(*websocket.CloseError); ok {
		if closeErr.Code != 4408 {
			t.Fatalf("close code = %d, want 4408", closeErr.Code)
		}
		return
	}
	if !strings.Contains(err.Error(), "4408") {
		t.Fatalf("expected close 4408, got %T %v", err, err)
	}
}

// A Cloud-valid, same-branch device of a type NOT permitted on /ws must be refused
// at the door with 4403: realtime is a shared surface for {user, pos, kds, handy,
// workstation}, never tms/kiosk (policyWS). Uses a tms device — pos was moved to the
// allowed set for pos-web realtime.
func TestWS_DeviceTypeNotAllowed_Rejected4403(t *testing.T) {
	hub := NewHub()
	go hub.Run()
	defer hub.Stop()

	verifier := &stubVerifier{
		identity: &service.Identity{
			Type:       "device",
			DeviceID:   "tms-dev-1",
			DeviceType: "tms",
			BranchID:   "branch-1",
		},
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hub.ServeWS(w, r, verifier)
	}))
	defer server.Close()

	wsURL := strings.Replace(server.URL, "http", "ws", 1)
	ws, _, err := websocket.DefaultDialer.Dial(wsURL, nil)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer ws.Close()

	_ = ws.WriteJSON(map[string]any{
		"type":    "auth",
		"payload": map[string]string{"token": "tms-token"},
	})

	ws.SetReadDeadline(time.Now().Add(2 * time.Second))
	_, _, err = ws.ReadMessage()
	if err == nil {
		t.Fatalf("expected close error")
	}
	ce, ok := err.(*websocket.CloseError)
	if !ok {
		if strings.Contains(err.Error(), "4403") {
			return // ok
		}
		t.Fatalf("expected CloseError 4403, got %T %v", err, err)
	}
	if ce.Code != 4403 {
		t.Fatalf("expected close 4403, got %d", ce.Code)
	}
}

// waitForClients polls the hub until it has `n` registered clients or the
// deadline elapses. Registration happens on a channel after auth_ok is sent,
// so callers that broadcast right after reading auth_ok must sync on this.
func waitForClients(t *testing.T, hub *Hub, n int) {
	t.Helper()
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		if hub.ClientCount() == n {
			return
		}
		time.Sleep(5 * time.Millisecond)
	}
	t.Fatalf("timeout waiting for %d ws client(s), have %d", n, hub.ClientCount())
}

// authAndWait dials the server, completes the first-message auth handshake,
// asserts auth_ok, and returns the live connection.
func authAndWait(t *testing.T, serverURL string) *websocket.Conn {
	t.Helper()
	wsURL := strings.Replace(serverURL, "http", "ws", 1)
	ws, _, err := websocket.DefaultDialer.Dial(wsURL, nil)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	_ = ws.WriteJSON(map[string]any{
		"type":    "auth",
		"payload": map[string]string{"token": "good-token"},
	})
	var reply Message
	ws.SetReadDeadline(time.Now().Add(2 * time.Second))
	if err := ws.ReadJSON(&reply); err != nil {
		t.Fatalf("read auth_ok: %v", err)
	}
	if reply.Type != "auth_ok" {
		t.Fatalf("expected auth_ok, got %s", reply.Type)
	}
	return ws
}

// An SSO-user token (pos-web cashier) resolves to an empty BranchID. With a
// branch fallback wired, the handshake binds the client to the workstation's
// own branch, so it receives branch-scoped broadcasts (the KDS→POS realtime
// item-status path). This is the regression guard for that fix.
func TestWS_SSOUser_BoundToFallbackBranch_ReceivesScopedEvent(t *testing.T) {
	hub := NewHub()
	hub.SetBranchFallback(func() string { return "branch-1" })
	go hub.Run()
	defer hub.Stop()

	verifier := &stubVerifier{
		identity: &service.Identity{
			Type:     "user", // SSO cashier — branch deferred to Cloud
			UserID:   "user-1",
			BranchID: "", // empty: the condition the fallback fixes
		},
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hub.ServeWS(w, r, verifier)
	}))
	defer server.Close()

	ws := authAndWait(t, server.URL)
	defer ws.Close()
	waitForClients(t, hub, 1)

	// KDS bumps an item → workstation fans out scoped to its branch.
	hub.BroadcastEventScoped("order_item.status_changed", map[string]any{
		"order_id": "ord-1", "item_id": "item-1", "status": "ready",
	}, "branch-1")

	var msg Message
	ws.SetReadDeadline(time.Now().Add(2 * time.Second))
	if err := ws.ReadJSON(&msg); err != nil {
		t.Fatalf("expected scoped event, got read error: %v", err)
	}
	if msg.Type != "order_item.status_changed" {
		t.Fatalf("expected order_item.status_changed, got %s", msg.Type)
	}
}

// A paired DEVICE (godx-handy / godx-kds) authenticates with its own branch_id
// (branchOK fail-close guarantees it equals the workstation branch). It must
// receive branch-scoped broadcasts like order_item.status_changed — this is the
// KDS→handy realtime item-status path. Regression guard for that scenario.
func TestWS_Device_MatchingBranch_ReceivesScopedEvent(t *testing.T) {
	hub := NewHub()
	// Device branch already matches; fallback is irrelevant but wired as in prod.
	hub.SetBranchFallback(func() string { return "branch-1" })
	go hub.Run()
	defer hub.Stop()

	verifier := &stubVerifier{
		identity: &service.Identity{
			Type:       "device",
			DeviceID:   "handy-dev-1",
			DeviceType: "handy",
			BranchID:   "branch-1", // == workstation branch (branchOK enforced upstream)
		},
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hub.ServeWS(w, r, verifier)
	}))
	defer server.Close()

	ws := authAndWait(t, server.URL)
	defer ws.Close()
	waitForClients(t, hub, 1)

	hub.BroadcastEventScoped("order_item.status_changed", map[string]any{
		"order_id": "ord-1", "item_id": "item-1", "status": "ready",
	}, "branch-1")

	var msg Message
	ws.SetReadDeadline(time.Now().Add(2 * time.Second))
	if err := ws.ReadJSON(&msg); err != nil {
		t.Fatalf("expected scoped event for matching-branch device, got: %v", err)
	}
	if msg.Type != "order_item.status_changed" {
		t.Fatalf("expected order_item.status_changed, got %s", msg.Type)
	}
}

// Without a fallback, an SSO user keeps an empty branchID and the scope filter
// drops every branch-scoped event — the original bug. Documents why the fix
// is needed and that the fallback is the load-bearing piece.
func TestWS_SSOUser_NoFallback_MissesScopedEvent(t *testing.T) {
	hub := NewHub() // no SetBranchFallback
	go hub.Run()
	defer hub.Stop()

	verifier := &stubVerifier{
		identity: &service.Identity{Type: "user", UserID: "user-1", BranchID: ""},
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hub.ServeWS(w, r, verifier)
	}))
	defer server.Close()

	ws := authAndWait(t, server.URL)
	defer ws.Close()
	waitForClients(t, hub, 1)

	hub.BroadcastEventScoped("order_item.status_changed", map[string]any{
		"order_id": "ord-1", "item_id": "item-1", "status": "ready",
	}, "branch-1")

	// Expect NO scoped event to arrive (empty branchID is filtered out).
	var msg Message
	ws.SetReadDeadline(time.Now().Add(500 * time.Millisecond))
	if err := ws.ReadJSON(&msg); err == nil {
		t.Fatalf("expected no scoped event for empty-branch client, got %s", msg.Type)
	}
}

func TestWS_FirstMessageAuth_Timeout(t *testing.T) {
	hub := NewHub()
	go hub.Run()
	defer hub.Stop()

	// Override the auth timeout for the test.
	oldTimeout := authTimeout
	authTimeout = 200 * time.Millisecond
	defer func() { authTimeout = oldTimeout }()

	verifier := &stubVerifier{}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hub.ServeWS(w, r, verifier)
	}))
	defer server.Close()

	wsURL := strings.Replace(server.URL, "http", "ws", 1)
	ws, _, err := websocket.DefaultDialer.Dial(wsURL, nil)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer ws.Close()

	// Don't send any message — wait for timeout.
	ws.SetReadDeadline(time.Now().Add(1 * time.Second))
	_, _, err = ws.ReadMessage()
	if err == nil {
		t.Fatalf("expected timeout close")
	}
	// 4408 close code or net error.
	if ce, ok := err.(*websocket.CloseError); ok && ce.Code != 4408 {
		t.Fatalf("expected close 4408, got %d", ce.Code)
	}
}
