package handler

import (
	"context"
	"encoding/json"
	"log/slog"
	"net"
	"net/http"
	"sync"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/gorilla/websocket"
)

// AuthVerifier validates a bearer token and returns the resolved Identity
// plus a `stale` flag (true when the caller is in offline-tolerant
// degraded mode — Cloud unreachable, served from stale cache).
// Implemented in production by AuthMiddleware.VerifyToken so the WS
// handshake shares the cache + stale-fallback ladder with HTTP auth —
// previously the WS path called service.CloudVerifier.Verify directly,
// which meant a Cloud outage cut every LAN realtime channel even though
// the HTTP side stayed up via stale cache.
type AuthVerifier interface {
	Verify(ctx context.Context, token string) (id *service.Identity, stale bool, err error)
}

// authMiddlewareVerifier adapts AuthMiddleware.VerifyToken to the
// AuthVerifier interface. Production wiring (server.go) sets
// `s.authVerifier = &authMiddlewareVerifier{mw: s.authMW}` so the WS
// handshake reuses cache + stale tolerance instead of bypassing them.
type authMiddlewareVerifier struct {
	mw *AuthMiddleware
}

func (v *authMiddlewareVerifier) Verify(ctx context.Context, token string) (*service.Identity, bool, error) {
	res, err := v.mw.VerifyToken(ctx, token)
	if err != nil {
		return nil, false, err
	}
	return res.Identity, res.Stale, nil
}

// authTimeout is the window within which a new WS client must send its auth
// message. Declared as a var so tests can override it.
var authTimeout = 5 * time.Second

// wsVerifyTimeout also bounds custom/test AuthVerifier implementations. The
// production AuthMiddleware has a tighter one-second Cloud budget, but the WS
// protocol must never wait forever for auth_ok if a verifier regresses.
var wsVerifyTimeout = 1500 * time.Millisecond

// wsCloseDesync is sent when a client's send buffer overflows: it has missed
// at least one event, so the connection is closed to force a reconnect and a
// refetch (#1793). Application close codes live in 4000–4999. Clients treat it
// as any other close and reconnect with backoff — only 4401 (revoked token) is
// terminal on the KDS side.
const wsCloseDesync = 4409

var upgrader = websocket.Upgrader{
	ReadBufferSize:  1024,
	WriteBufferSize: 1024,
	CheckOrigin:     checkWSOrigin,
}

// checkWSOrigin gates the WebSocket handshake. Before #86 it returned true
// unconditionally, so any web page a LAN user visited could open a socket to
// the workstation (the first-message bearer auth still applied, but the
// upgrade itself — and DNS-rebinding exposure — did not).
//
//   - No Origin header → native app (kiosk RN / tms), not a browser. Allowed;
//     the first-message bearer auth + lanOnly ring still gate it.
//   - Origin present → browser (pos-web / kds). Must be on the LAN/loopback or
//     *.godx.jp allow-list (same policy as the HTTP CORS layer).
//   - The Host the browser connected to must resolve to a loopback/LAN address,
//     not an attacker-controlled domain pointed at the workstation's LAN IP
//     (anti-DNS-rebinding).
func checkWSOrigin(r *http.Request) bool {
	origin := r.Header.Get("Origin")
	if origin != "" {
		if !originAllowed(origin) && !isAllowedOrigin(origin) {
			slog.Warn("ws upgrade rejected: origin not allowed", "origin", origin)
			return false
		}
	}
	if !wsHostIsLANOrLoopback(r.Host) {
		slog.Warn("ws upgrade rejected: host not LAN/loopback (dns-rebinding?)", "host", r.Host)
		return false
	}
	return true
}

// wsHostIsLANOrLoopback validates the Host header: an IP literal must be
// private/loopback, and the only accepted hostname form is "localhost". This
// blocks a rebinding attack where `evil.example` resolves to the workstation's
// LAN IP — the browser would send Host: evil.example, which is refused.
func wsHostIsLANOrLoopback(hostport string) bool {
	host := hostport
	if h, _, err := net.SplitHostPort(hostport); err == nil {
		host = h
	}
	if host == "" {
		return false
	}
	if ip := net.ParseIP(host); ip != nil {
		return isPrivateIP(ip)
	}
	if host == "localhost" {
		return true
	}
	// A reverse proxy / tunnel in front of the LAN gateway makes the browser
	// send that proxy's hostname as Host. Opt-in only (WS_APP_TUNNEL_HOSTS);
	// unset keeps this check exactly as strict as before. See tunnel_hosts.go.
	return isTunnelHost(host)
}

