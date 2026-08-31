package service

// #1175 Part 2 — poke client ("push is an EMPTY hint, pull is the truth").
//
// A minimal Pusher-protocol websocket client (works against Reverb, Pusher,
// or Ably's Pusher adapter — provider swap is Cloud-side config) that
// subscribes the per-branch private channel
//
//	private-workstation.sync.{branchId}
//
// and, on every empty "sync.poke" event, kicks the manifest loop so the SAME
// manifest check runs early. The poke carries no data → there is no payload
// contract to break.
//
// ── THE NON-NEGOTIABLE INVARIANT (chaos-pinned) ──────────────────────────
// Losing the websocket — or the provider dying entirely — MUST NOT affect or
// block anything. The poke client:
//   - runs in its own goroutine with recover();
//   - on ANY failure (dial, auth 4xx, protocol garbage, close, panic) logs at
//     most once per state change and reconnects with exp backoff + jitter
//     (cap 60 s);
//   - shares NO state with the pull loop except the buffered-1 kick channel;
//   - is silently OFF when config is missing/invalid.
//
// The periodic 5 s tick remains the only load-bearing sync mechanism; a poke
// only makes it arrive early.
//
// ── Config (integration point with the backend half) ─────────────────────
// Read from the shop_settings mirror, which PullBranch fills by flattening
// Cloud's branch `settings` map — so Cloud must serialize these keys on the
// workstation branch payload (`data.settings.*`):
//
//	broadcast_app_key  (required)  Pusher/Reverb app key
//	broadcast_host     (required)  websocket host, e.g. "reverb.godx.jp"
//	broadcast_port     (optional)  omit → scheme default (443/80)
//	broadcast_scheme   (optional)  "https"/"wss" (default) or "http"/"ws"
//
// Missing/invalid config = poke disabled (rechecked every 30 s, since the
// config itself syncs DOWN). Channel auth uses the EXISTING device
// broadcasting endpoint POST /api/v1/devices/broadcasting/auth with the
// workstation's device token.

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"math/rand/v2"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

const (
	pokeSettingAppKey = "broadcast_app_key"
	pokeSettingHost   = "broadcast_host"
	pokeSettingPort   = "broadcast_port"
	pokeSettingScheme = "broadcast_scheme"

	pokeAuthPath = "/api/v1/devices/broadcasting/auth"

	pokeChannelPrefix = "private-workstation.sync."
	pokeEventName     = "sync.poke"

	pokeHandshakeTimeout       = 10 * time.Second
	pokeWriteTimeout           = 10 * time.Second
	pokeDefaultActivityTimeout = 30 * time.Second
	pokeMinActivityTimeout     = 5 * time.Second
	pokeBackoffMin             = 1 * time.Second
	pokeBackoffMax             = 60 * time.Second
	pokeConfigRecheck          = 30 * time.Second
)

type pokeConfig struct {
	wsURL   string
	channel string
}

// shopSetting reads one value from the shop_settings mirror ("" when absent).
func (p *SyncPuller) shopSetting(key string) string {
	var v string
	_ = p.db.QueryRow(`SELECT value FROM shop_settings WHERE key = ?`, key).Scan(&v)
	return v
}

// loadPokeConfig assembles the poke websocket config from synced settings.
// ok=false (poke silently disabled) whenever any required piece is missing or
// the scheme is unrecognized.
func (p *SyncPuller) loadPokeConfig() (pokeConfig, bool) {
	appKey := strings.TrimSpace(p.shopSetting(pokeSettingAppKey))
	host := strings.TrimSpace(p.shopSetting(pokeSettingHost))
	branchID := strings.TrimSpace(p.getCursor("workstation_branch_id"))
	if appKey == "" || host == "" || branchID == "" {
		return pokeConfig{}, false
	}

	scheme := ""
	switch strings.ToLower(strings.TrimSpace(p.shopSetting(pokeSettingScheme))) {
	case "", "https", "wss":
		scheme = "wss"
	case "http", "ws":
		scheme = "ws"
	default:
		return pokeConfig{}, false
	}

	if port := strings.TrimSpace(p.shopSetting(pokeSettingPort)); port != "" {
		host += ":" + port
	}

	q := url.Values{}
	q.Set("protocol", "7")
	q.Set("client", "workstation-app")
	q.Set("version", "1.0")
	q.Set("flash", "false")
	wsURL := fmt.Sprintf("%s://%s/app/%s?%s", scheme, host, url.PathEscape(appKey), q.Encode())

	return pokeConfig{wsURL: wsURL, channel: pokeChannelPrefix + branchID}, true
}

