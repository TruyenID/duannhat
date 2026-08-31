package service

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"testing"
	"time"
)

// The customer-orders cursor only ever moved forward, to max(updated_at) of
// whatever Cloud returned, with no upper bound. A single row carrying a
// timestamp in the future pushed it past every real order and the workstation
// went blind — silently, because a cursor above the newest row has nothing to
// advance on. In production that meant no order reached the shop for hours and
// nothing printed at all: not the auto receipt, not a manual reprint.
//
// These tests pin the bound (Cloud's clock, not ours), the repair path for
// installs already poisoned, and the two cases where clamping must NOT happen.

// serveOrdersRecording is serveOrders plus a record of the request URLs, so a
// test can assert what the next tick actually sent.
func serveOrdersRecording(t *testing.T, urls *[]string, body func() string) *httptest.Server {
	t.Helper()
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		*urls = append(*urls, r.URL.String())
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(body()))
	}))
}

func cursorOf(t *testing.T, p *SyncPuller) string {
	t.Helper()
	return p.getCursor(settingsCursorKey)
}

// boundFor is the cursor value the clamp should produce for a given Cloud
// render time — expressed through the same margin the production code uses, so
// this stays honest if the margin is ever retuned.
func boundFor(t *testing.T, generatedAt string) string {
	t.Helper()
	gen, ok := parseCloudTime(generatedAt)
	if !ok {
		t.Fatalf("bad generated_at fixture %q", generatedAt)
	}
	return gen.Add(-cursorClockSafetyMargin).UTC().Format(time.RFC3339)
}

func orderRow(id, updatedAt string) string {
	return fmt.Sprintf(`{"id":%q,"status":"open","order_type":"dine_in",
		"opened_at":"2026-07-31T03:00:00Z","updated_at":%q,"branch_id":"branch-1"}`, id, updatedAt)
}

func TestParseCloudTime(t *testing.T) {
	cases := []struct {
		in   string
		ok   bool
		want string // RFC3339 UTC
	}{
		{"2026-07-31T04:00:00Z", true, "2026-07-31T04:00:00Z"},
		// Cloud actually emits this form (toIso8601String on a JST app clock).
		{"2026-07-31T13:00:00+09:00", true, "2026-07-31T04:00:00Z"},
		{"", false, ""},
		{"not-a-time", false, ""},
	}
	for _, c := range cases {
		got, ok := parseCloudTime(c.in)
		if ok != c.ok {
			t.Fatalf("parseCloudTime(%q) ok=%v, want %v", c.in, ok, c.ok)
		}
		if ok && got.Format(time.RFC3339) != c.want {
			t.Fatalf("parseCloudTime(%q) = %q, want %q", c.in, got.Format(time.RFC3339), c.want)
		}
	}
}

// The bound is Cloud's clock. A future-dated row must not drag the cursor past
// the instant Cloud rendered the response.
func TestPullCustomerOrders_FutureRowCannotPushCursorPastCloudClock(t *testing.T) {
	generatedAt := "2026-07-31T04:00:00Z"
	cloud := serveOrders(t, `{"data":[`+orderRow("future-1", "2026-07-31T15:00:00Z")+
		`],"count":1,"generated_at":"`+generatedAt+`"}`)
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))

	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	if got, want := cursorOf(t, p), boundFor(t, generatedAt); got != want {
		t.Fatalf("cursor must be clamped to Cloud's clock; got %q, want %q", got, want)
	}
	// And the row itself is still mirrored — clamping bounds the CURSOR, it
	// does not drop data.
	var n int
	_ = db.QueryRow(`SELECT COUNT(*) FROM orders WHERE id = 'future-1'`).Scan(&n)
	if n != 1 {
		t.Fatalf("future-dated order must still be mirrored; got %d rows", n)
	}
}

// The bound must sit strictly BELOW generated_at. Cloud stamps that field after
// reading the rows, so a cursor landing exactly on it skips anything updated
// mid-render — and the feed is `>=`, so a skipped row never comes back.
func TestPullCustomerOrders_ClampLeavesMarginBelowGeneratedAt(t *testing.T) {
	generatedAt := "2026-07-31T04:00:00Z"
	cloud := serveOrders(t, `{"data":[`+orderRow("future-1", "2026-07-31T15:00:00Z")+
		`],"count":1,"generated_at":"`+generatedAt+`"}`)
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}

	got, ok := parseCloudTime(cursorOf(t, p))
	if !ok {
		t.Fatalf("cursor is not a parseable time: %q", cursorOf(t, p))
	}
	gen, _ := parseCloudTime(generatedAt)
	if !got.Before(gen) {
		t.Fatalf("cursor %v must be strictly before generated_at %v", got, gen)
	}
}

