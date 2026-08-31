package handler

// #149 — GET /api/orders gained a third board. The Wails Orders page reads
// `?status=cancelled` for its "Đã huỷ" tab; the dashboard counters must stop
// treating a Cloud-expired takeaway as live money.

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"testing"
	"time"

	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/dxs-platform/workstation-app/internal/store/storetest"
)

func newOrdersListServer(t *testing.T) *Server {
	t.Helper()
	db, err := storetest.Open(filepath.Join(t.TempDir(), "orders.db"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return &Server{db: db, orders: service.NewOrderEngine(db)}
}

func seedListOrder(t *testing.T, s *Server, id, status string, openedAt time.Time) {
	t.Helper()
	ts := openedAt.UTC().Format(time.RFC3339)
	if _, err := s.db.Exec(`
		INSERT INTO orders (id, order_code, order_type, status, opened_at,
		    subtotal, discount_amount, total_amount, paid_amount,
		    created_at, updated_at)
		VALUES (?, ?, 'takeaway', ?, ?, 1000, 0, 1000, 0, ?, ?)`,
		id, "T-"+id, status, ts, ts, ts); err != nil {
		t.Fatalf("seed %s order: %v", status, err)
	}
}

func listOrderIDs(t *testing.T, s *Server, query string) []string {
	t.Helper()
	rec := httptest.NewRecorder()
	s.handleListOrders(rec, httptest.NewRequest(http.MethodGet, "/api/orders"+query, nil))
	if rec.Code != http.StatusOK {
		t.Fatalf("GET /api/orders%s = %d, body %s", query, rec.Code, rec.Body.String())
	}
	var body struct {
		Orders []struct {
			ID string `json:"id"`
		} `json:"orders"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &body); err != nil {
		t.Fatalf("decode: %v (body %s)", err, rec.Body.String())
	}
	ids := make([]string, 0, len(body.Orders))
	for _, o := range body.Orders {
		ids = append(ids, o.ID)
	}
	return ids
}

func TestHandleListOrders_ThreeBoards(t *testing.T) {
	s := newOrdersListServer(t)
	now := time.Now().UTC()
	seedListOrder(t, s, "live", "open", now)
	seedListOrder(t, s, "paid", "closed", now)
	seedListOrder(t, s, "void", "voided", now.Add(-2*time.Hour))
	seedListOrder(t, s, "gone", "expired", now.Add(-time.Hour))

	if got := listOrderIDs(t, s, ""); len(got) != 1 || got[0] != "live" {
		t.Fatalf("active board = %v, want [live] — an expired takeaway is not live", got)
	}
	if got := listOrderIDs(t, s, "?status=closed"); len(got) != 1 || got[0] != "paid" {
		t.Fatalf("paid board = %v, want [paid]", got)
	}
	// Newest-first by updated_at: the expiry is an hour after the void.
	got := listOrderIDs(t, s, "?status=cancelled")
	if len(got) != 2 || got[0] != "gone" || got[1] != "void" {
		t.Fatalf("cancelled board = %v, want [gone void]", got)
	}
}

// An unknown ?status= is not an error — it falls back to the active board,
// the same way it did when `closed` was the only recognised value.
func TestHandleListOrders_UnknownStatusFallsBackToActive(t *testing.T) {
	s := newOrdersListServer(t)
	now := time.Now().UTC()
	seedListOrder(t, s, "live", "open", now)
	seedListOrder(t, s, "gone", "expired", now)

	if got := listOrderIDs(t, s, "?status=banana"); len(got) != 1 || got[0] != "live" {
		t.Fatalf("unknown status = %v, want the active board [live]", got)
	}
}

func TestDashboardAndReport_TreatExpiredAsCancelled(t *testing.T) {
	s := newOrdersListServer(t)
	now := time.Now().UTC()
	seedListOrder(t, s, "live", "open", now)
	seedListOrder(t, s, "paid", "closed", now)
	seedListOrder(t, s, "void", "voided", now)
	seedListOrder(t, s, "gone", "expired", now)

	var activeOrders int
	if err := s.db.QueryRow(
		"SELECT COUNT(*) FROM orders WHERE status NOT IN " + service.SQLStatusTerminal,
	).Scan(&activeOrders); err != nil {
		t.Fatal(err)
	}
	if activeOrders != 1 {
		t.Fatalf("dashboard active_orders = %d, want 1", activeOrders)
	}

	// Today's revenue counts neither cancellation — the expired order never
	// took a yen, which is precisely why Cloud expired it.
	var todayOrders, todayRevenue int
	if err := s.db.QueryRow(
		"SELECT COUNT(*), COALESCE(SUM(total_amount), 0) FROM orders WHERE status NOT IN "+service.SQLStatusCancelled,
	).Scan(&todayOrders, &todayRevenue); err != nil {
		t.Fatal(err)
	}
	if todayOrders != 2 || todayRevenue != 2000 {
		t.Fatalf("today = %d orders / %d revenue, want 2 / 2000", todayOrders, todayRevenue)
	}

	var cancelled int
	if err := s.db.QueryRow(
		"SELECT COUNT(*) FROM orders WHERE status IN " + service.SQLStatusCancelled,
	).Scan(&cancelled); err != nil {
		t.Fatal(err)
	}
	if cancelled != 2 {
		t.Fatalf("report cancelled_orders = %d, want 2 (voided + expired)", cancelled)
	}
}