// pusherFrame is the generic Pusher-protocol message envelope.
type pusherFrame struct {
	Event   string          `json:"event"`
	Channel string          `json:"channel,omitempty"`
	Data    json.RawMessage `json:"data,omitempty"`
}

// dataString returns the frame's data payload. The Pusher protocol usually
// double-encodes it (a JSON string containing JSON); decode that layer when
// present, otherwise return the raw bytes.
func (f pusherFrame) dataString() string {
	if len(f.Data) == 0 {
		return ""
	}
	var s string
	if err := json.Unmarshal(f.Data, &s); err == nil {
		return s
	}
	return string(f.Data)
}

// runPokeClient is the poke listener goroutine, started by SyncPuller.Start.
// See the file header for the failure invariant it upholds.
func (p *SyncPuller) runPokeClient() {
	defer func() {
		if r := recover(); r != nil {
			// Last-resort belt: pokeSession has its own recover, so reaching
			// this means the reconnect loop itself broke. Poke stays off until
			// restart; periodic pull is untouched.
			slog.Error("poke client exited on panic — periodic pull unaffected", "panic", r)
		}
	}()

	backoff := pokeBackoffMin
	failureLogged := false
	for {
		select {
		case <-p.stopCh:
			return
		default:
		}

		cfg, ok := p.loadPokeConfig()
		if !ok {
			// Missing/invalid config = poke silently off. The config syncs
			// DOWN via PullBranch, so recheck on a slow timer.
			if !p.pokeSleep(pokeConfigRecheck) {
				return
			}
			continue
		}

		subscribed, err := p.pokeSession(cfg)
		select {
		case <-p.stopCh:
			return // shutdown closed the conn — don't log that as a failure
		default:
		}
		if subscribed {
			// The session reached the subscribed state (logged there as the
			// connected state change) — reset backoff, re-arm the one-shot
			// disconnect log.
			backoff = pokeBackoffMin
			failureLogged = false
		}
		if err != nil && !failureLogged {
			slog.Warn("sync poke disconnected — pull ticks unaffected, reconnecting with backoff", "err", err)
			failureLogged = true
		}

		sleep := backoff + rand.N(backoff/2+1) // jitter: [backoff, 1.5×backoff]
		backoff = min(backoff*2, pokeBackoffMax)
		if !p.pokeSleep(sleep) {
			return
		}
	}
}