// Cloud serialises with a numeric offset, never `Z`. The clamp must compare
// instants, not strings.
func TestPullCustomerOrders_ClampHandlesOffsetGeneratedAt(t *testing.T) {
	generatedAt := "2026-07-31T13:00:00+09:00" // == 04:00:00Z
	cloud := serveOrders(t, `{"data":[`+orderRow("future-1", "2026-07-31T15:00:00Z")+
		`],"count":1,"generated_at":"`+generatedAt+`"}`)
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}
	if got, want := cursorOf(t, p), boundFor(t, generatedAt); got != want {
		t.Fatalf("offset-form generated_at must clamp to the same instant; got %q, want %q", got, want)
	}
}

// The clamp must not depend on the local clock. A workstation whose clock runs
// fast is exactly the machine a `time.Now()` bound fails to protect — the clamp
// becomes a no-op while the tests go green. Cloud's clock is the only one both
// sides agree on, so a response generated in the local machine's distant past
// still bounds the cursor.
func TestPullCustomerOrders_ClampIgnoresLocalClock(t *testing.T) {
	generatedAt := "2020-01-01T00:00:00Z"
	cloud := serveOrders(t, `{"data":[`+orderRow("o-1", "2026-07-31T15:00:00Z")+
		`],"count":1,"generated_at":"`+generatedAt+`"}`)
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}
	if got, want := cursorOf(t, p), boundFor(t, generatedAt); got != want {
		t.Fatalf("clamp must follow Cloud's clock, not the local one; got %q, want %q", got, want)
	}
}

// The repair path. A workstation already carrying a poisoned cursor must heal
// itself, and it must do so on an EMPTY response — that is the only kind of
// response a poisoned cursor produces, so a repair placed after the
// "no rows, nothing to do" early return would never run on the machines that
// need it.
func TestPullCustomerOrders_HealsPoisonedCursorOnEmptyResponse(t *testing.T) {
	generatedAt := "2026-07-31T04:00:00Z"
	var urls []string
	cloud := serveOrdersRecording(t, &urls, func() string {
		return `{"data":[],"count":0,"generated_at":"` + generatedAt + `"}`
	})
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	if err := p.setCursor(settingsCursorKey, "2026-07-31T15:00:00Z"); err != nil {
		t.Fatalf("seed cursor: %v", err)
	}

	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}
	healed := boundFor(t, generatedAt)
	if got := cursorOf(t, p); got != healed {
		t.Fatalf("poisoned cursor must heal on an empty tick; got %q, want %q", got, healed)
	}

	// The very next tick must ASK with the healed cursor — that is what makes
	// the backlog visible again. Compare escaped: the cursor is URL-encoded.
	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("second pull: %v", err)
	}
	if len(urls) < 2 || !strings.Contains(urls[1], "updated_since="+url.QueryEscape(healed)) {
		t.Fatalf("second tick must query with the healed cursor; urls=%v", urls)
	}
	// `limit` must be on the wire — the full-page check below is meaningless if
	// the workstation and Cloud disagree about the page size.
	if !strings.Contains(urls[0], fmt.Sprintf("limit=%d", customerOrdersPullLimit)) {
		t.Fatalf("pull must send an explicit limit; url=%q", urls[0])
	}
}