// Message is the envelope for WebSocket messages.
type Message struct {
	Type      string `json:"type"`
	Payload   any    `json:"payload"`
	Timestamp string `json:"timestamp"`
}

// Client represents a connected WebSocket client.
type Client struct {
	hub      *Hub
	conn     *websocket.Conn
	send     chan []byte
	authedBy string // device_id, empty if pre-auth (still in handshake window); set by Task 2.4
	branchID string // bound after first-message auth (used for scoped fan-out); set by Task 2.4
	dropOnce sync.Once
}

// dropForOverflow closes a client that could not keep up with the fan-out.
//
// #1793 — the previous behaviour was to drop the EVENT and keep the client,
// which left it connected, believing it was live, and permanently missing what
// it never received: dropping an event does not close the connection, so there
// is no reconnect to "refetch on" and nothing else ever resyncs it. Closing is
// the honest answer — every client reconnects with backoff (pos-web ×2/30s ·
// kds 1s×2/30s · ws-app frontend ×1.5/30s) and refetches on the way back. A
// visible one-second gap beats an invisible permanently-wrong screen.
//
// It must NOT touch hub.clients: callers hold only a read lock. readPump's
// defer does the unregister through the normal path, which takes the write
// lock. gorilla's Close/WriteControl are safe to call concurrently with the
// writePump's WriteMessage.
func (c *Client) dropForOverflow(eventType string) {
	// #1806 — client bị đóng vì không theo kịp. Gộp theo LOẠI client, không
	// theo từng kết nối: một máy KDS chập chờn reconnect 50 lần trong một phút
	// là MỘT sự cố, và 50 dòng alert sẽ chôn mất nó.
	// Subject là một hằng, không phải id kết nối: Client không mang trường phân
	// loại nào, và kể cả có thì gộp theo từng kết nối cũng sai — một máy KDS
	// chập chờn reconnect 50 lần trong một phút là MỘT sự cố, còn 50 dòng alert
	// sẽ chôn mất nó.
	if c.hub != nil && c.hub.alerts != nil {
		c.hub.alerts.Raise(service.KindRealtimeClientDropped, "ws_client",
			"Client realtime bị ngắt vì không theo kịp",
			map[string]any{"event_type": eventType})
	}
	c.dropOnce.Do(func() {
		slog.Warn("ws client send buffer full — closing so it reconnects and resyncs",
			"device", c.authedBy, "branch", c.branchID, "event", eventType)
		if c.conn == nil {
			return // unit-test client, or already torn down
		}
		_ = c.conn.WriteControl(
			websocket.CloseMessage,
			websocket.FormatCloseMessage(wsCloseDesync, "desync: send buffer full"),
			time.Now().Add(time.Second),
		)
		_ = c.conn.Close()
	})
}

// Hub manages all WebSocket clients.
type Hub struct {
	// #1806 — alert centre. Nil-safe: nhiều test dựng Hub trực tiếp.
	alerts     *service.AlertEmitter
	mu         sync.RWMutex
	clients    map[*Client]bool
	register   chan *Client
	unregister chan *Client
	stopCh     chan struct{} // closed by Stop() to exit Run() and any pumps
	stopOnce   sync.Once
	// Plan-038 T9.1 — replay buffer for late KDS reconnects. Only
	// `order.kitchen_printed` (and similar low-volume KDS-relevant events)
	// are pushed here; HFT order_updated traffic stays live-only.
	replay *kdsReplayBuffer
	// branchFallback resolves this workstation's own branch_id. It is used
	// during the auth handshake to bind LAN clients that authenticate as an
	// SSO *user* (pos-web cashier) rather than a paired device: such a token
	// resolves to an Identity with an empty BranchID (branch is deferred to
	// Cloud), so without this fallback the client would never match any
	// branch-scoped broadcast (e.g. order_item.status_changed from KDS) and
	// silently miss every scoped event. A workstation serves exactly one
	// branch, so binding empty-branch clients to it is always correct.
	// nil → no fallback (used by unit tests that don't exercise scoping).
	branchFallback func() string
}

