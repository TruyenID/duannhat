package handler

import (
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// `/api/terminal/next` MUTATES: it hands the pending command out and moves the
// session to processing, so whoever calls it owns driving the P400. Registered
// as a GET it was a trap — and Go's ServeMux routes HEAD to a GET pattern too,
// so a link checker or health probe was enough to consume a charge and leave the
// machine wedged in `processing` with the terminal never rung.
//
// Source-level, like TestCashChangerBridgeIsMountedForBothSurfaces: Go's
// ServeMux cannot be asked what it was registered with. The property worth
// pinning is that the verb does not quietly drift back.
func TestTerminalNextIsRegisteredAsPOST(t *testing.T) {
	src := readSource(t, "routes.go")

	if !strings.Contains(src, `mux.Handle("POST /api/terminal/next"`) {
		t.Error(`/api/terminal/next is not registered as POST — it dequeues a command, so it must not be a safe method`)
	}
	if strings.Contains(src, `"GET /api/terminal/next"`) || strings.Contains(src, `"HEAD /api/terminal/next"`) {
		t.Error(`/api/terminal/next is registered for GET/HEAD again: any prefetch, curl or probe steals the command and the P400 is never driven`)
	}
}

// The two ends of the relay live in different languages and must agree on the
// verb. Changing one side alone breaks card payments silently — the poll just
// stops returning commands.
func TestTerminalBridgeFrontendPollsWithPOST(t *testing.T) {
	src := readSource(t, "../../frontend/src/providers/terminal-bridge.tsx")

	if !strings.Contains(src, `method: "POST"`) {
		t.Error("the Wails bridge does not POST to /api/terminal/next — it will get 405 and no card charge will ever start")
	}
	// The liveness signal the Go side expires against: a webview that reloaded
	// mid-transaction keeps polling but drives nothing, and must stop vouching
	// for the session it can no longer report on.
	if !strings.Contains(src, "driving") {
		t.Error("the poll does not send the session it is driving — Go cannot tell a live driver from a reloaded webview")
	}
	// Polling before VescaJS says READY consumes the command and drops it.
	if !strings.Contains(src, "if (!readyRef.current)") {
		t.Error("the bridge polls before the VescaJS iframe is READY — the dequeued command is discarded and the session sticks in processing")
	}
}

// The way out of a stuck machine has to be reachable from where the cashier
// stands. localOnly means loopback, and a cashier on a LAN tablet is not
// loopback — a mistake that hides perfectly on a dev box at localhost.
func TestTerminalRecoveryRoutesArePosAuthed(t *testing.T) {
	src := readSource(t, "routes.go")

	for _, route := range []string{
		`/api/v1/pos/terminal/current"`,
		`/api/v1/pos/terminal/abandon"`,
	} {
		var line string
		for l := range strings.SplitSeq(src, "\n") {
			if strings.Contains(l, route) {
				line = l
				break
			}
		}
		if line == "" {
			t.Errorf("route missing: %s — a 409 stays a dead end without it", route)
			continue
		}
		if !strings.Contains(line, "posAuth") {
			t.Errorf("route is not behind posAuth (localOnly would 403 every LAN tablet):\n  %s", strings.TrimSpace(line))
		}
	}
}

// A busy answer that cannot name what is busy is not actionable. During QA it
// left the shop holding a 409 whose session id nobody could produce — and no
// endpoint that could produce it either.
func TestCardTerminalBusyNamesTheBlockingSession(t *testing.T) {
	t.Setenv("WS_APP_CARD_TERMINAL_HOST", "192.168.0.77")
	s := newTerminalServer(t)
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o1', 'checkout', 3000)`)
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('o2', 'checkout', 1500)`)

	charge := func(order string) *httptest.ResponseRecorder {
		rr := httptest.NewRecorder()
		s.handleCardTerminalCharge(rr, httptest.NewRequest(http.MethodPost,
			"/api/v1/pos/terminal/charge", strings.NewReader(`{"order_id":"`+order+`"}`)))
		return rr
	}

	if rr := charge("o1"); rr.Code != http.StatusAccepted {
		t.Fatalf("first charge = %d, want 202", rr.Code)
	}
	rr := charge("o2")
	if rr.Code != http.StatusConflict {
		t.Fatalf("second charge = %d, want 409", rr.Code)
	}
	body := rr.Body.String()
	for _, want := range []string{"active_session", "session_id", `"order_id":"o1"`, "started_at"} {
		if !strings.Contains(body, want) {
			t.Errorf("409 body is missing %q — the cashier has nothing to act on:\n  %s", want, body)
		}
	}

	// …and /current answers the same question without needing an id first.
	cur := httptest.NewRecorder()
	s.handleCardTerminalCurrent(cur, httptest.NewRequest(http.MethodGet, "/api/v1/pos/terminal/current", nil))
	if cur.Code != http.StatusOK || !strings.Contains(cur.Body.String(), `"order_id":"o1"`) {
		t.Errorf("current = %d %s, want the blocking session", cur.Code, cur.Body.String())
	}

	// Abandon frees the machine and says `unknown`, never `canceled`: whoever
	// pressed it does not know whether the card was captured.
	ab := httptest.NewRecorder()
	s.handleCardTerminalAbandon(ab, httptest.NewRequest(http.MethodPost, "/api/v1/pos/terminal/abandon", nil))
	if ab.Code != http.StatusOK || !strings.Contains(ab.Body.String(), `"status":"unknown"`) {
		t.Fatalf("abandon = %d %s, want 200 + unknown", ab.Code, ab.Body.String())
	}
	if rr := charge("o2"); rr.Code != http.StatusAccepted {
		t.Fatalf("charge after abandon = %d, want 202 — the machine must be free", rr.Code)
	}

	idle := httptest.NewRecorder()
	s.terminalBridge = service.NewTerminalBridge(s) // nothing in flight
	s.handleCardTerminalCurrent(idle, httptest.NewRequest(http.MethodGet, "/api/v1/pos/terminal/current", nil))
	if idle.Code != http.StatusNoContent {
		t.Errorf("current on an idle bridge = %d, want 204", idle.Code)
	}
}

func readSource(t *testing.T, path string) string {
	t.Helper()
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read %s: %v", path, err)
	}
	return string(b)
}

// The guard that was missing on the evening one ¥715 order took four ¥715
// charges. A sync bug kept Cloud from ever hearing about the card payments, so
// pos-web — reading the order from Cloud — kept showing it unpaid and the
// cashier kept swiping. Every charge was real money off a real card.
//
// Local records are the authority on what THIS terminal has collected, so they
// are what decides. Hard 409, not a warning: unlike a reprint, a second swipe
// moves money and cannot be undone from here.
func TestCardTerminalRefusesAnOrderAlreadyPaidInFull(t *testing.T) {
	t.Setenv("WS_APP_CARD_TERMINAL_HOST", "192.168.0.77")
	s := newTerminalServer(t)
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('paid1', 'checkout', 715)`)
	s.db.Exec(`INSERT INTO payments (id, order_id, amount, status, payment_method)
	           VALUES ('p1', 'paid1', 715, 'succeeded', 'card')`)

	rr := httptest.NewRecorder()
	s.handleCardTerminalCharge(rr, httptest.NewRequest(http.MethodPost,
		"/api/v1/pos/terminal/charge", strings.NewReader(`{"order_id":"paid1"}`)))

	if rr.Code != http.StatusConflict {
		t.Fatalf("charge on a fully paid order = %d, want 409 — this is how a card gets charged twice", rr.Code)
	}
	if !strings.Contains(rr.Body.String(), "order_already_paid") {
		t.Errorf("409 must name the reason so the cashier knows to look at the payment list: %s", rr.Body.String())
	}

	// A partly paid order still charges: split bills and top-ups are legitimate.
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('part1', 'checkout', 2000)`)
	s.db.Exec(`INSERT INTO payments (id, order_id, amount, status, payment_method)
	           VALUES ('p2', 'part1', 500, 'succeeded', 'cash')`)
	pr := httptest.NewRecorder()
	s.handleCardTerminalCharge(pr, httptest.NewRequest(http.MethodPost,
		"/api/v1/pos/terminal/charge", strings.NewReader(`{"order_id":"part1"}`)))
	if pr.Code != http.StatusAccepted {
		t.Errorf("charge on a partly paid order = %d, want 202: %s", pr.Code, pr.Body.String())
	}

	// A voided/failed payment is not collected money and must not block a retry.
	s.db.Exec(`INSERT INTO orders (id, status, total_amount) VALUES ('fail1', 'checkout', 715)`)
	s.db.Exec(`INSERT INTO payments (id, order_id, amount, status, payment_method)
	           VALUES ('p3', 'fail1', 715, 'failed', 'card')`)
	s.terminalBridge = service.NewTerminalBridge(s)
	fr := httptest.NewRecorder()
	s.handleCardTerminalCharge(fr, httptest.NewRequest(http.MethodPost,
		"/api/v1/pos/terminal/charge", strings.NewReader(`{"order_id":"fail1"}`)))
	if fr.Code != http.StatusAccepted {
		t.Errorf("charge after a FAILED payment = %d, want 202 — nothing was collected: %s", fr.Code, fr.Body.String())
	}
}