// The healed value must feed THIS tick, not just the stored setting: the tick
// seeds maxUpdated from the cursor, so discarding the healed value leaves a
// future seed that swallows every row in the response.
func TestPullCustomerOrders_HealedCursorFeedsThisTick(t *testing.T) {
	generatedAt := "2026-07-31T04:00:00Z"
	rows := make([]string, 0, customerOrdersPullLimit)
	for i := range customerOrdersPullLimit {
		rows = append(rows, orderRow(fmt.Sprintf("o-%d", i), "2026-07-31T05:00:00Z"))
	}
	cloud := serveOrders(t, `{"data":[`+strings.Join(rows, ",")+`],"count":`+
		fmt.Sprint(customerOrdersPullLimit)+`,"generated_at":"`+generatedAt+`"}`)
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	if err := p.setCursor(settingsCursorKey, "2026-07-31T15:00:00Z"); err != nil {
		t.Fatalf("seed cursor: %v", err)
	}

	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}
	// Full page → no clamp → the cursor rides the data, which is only reachable
	// if the healed cursor (not the poisoned one) seeded maxUpdated.
	if got, want := cursorOf(t, p), "2026-07-31T05:00:00Z"; got != want {
		t.Fatalf("healed cursor must seed this tick; got %q, want %q", got, want)
	}
}

// A healthy cursor must not be dragged backwards, and normal forward progress
// must still happen.
func TestPullCustomerOrders_HealthyCursorAdvancesNormally(t *testing.T) {
	cloud := serveOrders(t, `{"data":[`+orderRow("o-1", "2026-07-31T03:30:00Z")+
		`],"count":1,"generated_at":"2026-07-31T04:00:00Z"}`)
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	if err := p.setCursor(settingsCursorKey, "2026-07-31T03:00:00Z"); err != nil {
		t.Fatalf("seed cursor: %v", err)
	}

	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}
	if got, want := cursorOf(t, p), "2026-07-31T03:30:00Z"; got != want {
		t.Fatalf("healthy cursor must advance to max(updated_at); got %q, want %q", got, want)
	}
}

// Back-compat: a Cloud too old to send `generated_at` must not be fatal, and
// must not be silently treated as "clamp to zero" — that would rewind the
// cursor to the epoch and re-pull all history on every tick.
func TestPullCustomerOrders_MissingGeneratedAtSkipsClamp(t *testing.T) {
	cloud := serveOrders(t, `{"data":[`+orderRow("o-1", "2026-07-31T03:30:00Z")+`],"count":1}`)
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pull must tolerate a Cloud without generated_at: %v", err)
	}
	if got, want := cursorOf(t, p), "2026-07-31T03:30:00Z"; got != want {
		t.Fatalf("without generated_at the cursor keeps legacy behaviour; got %q, want %q", got, want)
	}
}

// The trap the clamp itself could set. Cloud caps a page at
// customerOrdersPullLimit rows; a full page means there is MORE behind it. If
// the cursor were clamped there, it would pin below the backlog and Cloud would
// re-serve the same page forever — the identical silent blindness, reached by
// volume instead of by a bad timestamp. A full page must advance on the data's
// own timestamps.
func TestPullCustomerOrders_FullPageIsNotClamped(t *testing.T) {
	base := time.Date(2026, 7, 31, 4, 0, 0, 0, time.UTC)
	rows := make([]string, 0, customerOrdersPullLimit)
	for i := range customerOrdersPullLimit {
		rows = append(rows, orderRow(fmt.Sprintf("backlog-%d", i),
			base.Add(time.Duration(i)*time.Second).Format(time.RFC3339)))
	}
	last := base.Add(time.Duration(customerOrdersPullLimit-1) * time.Second).Format(time.RFC3339)

	cloud := serveOrders(t, `{"data":[`+strings.Join(rows, ",")+`],"count":`+
		fmt.Sprint(customerOrdersPullLimit)+`,"generated_at":"2026-07-31T04:00:00Z"}`)
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}
	if got := cursorOf(t, p); got != last {
		t.Fatalf("a full page must walk the backlog, not clamp; got %q, want %q", got, last)
	}
}

// Liveness backstop. A full page whose rows all share the cursor's own second
// would leave the cursor unmoved and Cloud re-serving the identical page
// forever — blind again, by a different road. A mass UPDATE (the tax backfills)
// produces exactly this shape. The cursor must step past it rather than stall.
func TestPullCustomerOrders_FullPageOfOneSecondStepsForward(t *testing.T) {
	stuck := "2026-07-31T04:00:00Z"
	rows := make([]string, 0, customerOrdersPullLimit)
	for i := range customerOrdersPullLimit {
		rows = append(rows, orderRow(fmt.Sprintf("same-%d", i), stuck))
	}
	cloud := serveOrders(t, `{"data":[`+strings.Join(rows, ",")+`],"count":`+
		fmt.Sprint(customerOrdersPullLimit)+`,"generated_at":"2026-07-31T09:00:00Z"}`)
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	if err := p.setCursor(settingsCursorKey, stuck); err != nil {
		t.Fatalf("seed cursor: %v", err)
	}

	if err := p.pullCustomerOrders(context.Background()); err != nil {
		t.Fatalf("pull: %v", err)
	}
	if got, want := cursorOf(t, p), "2026-07-31T04:00:01Z"; got != want {
		t.Fatalf("a stalled full page must step forward; got %q, want %q", got, want)
	}
}