// SetBranchFallback wires the resolver used to bind empty-branch (SSO user)
// clients to this workstation's own branch during the WS auth handshake.
func (h *Hub) SetBranchFallback(fn func() string) {
	h.mu.Lock()
	h.branchFallback = fn
	h.mu.Unlock()
}

func NewHub() *Hub {
	return &Hub{
		clients:    make(map[*Client]bool),
		register:   make(chan *Client),
		unregister: make(chan *Client),
		stopCh:     make(chan struct{}),
		replay:     newKDSReplayBuffer(),
	}
}

func (h *Hub) Run() {
	for {
		select {
		case <-h.stopCh:
			// Drain connected clients so writePump/readPump exit on their
			// next iteration. Holding the write lock ensures no new
			// client registers while we're closing.
			h.mu.Lock()
			for client := range h.clients {
				close(client.send)
				if client.conn != nil {
					_ = client.conn.Close()
				}
				delete(h.clients, client)
			}
			h.mu.Unlock()
			return

		case client := <-h.register:
			h.mu.Lock()
			h.clients[client] = true
			h.mu.Unlock()
			slog.Debug("websocket client connected", "total", len(h.clients))

		case client := <-h.unregister:
			h.mu.Lock()
			if _, ok := h.clients[client]; ok {
				delete(h.clients, client)
				close(client.send)
			}
			h.mu.Unlock()
			slog.Debug("websocket client disconnected", "total", len(h.clients))

		}
	}
}

// Stop signals Run() to exit and closes all active client connections.
// Safe to call multiple times — subsequent calls are no-ops.
func (h *Hub) Stop() {
	h.stopOnce.Do(func() {
		close(h.stopCh)
	})
}

// stopCh exposes the channel used by per-connection pumps to learn that the
// hub is shutting down, so they can exit promptly.
func (h *Hub) done() <-chan struct{} { return h.stopCh }

// BroadcastEventScoped sends a message directly to clients matching branchID.
// branchID="" → broadcast to all connected clients (unscoped, legacy behaviour).
// branchID set → only clients with a matching branchID receive the message;
// clients whose branchID is empty (not yet bound by the auth handshake) are
// skipped when a scope is given.
//
// The send into each client's channel is non-blocking. A full buffer means the
// client cannot keep up, so it is CLOSED (code 4409) rather than quietly served
// an incomplete stream — it reconnects with backoff and refetches. See
// dropForOverflow for why silently dropping the event was wrong (#1793).
func (h *Hub) BroadcastEventScoped(eventType string, payload any, branchID string) {
	msg := Message{
		Type:      eventType,
		Payload:   payload,
		Timestamp: time.Now().UTC().Format(time.RFC3339),
	}
	data, err := json.Marshal(msg)
	if err != nil {
		slog.Error("marshal scoped broadcast", "error", err)
		return
	}

	// Plan-038 T9.2 — record KDS-relevant events in the replay buffer so
	// late reconnects (?since=...) can catch up. Only a tiny set of event
	// types are recorded; everything else is live-only.
	if h.replay != nil && shouldReplay(eventType) {
		h.replay.Push(eventType, branchID, data)
	}

	// Overflowed clients are collected here and closed AFTER the lock is
	// released: dropForOverflow does network I/O with a 1s deadline, and doing
	// that under RLock would stall register/unregister (they need the write
	// lock) for as long as the slowest socket takes to accept a close frame.
	var overflowed []*Client

	h.mu.RLock()
	total := len(h.clients)
	delivered := 0
	skipped := 0
	for client := range h.clients {
		if branchID != "" && client.branchID != branchID {
			skipped++
			continue // scope filter — skip clients without matching branchID
		}
		select {
		case client.send <- data:
			delivered++
		default:
			// Buffer full: this client has now missed an event. Close it rather
			// than dropping the event silently — see dropForOverflow (#1793).
			overflowed = append(overflowed, client)
		}
	}
	h.mu.RUnlock()

	// Each drop writes a close frame with a 1s deadline, and fan-out runs
	// SYNCHRONOUSLY inside request handlers (lan_print.go broadcasts
	// order.kitchen_printed while the cashier waits on the print response).
	// Closing inline would add up to 1s PER stuck client to that request, so
	// several congested tablets could stall a print for seconds. dropOnce makes
	// the call safe to fire and forget.
	for _, client := range overflowed {
		go client.dropForOverflow(eventType)
	}

	// Diagnostic: a "realtime not arriving" report can be triaged from the
	// workstation console — delivered=0 with clients>0 means the connected
	// clients' branchID didn't match the broadcast scope, and dropped>0 means
	// a client could not keep up and was closed to force a resync.
	slog.Info("ws fanout",
		"event", eventType,
		"scope", branchID,
		"clients", total,
		"delivered", delivered,
		"skipped_branch", skipped,
		"dropped_slow", len(overflowed),
	)
}

