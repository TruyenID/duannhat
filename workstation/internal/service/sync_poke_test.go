package service

// #1175 Part 2 — poke client tests. The happy path pins that a sync.poke
// event triggers an EARLY manifest check; the chaos tests pin the
// non-negotiable invariant: a refused / dropping / garbage-speaking poke
// provider changes NOTHING about the pull loop except update latency, the
// client reconnects with backoff, and no goroutines leak past Stop().

import (
	"encoding/json"
	"net"
	"net/http"
	"net/http/httptest"
	"net/url"
	"runtime"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/gorilla/websocket"
)

// setPokeSettings wires the poke config KVs (shop_settings mirror + branch
// id) to point at the given ws server URL.
func setPokeSettings(t *testing.T, p *SyncPuller, wsServerURL string) {
	t.Helper()
	u, err := url.Parse(wsServerURL)
	if err != nil {
		t.Fatalf("parse ws url: %v", err)
	}
	for k, v := range map[string]string{
		pokeSettingAppKey: "app-key-1",
		pokeSettingHost:   u.Hostname(),
		pokeSettingPort:   u.Port(),
		pokeSettingScheme: "http",
	} {
		if _, err := p.db.Exec(`
			INSERT INTO shop_settings (key, value) VALUES (?, ?)
			ON CONFLICT(key) DO UPDATE SET value = excluded.value`, k, v); err != nil {
			t.Fatalf("seed shop_settings %s: %v", k, err)
		}
	}
	if err := p.setCursor("workstation_branch_id", "b1"); err != nil {
		t.Fatalf("seed branch id: %v", err)
	}
}

// startFakePusher runs a websocket server; behavior drives the per-connection
// pusher-protocol conversation. Returns the server and an upgrade counter.
func startFakePusher(t *testing.T, behavior func(c *websocket.Conn)) (*httptest.Server, *int32) {
	t.Helper()
	var upgrades int32
	up := websocket.Upgrader{CheckOrigin: func(*http.Request) bool { return true }}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		c, err := up.Upgrade(w, r, nil)
		if err != nil {
			return
		}
		atomic.AddInt32(&upgrades, 1)
		defer c.Close()
		behavior(c)
	}))
	t.Cleanup(srv.Close)
	return srv, &upgrades
}

func pusherHello(c *websocket.Conn) error {
	return c.WriteJSON(map[string]any{
		"event": "pusher:connection_established",
		"data":  `{"socket_id":"123.456","activity_timeout":30}`,
	})
}

// assertGoroutinesSettle polls until the goroutine count returns near the
// recorded baseline — the leak-ish assertion for the poke goroutines.
func assertGoroutinesSettle(t *testing.T, before int) {
	t.Helper()
	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		if runtime.NumGoroutine() <= before+2 {
			return
		}
		time.Sleep(50 * time.Millisecond)
	}
	t.Errorf("goroutine leak-ish: before=%d after=%d", before, runtime.NumGoroutine())
}