// The force-pull is a side channel; it must not move the periodic puller's
// resume point.
func TestPullOrderNow_DoesNotTouchCursor(t *testing.T) {
	cloud := serveOrders(t, `{"data":[`+orderRow("o-1", "2026-07-31T09:00:00Z")+
		`],"count":1,"generated_at":"2026-07-31T04:00:00Z"}`)
	defer cloud.Close()

	db := newPullerTestDB(t)
	p := NewSyncPuller(db, cloud.URL, staticTokenFn("fake-token"))
	if err := p.setCursor(settingsCursorKey, "2026-07-31T03:00:00Z"); err != nil {
		t.Fatalf("seed cursor: %v", err)
	}

	if err := p.PullOrderNow(context.Background(), "o-1"); err != nil {
		t.Fatalf("force-pull: %v", err)
	}
	if got, want := cursorOf(t, p), "2026-07-31T03:00:00Z"; got != want {
		t.Fatalf("force-pull must leave the cursor alone; got %q, want %q", got, want)
	}
}

// The string-typed cursors (customers, print templates) folded their maximum
// with `>`, i.e. byte order. Cloud emits both `…Z` and `…+00:00` for the same
// moment, and `'+' (0x2B)` sorts BELOW `'Z' (0x5A)` — so one format change made
// every row in the cursor's own second look older, the maximum stopped moving,
// and the feed went quiet with nothing to show for it.
func TestMaxCursorString_ComparesInstantsNotBytes(t *testing.T) {
	// Same instant, two serialisations. Neither may be treated as later.
	if got := maxCursorString("2026-07-31T04:00:00Z", "2026-07-31T13:00:00+09:00"); got != "2026-07-31T04:00:00Z" {
		t.Fatalf("equal instants must not advance the cursor; got %q", got)
	}
	// The `+` form is genuinely later here — byte order would say otherwise.
	got := maxCursorString("2026-07-31T04:00:00Z", "2026-07-31T14:00:00+09:00") // = 05:00Z
	if got != "2026-07-31T14:00:00+09:00" {
		t.Fatalf("a later instant must win regardless of serialisation; got %q", got)
	}
	// Garbage carries no information and must never win.
	if got := maxCursorString("2026-07-31T04:00:00Z", "not-a-time"); got != "2026-07-31T04:00:00Z" {
		t.Fatalf("unparseable input must not move the cursor; got %q", got)
	}
	if got := maxCursorString("", "2026-07-31T04:00:00Z"); got != "2026-07-31T04:00:00Z" {
		t.Fatalf("an empty cursor must accept the first real value; got %q", got)
	}
}

// Same bound as the orders cursor, for the string-typed ones.
func TestBoundedCursorString(t *testing.T) {
	gen := "2026-07-31T04:00:00Z"
	want := boundFor(t, gen)

	if got := boundedCursorString("2026-07-31T15:00:00Z", gen); got != want {
		t.Fatalf("a future cursor must be clamped to Cloud's clock; got %q, want %q", got, want)
	}
	// Below the bound → untouched, and NOT reformatted: rewriting a value that
	// did not need changing is how serialisations start mixing.
	if got := boundedCursorString("2026-07-31T03:00:00+00:00", gen); got != "2026-07-31T03:00:00+00:00" {
		t.Fatalf("a healthy cursor must be left alone; got %q", got)
	}
	// A Cloud too old to send generated_at keeps its previous behaviour rather
	// than losing its cursor.
	if got := boundedCursorString("2026-07-31T15:00:00Z", ""); got != "2026-07-31T15:00:00Z" {
		t.Fatalf("no generated_at must mean no clamp; got %q", got)
	}
	if got := boundedCursorString("junk", gen); got != "junk" {
		t.Fatalf("unparseable cursor must pass through; got %q", got)
	}
}
