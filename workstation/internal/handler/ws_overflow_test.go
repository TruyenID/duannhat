package handler

import (
	"net/http"
	"net/http/httptest"
	"reflect"
	"strings"
	"testing"
	"time"

	"github.com/gorilla/websocket"
)

// #1793 — a client whose send buffer overflows has already missed an event.
// The old behaviour dropped the EVENT and kept the client connected, so it sat
// there believing it was live with a permanently incomplete view: dropping an
// event closes nothing, so there is no reconnect to "refetch on". These tests
// pin the replacement: the CLIENT is closed (4409) so its reconnect+refetch
// path runs.

func TestHub_OverflowClosesClientInsteadOfDroppingEventSilently(t *testing.T) {
	hub := NewHub()
	go hub.Run()
	defer hub.Stop()

	// A real socket, so we can observe the close frame the client receives.
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		conn, err := (&websocket.Upgrader{CheckOrigin: func(*http.Request) bool { return true }}).Upgrade(w, r, nil)
		if err != nil {
			t.Errorf("upgrade: %v", err)
			return
		}
		// Register with a send buffer of 1 that we immediately fill, so the
		// next fan-out overflows. No pumps: nothing drains it, which is exactly
		// the "client too slow to keep up" shape.
		client := &Client{hub: hub, conn: conn, branchID: "branch-1", send: make(chan []byte, 1)}
		client.send <- []byte("occupied")
		hub.register <- client
	}))
	defer srv.Close()

	dialed, _, err := websocket.DefaultDialer.Dial("ws"+strings.TrimPrefix(srv.URL, "http"), nil)
	if err != nil {
		t.Fatalf("dial: %v", err)
	}
	defer dialed.Close()

	waitForClients(t, hub, 1)

	gotClose := make(chan *websocket.CloseError, 1)
	go func() {
		for {
			if _, _, err := dialed.ReadMessage(); err != nil {
				if ce, ok := err.(*websocket.CloseError); ok {
					gotClose <- ce
				} else {
					gotClose <- &websocket.CloseError{Code: -1, Text: err.Error()}
				}
				return
			}
		}
	}()

	hub.BroadcastEventScoped("order_updated", map[string]any{"x": 1}, "branch-1")

	select {
	case ce := <-gotClose:
		if ce.Code != wsCloseDesync {
			t.Fatalf("close code = %d, want %d (desync); text=%q", ce.Code, wsCloseDesync, ce.Text)
		}
	case <-time.After(3 * time.Second):
		t.Fatal("overflowed client was NOT closed — the event was dropped silently, which is the #1793 bug")
	}
}

// A slow client must not take the healthy ones down with it: the fan-out still
// delivers to everyone whose buffer has room.
func TestHub_OverflowDoesNotStarveHealthyClients(t *testing.T) {
	hub := NewHub()
	go hub.Run()
	defer hub.Stop()

	// conn==nil is fine here: dropForOverflow tolerates it (unit-test client).
	slow := &Client{hub: hub, branchID: "branch-1", send: make(chan []byte, 1)}
	slow.send <- []byte("occupied")
	healthy := &Client{hub: hub, branchID: "branch-1", send: make(chan []byte, 4)}
	hub.register <- slow
	hub.register <- healthy
	waitForClients(t, hub, 2)

	hub.BroadcastEventScoped("order_updated", map[string]any{"x": 1}, "branch-1")

	select {
	case <-healthy.send:
		// good — a slow neighbour did not cost the healthy client its event
	case <-time.After(time.Second):
		t.Fatal("healthy client did not receive the event")
	}
}

// dropForOverflow fires once even if several events overflow back to back —
// the second close would race the teardown.
func TestClient_DropForOverflowIsIdempotent(t *testing.T) {
	c := &Client{send: make(chan []byte, 1)}
	c.dropForOverflow("order_updated")
	c.dropForOverflow("order_updated") // must not panic
}

// The dead `broadcast` channel that mutated hub.clients under a READ lock is
// gone (#1793 item 2). Re-introducing a message channel and wiring Run() back
// to it inherits that concurrent-map-write panic, which only shows up under
// load. Assert on the STRUCT, not on behaviour: a behavioural test passes
// whether or not the field exists, so it would answer "yes" to "is this
// guarded?" while guarding nothing.
func TestHub_HasNoMessageChannelField(t *testing.T) {
	hubType := reflect.TypeOf(Hub{})
	byteSlice := reflect.TypeOf([]byte(nil))

	for i := range hubType.NumField() {
		f := hubType.Field(i)
		if f.Type.Kind() != reflect.Chan {
			continue
		}
		// register/unregister carry *Client; stopCh carries struct{}. A channel
		// of message bytes means someone re-added the fan-out relay.
		if f.Type.Elem() == byteSlice {
			t.Fatalf("Hub.%s is a chan []byte — the fan-out relay is back (#1793). "+
				"Run()'s branch for it closed client.send and deleted from hub.clients "+
				"under a READ lock, which races BroadcastEventScoped/ClientCount and "+
				"panics with concurrent map write under load. Fan out directly instead.",
				f.Name)
		}
	}
}

// Fan-out must not block on a stuck client: it runs synchronously inside
// request handlers (lan_print.go broadcasts while the cashier waits on the
// print response), and each drop writes a close frame with a 1s deadline.
func TestHub_FanoutDoesNotBlockOnOverflowedClients(t *testing.T) {
	hub := NewHub()
	go hub.Run()
	defer hub.Stop()

	// Three clients that can never accept another message.
	for range 3 {
		c := &Client{hub: hub, branchID: "branch-1", send: make(chan []byte, 1)}
		c.send <- []byte("occupied")
		hub.register <- c
	}
	waitForClients(t, hub, 3)

	done := make(chan struct{})
	go func() {
		hub.BroadcastEventScoped("order_updated", map[string]any{"x": 1}, "branch-1")
		close(done)
	}()

	select {
	case <-done:
	case <-time.After(500 * time.Millisecond):
		t.Fatal("fan-out blocked on overflowed clients — a print request would stall behind it")
	}
}