func Test_Poke_TriggersEarlyManifestCheck(t *testing.T) {
	// Cloud: manifest (bootstraps then 304s) + broadcasting auth.
	var mu sync.Mutex
	manifestHits := 0
	var gotAuthBody url.Values
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case pullPathSyncManifest:
			mu.Lock()
			manifestHits++
			mu.Unlock()
			if r.Header.Get("If-None-Match") == `"v1"` {
				w.WriteHeader(http.StatusNotModified)
				return
			}
			w.Write([]byte(`{"data":{"manifest_version":"v1","feeds":{}}}`))
		case pokeAuthPath:
			_ = r.ParseForm()
			mu.Lock()
			gotAuthBody = r.PostForm
			mu.Unlock()
			w.Write([]byte(`{"auth":"app-key-1:signature"}`))
		default:
			w.Write([]byte(feedCannedResponse(r.URL.Path)))
		}
	}))
	defer cloud.Close()

	// Pusher server: handshake, accept subscribe, then poke repeatedly until
	// one lands past the loop's debounce window.
	subscribeCh := make(chan pusherFrame, 1)
	ws, _ := startFakePusher(t, func(c *websocket.Conn) {
		if pusherHello(c) != nil {
			return
		}
		var sub pusherFrame
		if err := c.ReadJSON(&sub); err != nil {
			return
		}
		select {
		case subscribeCh <- sub:
		default:
		}
		_ = c.WriteJSON(map[string]any{
			"event":   "pusher_internal:subscription_succeeded",
			"channel": "private-workstation.sync.b1",
			"data":    "{}",
		})
		for range 40 {
			if c.WriteJSON(map[string]any{
				"event":   pokeEventName,
				"channel": "private-workstation.sync.b1",
				"data":    "{}",
			}) != nil {
				return
			}
			time.Sleep(200 * time.Millisecond)
		}
	})

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS-TOKEN"))
	setPokeSettings(t, p, ws.URL)

	done := make(chan struct{})
	go func() {
		p.loopWithKick(time.Hour, p.manifestTick) // ticker never fires — only pokes can re-check
		close(done)
	}()
	go p.runPokeClient()
	defer func() { p.Stop(); <-done }()

	// The initial pass fetches the manifest once; a post-debounce poke must
	// drive at least one EARLY re-check (the 1 h ticker can't).
	deadline := time.Now().Add(10 * time.Second)
	for {
		mu.Lock()
		n := manifestHits
		mu.Unlock()
		if n >= 2 {
			break
		}
		if time.Now().After(deadline) {
			t.Fatalf("poke never triggered an early manifest check (hits=%d)", n)
		}
		time.Sleep(25 * time.Millisecond)
	}

	// The subscription must target the branch channel with the auth
	// signature returned by the device broadcasting-auth endpoint.
	select {
	case sub := <-subscribeCh:
		if sub.Event != "pusher:subscribe" {
			t.Errorf("expected pusher:subscribe, got %q", sub.Event)
		}
		var data struct {
			Channel string `json:"channel"`
			Auth    string `json:"auth"`
		}
		if err := jsonUnmarshalLoose(sub.Data, &data); err != nil {
			t.Fatalf("subscribe data: %v", err)
		}
		if data.Channel != "private-workstation.sync.b1" {
			t.Errorf("wrong channel %q", data.Channel)
		}
		if data.Auth != "app-key-1:signature" {
			t.Errorf("wrong auth %q", data.Auth)
		}
	default:
		t.Fatal("server never received a subscribe frame")
	}
	mu.Lock()
	defer mu.Unlock()
	if gotAuthBody.Get("channel_name") != "private-workstation.sync.b1" {
		t.Errorf("auth POST channel_name = %q", gotAuthBody.Get("channel_name"))
	}
	if gotAuthBody.Get("socket_id") != "123.456" {
		t.Errorf("auth POST socket_id = %q", gotAuthBody.Get("socket_id"))
	}
}

// jsonUnmarshalLoose decodes frame data that may or may not be
// double-encoded (our client sends subscribe data as a plain object).
func jsonUnmarshalLoose(raw []byte, out any) error {
	f := pusherFrame{Data: raw}
	return json.Unmarshal([]byte(f.dataString()), out)
}

func Test_PokeChaos_RefusedConnections_PullCadenceUnaffected(t *testing.T) {
	// A TCP listener that accepts and immediately slams the door.
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	var attempts int32
	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				return
			}
			atomic.AddInt32(&attempts, 1)
			conn.Close()
		}
	}()

	// Baseline AFTER the listener exists (its accept loop is torn down by
	// the deferred close, not by p.Stop).
	before := runtime.NumGoroutine()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://unused", staticTokenFn("WS"))
	setPokeSettings(t, p, "http://"+ln.Addr().String())

	var ticks int32
	done := make(chan struct{})
	go func() {
		p.loopWithKick(60*time.Millisecond, func() { atomic.AddInt32(&ticks, 1) })
		close(done)
	}()
	go p.runPokeClient()

	// Long enough for ≥2 dial attempts under 1 s → 2 s backoff.
	time.Sleep(3500 * time.Millisecond)

	if got := atomic.LoadInt32(&attempts); got < 2 {
		t.Errorf("expected reconnect attempts with backoff, got %d dials", got)
	}
	// Pull cadence must be unaffected: ~58 ticks in 3.5 s at 60 ms; allow
	// generous scheduling slack but fail on any real stall.
	if got := atomic.LoadInt32(&ticks); got < 30 {
		t.Errorf("pull ticks stalled while poke provider was down: %d ticks in 3.5s", got)
	}

	p.Stop()
	<-done
	assertGoroutinesSettle(t, before)
}