// shouldReplay returns true for event types we want to backfill on KDS
// reconnect. Keeping the list small bounds the buffer's memory footprint
// even when high-frequency events (order_updated on every cart change)
// are flying around.
func shouldReplay(eventType string) bool {
	switch eventType {
	case "order.kitchen_printed":
		return true
	case "order_item.status_changed":
		return true
	case "print_status":
		// A kiosk that briefly drops its WS during the confirm round-trip would
		// otherwise miss the auto-print result and never show its reprint banner.
		return true
	default:
		return false
	}
}

// BroadcastEvent broadcasts to ALL connected clients (no branch scope).
// Kept for backward compat — new callers that know the target branch should
// use BroadcastEventScoped.
//
// Note: this no longer routes through the hub's broadcast channel; it sends
// directly to each client's send buffer (same as BroadcastEventScoped) so
// that scoped and unscoped paths share one code path.
func (h *Hub) BroadcastEvent(eventType string, payload any) {
	h.BroadcastEventScoped(eventType, payload, "")
}

func (h *Hub) ClientCount() int {
	h.mu.RLock()
	defer h.mu.RUnlock()
	return len(h.clients)
}

// ServeWS handles WebSocket upgrade requests with first-message auth.
//
// Browsers cannot set custom Authorization headers on WS upgrade requests, so
// authentication is deferred to the first message after the connection opens.
// The client must send {"type":"auth","payload":{"token":"<bearer>"}} within
// authTimeout (default 5s). The server verifies via verifier, binds
// client.authedBy + client.branchID, and replies {"type":"auth_ok"}.
//
// Close codes:
//   - 4401: bad/missing token, or first message was not auth-typed.
//   - 4403: token valid but its identity type may not use /ws (policyWS).
//   - 4408: client did not send auth message within authTimeout.
func (h *Hub) ServeWS(w http.ResponseWriter, r *http.Request, verifier AuthVerifier) {
	conn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		slog.Error("websocket upgrade", "error", err)
		return
	}

	client := &Client{
		hub:  h,
		conn: conn,
		send: make(chan []byte, 256),
	}

	// First-message auth window.
	conn.SetReadDeadline(time.Now().Add(authTimeout))
	_, raw, err := conn.ReadMessage()
	if err != nil {
		// Timeout or connection drop before auth.
		slog.Warn("ws auth: no first message before deadline", "err", err.Error())
		_ = conn.WriteControl(
			websocket.CloseMessage,
			websocket.FormatCloseMessage(4408, "auth timeout"),
			time.Now().Add(time.Second),
		)
		conn.Close()
		return
	}

	var firstMsg Message
	if jsonErr := json.Unmarshal(raw, &firstMsg); jsonErr != nil || firstMsg.Type != "auth" {
		slog.Warn("ws auth: first message was not an auth frame", "type", firstMsg.Type)
		_ = conn.WriteControl(
			websocket.CloseMessage,
			websocket.FormatCloseMessage(4401, "first message must be auth"),
			time.Now().Add(time.Second),
		)
		conn.Close()
		return
	}

	payload, _ := firstMsg.Payload.(map[string]any)
	token, _ := payload["token"].(string)
	if token == "" {
		_ = conn.WriteControl(
			websocket.CloseMessage,
			websocket.FormatCloseMessage(4401, "missing token"),
			time.Now().Add(time.Second),
		)
		conn.Close()
		return
	}

	verifyCtx, cancelVerify := context.WithTimeout(r.Context(), wsVerifyTimeout)
	identity, stale, err := verifier.Verify(verifyCtx, token)
	verifyTimedOut := verifyCtx.Err() == context.DeadlineExceeded
	cancelVerify()
	if err != nil {
		// Diagnostic: the exact reason a client is refused at the WS door —
		// e.g. "device branch mismatch" (paired to a different branch),
		// "invalid token" (unpaired/revoked), or a Cloud-unreachable error.
		// A consumer that never receives realtime usually shows up here.
		closeCode := 4401
		closeReason := "invalid token"
		if verifyTimedOut {
			closeCode = 4408
			closeReason = "auth verification timeout"
		}
		slog.Warn("ws auth rejected", "reason", err.Error(), "timeout", verifyTimedOut)
		_ = conn.WriteControl(
			websocket.CloseMessage,
			websocket.FormatCloseMessage(closeCode, closeReason),
			time.Now().Add(time.Second),
		)
		conn.Close()
		return
	}

	// Device-type guard: enforce identity→surface for the realtime channel. /ws
	// authenticates inside ServeWS (not via the HTTP requireType ring), so the
	// type gate lives here and shares the same policy predicate. Allowed: SSO
	// users (pos-web) + kds/handy/workstation devices. A POS/tms/kiosk device
	// token is Cloud-valid + same-branch but has no business on this
	// workstation's realtime feed. Applies to stale + fresh identities alike.
	if !policyWS.permits(identity.Type, identity.DeviceType) {
		slog.Warn("ws auth: identity type not allowed",
			"identity_type", identity.Type, "device_type", identity.DeviceType)
		_ = conn.WriteControl(
			websocket.CloseMessage,
			websocket.FormatCloseMessage(4403, "device type not allowed"),
			time.Now().Add(time.Second),
		)
		conn.Close()
		return
	}

	// Bind authenticated identity.
	client.authedBy = identity.DeviceID
	client.branchID = identity.BranchID

	// SSO users (pos-web cashier) resolve to an empty BranchID — branch is
	// deferred to Cloud. Bind them to this workstation's own branch so they
	// receive branch-scoped broadcasts (order_item.status_changed, etc.);
	// otherwise the scope filter in BroadcastEventScoped drops every scoped
	// event for them. A paired device always carries its own (validated)
	// branch, so this only ever fills in the missing SSO-user case.
	if client.branchID == "" {
		h.mu.RLock()
		fallback := h.branchFallback
		h.mu.RUnlock()
		if fallback != nil {
			client.branchID = fallback()
		}
	}

	// Diagnostic: surface exactly which client authed and what branch it was
	// bound to, so a "realtime not arriving" report can be triaged from the
	// workstation console (does the consumer connect? which branch?).
	slog.Info("ws client authed",
		"device", identity.DeviceID,
		"type", identity.DeviceType,
		"branch", client.branchID,
		"stale", stale,
	)

	// Reply auth_ok BEFORE starting pumps to avoid race.
	// stale=true signals degraded mode (Cloud unreachable, accepted via
	// stale cache). Clients should display a banner; HTTP responses use
	// X-Auth-Stale: true for the same purpose.
	okPayload := map[string]any{"device_id": identity.DeviceID}
	if stale {
		okPayload["stale"] = true
	}
	okMsg := Message{
		Type:      "auth_ok",
		Payload:   okPayload,
		Timestamp: time.Now().UTC().Format(time.RFC3339),
	}
	okBytes, _ := json.Marshal(okMsg)
	if err := conn.WriteMessage(websocket.TextMessage, okBytes); err != nil {
		conn.Close()
		return
	}

	// Reset read deadline for normal operation.
	conn.SetReadDeadline(time.Now().Add(60 * time.Second))

	// #1806 S2 — RESYNC alert đang mở, ngay sau auth_ok.
	//
	// Đây là vế bắt buộc, không phải tối ưu. Alert được đẩy qua WS như sự kiện,
	// nên một client kết nối SAU khi sự cố xảy ra sẽ không bao giờ nghe thấy nó
	// — máy POS mở lúc 9h sáng sẽ không biết máy in bếp đã chết từ 8h. Sự kiện
	// nói "vừa có chuyện"; resync nói "đang có chuyện", và người đứng quầy cần
	// vế thứ hai.
	//
	// Gửi TRƯỚC khi pump chạy để client thấy trạng thái nền trước sự kiện trực
	// tiếp — cùng thứ tự mà replay KDS bên dưới dùng.
	if h.alerts != nil {
		if open, err := h.alerts.ListOpen(); err == nil && len(open) > 0 {
			snapshot := Message{
				Type:      "alert.snapshot",
				Payload:   map[string]any{"alerts": open},
				Timestamp: time.Now().UTC().Format(time.RFC3339),
			}
			if data, err := json.Marshal(snapshot); err == nil {
				if err := conn.WriteMessage(websocket.TextMessage, data); err != nil {
					conn.Close()
					return
				}
			}
		}
	}

	// Plan-038 T9.3 — replay any KDS-relevant events the client missed
	// during reconnect. `?since=<RFC3339>` query selects the cutoff;
	// malformed values are silently treated as no replay (the client
	// will refetch via its periodic poll). Events flow chronologically
	// BEFORE the pumps go live so the client sees them ordered.
	if since := parseSinceQuery(r); !since.IsZero() && h.replay != nil {
		for _, data := range h.replay.Since(since, client.branchID) {
			if err := conn.WriteMessage(websocket.TextMessage, data); err != nil {
				conn.Close()
				return
			}
		}
	}

	// Register the authenticated client and start pumps.
	h.register <- client
	go client.writePump()
	go client.readPump()
}