// pokeSession dials, handshakes, subscribes and listens until the connection
// dies. Returns subscribed=true once the private channel subscription
// succeeded (used to reset backoff). Panics inside the session are converted
// to an error so protocol garbage can never take down the app.
func (p *SyncPuller) pokeSession(cfg pokeConfig) (subscribed bool, err error) {
	defer func() {
		if r := recover(); r != nil {
			err = fmt.Errorf("poke session panic: %v", r)
		}
	}()

	dialer := &websocket.Dialer{HandshakeTimeout: pokeHandshakeTimeout}
	conn, httpResp, err := dialer.Dial(cfg.wsURL, nil)
	if err != nil {
		if httpResp != nil {
			return false, fmt.Errorf("poke dial: %w (http %d)", err, httpResp.StatusCode)
		}
		return false, fmt.Errorf("poke dial: %w", err)
	}
	defer conn.Close()

	// Unblock the blocking reads below when the puller stops.
	sessionDone := make(chan struct{})
	defer close(sessionDone)
	go func() {
		select {
		case <-p.stopCh:
			conn.Close()
		case <-sessionDone:
		}
	}()

	var writeMu sync.Mutex
	writeJSON := func(v any) error {
		writeMu.Lock()
		defer writeMu.Unlock()
		_ = conn.SetWriteDeadline(time.Now().Add(pokeWriteTimeout))
		return conn.WriteJSON(v)
	}

	// 1. pusher:connection_established → socket_id + activity_timeout.
	_ = conn.SetReadDeadline(time.Now().Add(pokeHandshakeTimeout))
	var hello pusherFrame
	if err := conn.ReadJSON(&hello); err != nil {
		return false, fmt.Errorf("poke handshake read: %w", err)
	}
	if hello.Event != "pusher:connection_established" {
		return false, fmt.Errorf("poke handshake: unexpected event %q", hello.Event)
	}
	var est struct {
		SocketID        string      `json:"socket_id"`
		ActivityTimeout json.Number `json:"activity_timeout"`
	}
	if err := json.Unmarshal([]byte(hello.dataString()), &est); err != nil {
		return false, fmt.Errorf("poke handshake decode: %w", err)
	}
	if est.SocketID == "" {
		return false, fmt.Errorf("poke handshake: empty socket_id")
	}
	activity := pokeDefaultActivityTimeout
	if secs, aerr := est.ActivityTimeout.Int64(); aerr == nil && secs > 0 {
		activity = max(time.Duration(secs)*time.Second, pokeMinActivityTimeout)
	}

	// 2. Private-channel auth via the existing device broadcasting endpoint.
	auth, err := p.pokeChannelAuth(est.SocketID, cfg.channel)
	if err != nil {
		return false, err
	}

	// 3. Subscribe.
	if err := writeJSON(map[string]any{
		"event": "pusher:subscribe",
		"data":  map[string]string{"channel": cfg.channel, "auth": auth},
	}); err != nil {
		return false, fmt.Errorf("poke subscribe write: %w", err)
	}

	// 4. Keepalive: send pusher:ping every activity_timeout (from the
	// connection_established payload) so an idle connection stays alive.
	go func() {
		t := time.NewTicker(activity)
		defer t.Stop()
		for {
			select {
			case <-sessionDone:
				return
			case <-t.C:
				if writeJSON(map[string]any{"event": "pusher:ping", "data": "{}"}) != nil {
					return
				}
			}
		}
	}()

	// 5. Listen. Read deadline = 2×activity + slack: a connection that stays
	// silent past our own keepalive pings is dead — bail out and reconnect.
	for {
		_ = conn.SetReadDeadline(time.Now().Add(2*activity + pokeHandshakeTimeout))
		var f pusherFrame
		if err := conn.ReadJSON(&f); err != nil {
			return subscribed, fmt.Errorf("poke read: %w", err)
		}
		switch f.Event {
		case "pusher:ping":
			_ = writeJSON(map[string]any{"event": "pusher:pong", "data": "{}"})
		case "pusher:pong":
			// our keepalive answered — nothing to do
		case "pusher_internal:subscription_succeeded":
			if !subscribed {
				subscribed = true
				slog.Info("sync poke connected", "channel", cfg.channel)
			}
		case "pusher:error":
			return subscribed, fmt.Errorf("pusher error frame: %s", f.dataString())
		case pokeEventName:
			// The whole point: an empty hint → run the same manifest check
			// early. Non-blocking, coalescing, debounced on the loop side.
			p.Kick()
		}
	}
}

// pokeChannelAuth POSTs the existing device broadcasting-auth endpoint to
// sign the private-channel subscription with the workstation's device token.
func (p *SyncPuller) pokeChannelAuth(socketID, channel string) (string, error) {
	token := ""
	if p.tokenFn != nil {
		token = p.tokenFn()
	}
	if token == "" {
		return "", fmt.Errorf("poke auth: not paired")
	}
	baseURL := p.resolveURL()
	if baseURL == "" {
		return "", fmt.Errorf("poke auth: cloud URL not configured")
	}

	form := url.Values{}
	form.Set("socket_id", socketID)
	form.Set("channel_name", channel)

	ctx, cancel := context.WithTimeout(context.Background(), pokeWriteTimeout)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, baseURL+pokeAuthPath, strings.NewReader(form.Encode()))
	if err != nil {
		return "", err
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("Authorization", "Bearer "+token)

	resp, err := p.httpClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("poke auth POST: %w", err)
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return "", fmt.Errorf("poke auth %d: %s", resp.StatusCode, string(body))
	}
	var out struct {
		Auth string `json:"auth"`
	}
	if err := json.Unmarshal(body, &out); err != nil {
		return "", fmt.Errorf("poke auth decode: %w", err)
	}
	if out.Auth == "" {
		return "", fmt.Errorf("poke auth: empty auth signature")
	}
	return out.Auth, nil
}

// pokeSleep waits d or until Stop. Returns false on stop.
func (p *SyncPuller) pokeSleep(d time.Duration) bool {
	select {
	case <-p.stopCh:
		return false
	case <-time.After(d):
		return true
	}
}