func Test_PokeChaos_GarbageFrames_PullCadenceUnaffected(t *testing.T) {
	ws, upgrades := startFakePusher(t, func(c *websocket.Conn) {
		// Speak garbage instead of the pusher protocol.
		_ = c.WriteMessage(websocket.TextMessage, []byte("THIS IS NOT PUSHER {{{"))
		time.Sleep(50 * time.Millisecond)
	})

	// Baseline AFTER the server exists (its accept loop is torn down by the
	// test cleanup, not by p.Stop).
	before := runtime.NumGoroutine()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://unused", staticTokenFn("WS"))
	setPokeSettings(t, p, ws.URL)

	var ticks int32
	done := make(chan struct{})
	go func() {
		p.loopWithKick(60*time.Millisecond, func() { atomic.AddInt32(&ticks, 1) })
		close(done)
	}()
	go p.runPokeClient()

	time.Sleep(3500 * time.Millisecond)

	if got := atomic.LoadInt32(upgrades); got < 2 {
		t.Errorf("expected reconnects after garbage frames, got %d sessions", got)
	}
	if got := atomic.LoadInt32(&ticks); got < 30 {
		t.Errorf("pull ticks stalled on protocol garbage: %d ticks in 3.5s", got)
	}

	p.Stop()
	<-done
	assertGoroutinesSettle(t, before)
}

func Test_PokeChaos_DropMidStream_ReconnectsAndPullUnaffected(t *testing.T) {
	// Cloud only needs the auth endpoint for this test.
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == pokeAuthPath {
			w.Write([]byte(`{"auth":"app-key-1:sig"}`))
			return
		}
		w.Write([]byte(`{"data":[]}`))
	}))
	defer cloud.Close()

	ws, upgrades := startFakePusher(t, func(c *websocket.Conn) {
		if pusherHello(c) != nil {
			return
		}
		var sub pusherFrame
		if c.ReadJSON(&sub) != nil {
			return
		}
		_ = c.WriteJSON(map[string]any{
			"event":   "pusher_internal:subscription_succeeded",
			"channel": "private-workstation.sync.b1",
			"data":    "{}",
		})
		_ = c.WriteJSON(map[string]any{"event": pokeEventName, "data": "{}"})
		// …then die mid-stream.
	})

	// Baseline AFTER the servers exist so their accept loops (torn down by
	// the deferred closes, not by p.Stop) don't read as poke leaks.
	before := runtime.NumGoroutine()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	setPokeSettings(t, p, ws.URL)

	var ticks int32
	done := make(chan struct{})
	start := time.Now()
	go func() {
		p.loopWithKick(60*time.Millisecond, func() { atomic.AddInt32(&ticks, 1) })
		close(done)
	}()
	go p.runPokeClient()

	// Successful subscribe resets backoff to 1 s → a reconnect must appear
	// well within the window.
	deadline := time.Now().Add(6 * time.Second)
	for atomic.LoadInt32(upgrades) < 2 && time.Now().Before(deadline) {
		time.Sleep(50 * time.Millisecond)
	}
	if got := atomic.LoadInt32(upgrades); got < 2 {
		t.Errorf("expected a reconnect after mid-stream drop, got %d sessions", got)
	}
	// Pull cadence unaffected: ~16.6 ticks/s ideal at 60 ms; require at
	// least half of that over the elapsed window.
	elapsed := time.Since(start)
	if got, wantMin := atomic.LoadInt32(&ticks), int32(elapsed.Seconds()*8); got < wantMin {
		t.Errorf("pull ticks stalled across poke drops: %d ticks in %v (want ≥ %d)", got, elapsed, wantMin)
	}

	p.Stop()
	<-done
	// Idle keep-alive conns from the auth POSTs hold transport goroutines —
	// they belong to the shared http.Client, not the poke client.
	p.httpClient.CloseIdleConnections()
	assertGoroutinesSettle(t, before)
}

func Test_PokeConfig_MissingMeansDisabled(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://unused", staticTokenFn("WS"))

	// Nothing configured at all.
	if _, ok := p.loadPokeConfig(); ok {
		t.Fatal("empty config must disable poke")
	}

	// Host+key present but no branch id → still disabled.
	for k, v := range map[string]string{
		pokeSettingAppKey: "k",
		pokeSettingHost:   "reverb.example",
	} {
		if _, err := p.db.Exec(`INSERT INTO shop_settings (key, value) VALUES (?, ?)`, k, v); err != nil {
			t.Fatal(err)
		}
	}
	if _, ok := p.loadPokeConfig(); ok {
		t.Fatal("missing branch id must disable poke")
	}

	// Branch id lands → enabled with wss default and the branch channel.
	if err := p.setCursor("workstation_branch_id", "b9"); err != nil {
		t.Fatal(err)
	}
	cfg, ok := p.loadPokeConfig()
	if !ok {
		t.Fatal("complete config must enable poke")
	}
	if cfg.channel != "private-workstation.sync.b9" {
		t.Errorf("channel = %q", cfg.channel)
	}
	wantPrefix := "wss://reverb.example/app/k?"
	if len(cfg.wsURL) < len(wantPrefix) || cfg.wsURL[:len(wantPrefix)] != wantPrefix {
		t.Errorf("wsURL = %q, want prefix %q", cfg.wsURL, wantPrefix)
	}

	// Unknown scheme → disabled (fail closed, never guess).
	if _, err := p.db.Exec(`INSERT INTO shop_settings (key, value) VALUES (?, 'gopher')`, pokeSettingScheme); err != nil {
		t.Fatal(err)
	}
	if _, ok := p.loadPokeConfig(); ok {
		t.Fatal("unknown scheme must disable poke")
	}
}