// parseSinceQuery extracts the `?since=<RFC3339>` cutoff from the WS
// upgrade URL. Malformed values return zero so the caller skips replay.
func parseSinceQuery(r *http.Request) time.Time {
	raw := r.URL.Query().Get("since")
	if raw == "" {
		return time.Time{}
	}
	t, err := time.Parse(time.RFC3339, raw)
	if err != nil {
		slog.Warn("ws: malformed since param, skipping replay", "value", raw, "err", err)
		return time.Time{}
	}
	return t
}

func (c *Client) readPump() {
	defer func() {
		// Only signal unregister if the hub is still running — otherwise the
		// channel send would block forever after Stop() drained the loop.
		select {
		case <-c.hub.done():
		case c.hub.unregister <- c:
		}
		c.conn.Close()
	}()

	c.conn.SetReadLimit(4096)
	c.conn.SetReadDeadline(time.Now().Add(60 * time.Second))
	c.conn.SetPongHandler(func(string) error {
		c.conn.SetReadDeadline(time.Now().Add(60 * time.Second))
		return nil
	})

	for {
		_, message, err := c.conn.ReadMessage()
		if err != nil {
			break
		}

		// Handle client messages (ping, subscribe, etc.)
		var msg Message
		if json.Unmarshal(message, &msg) == nil {
			switch msg.Type {
			case "ping":
				reply, _ := json.Marshal(Message{
					Type:      "pong",
					Timestamp: time.Now().UTC().Format(time.RFC3339),
				})
				select {
				case <-c.hub.done():
					return
				case c.send <- reply:
				}
			}
		}
	}
}

func (c *Client) writePump() {
	ticker := time.NewTicker(30 * time.Second)
	defer func() {
		ticker.Stop()
		c.conn.Close()
	}()

	for {
		select {
		case message, ok := <-c.send:
			c.conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			if !ok {
				c.conn.WriteMessage(websocket.CloseMessage, []byte{})
				return
			}
			if err := c.conn.WriteMessage(websocket.TextMessage, message); err != nil {
				return
			}
		case <-ticker.C:
			c.conn.SetWriteDeadline(time.Now().Add(10 * time.Second))
			if err := c.conn.WriteMessage(websocket.PingMessage, nil); err != nil {
				return
			}
		}
	}
}