// Edge: a garbage broadcast_scheme value means poke is DISABLED, not guessed.
func Test_PokeConfig_GarbageSchemeMeansDisabled(t *testing.T) {
	db := newPullerTestDB(t)
	p := NewSyncPuller(db, "http://unused", staticTokenFn("WS"))
	for k, v := range map[string]string{
		pokeSettingAppKey: "k",
		pokeSettingHost:   "reverb.example",
		pokeSettingScheme: "gopher",
	} {
		if _, err := db.Exec(`INSERT INTO shop_settings (key, value) VALUES (?, ?)`, k, v); err != nil {
			t.Fatal(err)
		}
	}
	if err := p.setCursor("workstation_branch_id", "b1"); err != nil {
		t.Fatal(err)
	}
	if _, ok := p.loadPokeConfig(); ok {
		t.Fatal("garbage scheme must disable poke, not default it")
	}
}

// Edge: the broadcasting-auth endpoint rejecting the device (403) must leave
// the pull cadence untouched — the client backs off and retries, nothing else.
func Test_PokeChaos_AuthRejected_PullCadenceUnaffected(t *testing.T) {
	var mu sync.Mutex
	manifestHits, authHits := 0, 0
	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case pullPathSyncManifest:
			mu.Lock()
			manifestHits++
			mu.Unlock()
			w.Write([]byte(`{"data":{"manifest_version":"v1","feeds":{}}}`))
		case pokeAuthPath:
			mu.Lock()
			authHits++
			mu.Unlock()
			w.WriteHeader(http.StatusForbidden)
		default:
			w.Write([]byte(feedCannedResponse(r.URL.Path)))
		}
	}))
	defer cloud.Close()

	ws, _ := startFakePusher(t, func(c *websocket.Conn) {
		_ = pusherHello(c)
		// wait for a subscribe that will never come (auth 403s first)
		var f pusherFrame
		_ = c.ReadJSON(&f)
	})

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	setPokeSettings(t, p, ws.URL)

	done := make(chan struct{})
	go func() {
		p.loopWithKick(150*time.Millisecond, p.manifestTick)
		close(done)
	}()
	go p.runPokeClient()
	time.Sleep(1200 * time.Millisecond)
	p.Stop()
	<-done

	mu.Lock()
	defer mu.Unlock()
	if authHits == 0 {
		t.Fatal("auth endpoint was never attempted")
	}
	if manifestHits < 5 {
		t.Fatalf("pull cadence degraded under auth rejection: %d manifest hits", manifestHits)
	}
}

// Edge: the client must answer pusher:ping with pusher:pong or Reverb drops
// the connection at the activity timeout.
func Test_Poke_RespondsToPusherPing(t *testing.T) {
	pongCh := make(chan struct{}, 1)
	ws, _ := startFakePusher(t, func(c *websocket.Conn) {
		if pusherHello(c) != nil {
			return
		}
		var sub pusherFrame
		if c.ReadJSON(&sub) != nil {
			return
		}
		_ = c.WriteJSON(map[string]any{
			"event":   "pusher_internal:subscription_succeeded",
			"channel": sub.Channel,
			"data":    "{}",
		})
		if c.WriteJSON(map[string]any{"event": "pusher:ping", "data": "{}"}) != nil {
			return
		}
		for {
			var f pusherFrame
			if c.ReadJSON(&f) != nil {
				return
			}
			if f.Event == "pusher:pong" {
				select {
				case pongCh <- struct{}{}:
				default:
				}
				return
			}
		}
	})

	cloud := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == pokeAuthPath {
			w.Write([]byte(`{"auth":"app-key-1:sig"}`))
			return
		}
		w.Write([]byte(`{"data":{"manifest_version":"v1","feeds":{}}}`))
	}))
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("WS"))
	setPokeSettings(t, p, ws.URL)
	go p.runPokeClient()
	defer p.Stop()

	select {
	case <-pongCh:
	case <-time.After(5 * time.Second):
		t.Fatal("client never answered pusher:ping with pusher:pong")
	}
}
