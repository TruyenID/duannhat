package handler

import (
	"bytes"
	"database/sql"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/service"
)

// Regression: pos-web's split-bill payment POST sends `metadata` as a
// nested JSON OBJECT (not a JSON-encoded string). Pre-fix the
// CreatePaymentInput struct typed Metadata as `string`, so the decoder
// rejected the request with
//
//	json: cannot unmarshal object into Go struct field
//	      CreatePaymentInput.metadata of type string
//
// which the cashier saw as the red error under "Khách 1 → Thu" in the
// equal-split dialog. The fix flips Metadata to json.RawMessage so any
// JSON value lands; MetadataString() normalises both the new object
// shape and the legacy quoted-string shape into the single-level JSON
// text the DB column stores.

func setupPaymentTest(t *testing.T, orderID string, total int) (*Server, *sql.DB, string) {
	t.Helper()
	srv, _ := newServerWithAuth(t, "http://unused")
	seedOpenShift(t, srv)
	srv.orders = service.NewOrderEngine(srv.db, 10)
	srv.hub = NewHub()
	o, _ := srv.orders.Create(service.CreateOrderInput{OrderType: "spot"}, nil)
	if _, err := srv.db.Exec(
		`UPDATE orders SET id = ?, total_amount = ?, status = 'checkout' WHERE id = ?`,
		orderID, total, o.ID,
	); err != nil {
		t.Fatal(err)
	}
	// Seed a cash payment method so the handler can resolve method_id.
	if _, err := srv.db.Exec(`
		INSERT INTO payment_methods (id, code, name, is_active, sort_order)
		VALUES ('pm-cash', 'cash', 'Cash', 1, 0)`); err != nil {
		t.Fatal(err)
	}
	return srv, srv.db.Conn(), orderID
}

func TestCreatePayment_AcceptsMetadataAsJSONObject(t *testing.T) {
	srv, _, orderID := setupPaymentTest(t, "o-obj", 4133)

	// Exact body pos-web sends from SplitBillEqualTab → Thu button:
	// metadata is a NESTED OBJECT, not a JSON-encoded string.
	body := `{
		"payment_method_id": "pm-cash",
		"amount": 2067,
		"idempotency_key": "idem-row-0",
		"metadata": {
			"split_mode":   "equal",
			"bill_index":   0,
			"total_bills":  2
		}
	}`

	req := httptest.NewRequest("POST",
		"/api/v1/pos/orders/"+orderID+"/payments",
		bytes.NewReader([]byte(body)))
	req.Header.Set("Idempotency-Key", "idem-row-0")
	req.SetPathValue("id", orderID)
	rec := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(rec, req)

	if rec.Code != http.StatusCreated && rec.Code != http.StatusOK {
		t.Fatalf("status: want 201/200, got %d body=%s", rec.Code, rec.Body.String())
	}
	// Response must include the created payment's id.
	var resp map[string]any
	_ = json.NewDecoder(rec.Body).Decode(&resp)
	data, _ := resp["data"].(map[string]any)
	if data == nil {
		t.Fatalf("response missing data envelope: %v", resp)
	}
	paymentID, _ := data["id"].(string)
	if paymentID == "" {
		t.Fatal("response missing payment id")
	}

	// DB row's metadata column must hold the single-level JSON object,
	// not double-encoded. Decoding it as a map should succeed and have
	// the original keys.
	var meta string
	if err := srv.db.QueryRow(
		`SELECT COALESCE(metadata,'') FROM payments WHERE id = ?`, paymentID,
	).Scan(&meta); err != nil {
		t.Fatalf("readback: %v", err)
	}
	if meta == "" {
		t.Fatal("metadata not persisted")
	}
	var asMap map[string]any
	if err := json.Unmarshal([]byte(meta), &asMap); err != nil {
		t.Fatalf("DB metadata not valid JSON object: %v\nraw=%s", err, meta)
	}
	if asMap["split_mode"] != "equal" {
		t.Errorf("split_mode round-trip: want 'equal', got %v", asMap["split_mode"])
	}
	if int(asMap["bill_index"].(float64)) != 0 {
		t.Errorf("bill_index: want 0, got %v", asMap["bill_index"])
	}
	if int(asMap["total_bills"].(float64)) != 2 {
		t.Errorf("total_bills: want 2, got %v", asMap["total_bills"])
	}
}

func TestCreatePayment_AcceptsMetadataAsLegacyQuotedString(t *testing.T) {
	srv, _, orderID := setupPaymentTest(t, "o-str", 4133)

	// Kiosk + older test fixtures send metadata as a JSON-encoded
	// STRING (the inner payload is itself JSON text). Workstation
	// must still accept it without double-encoding.
	body := `{
		"payment_method_id": "pm-cash",
		"amount": 2067,
		"idempotency_key": "idem-legacy",
		"metadata": "{\"split_mode\":\"equal\",\"bill_index\":0}"
	}`

	req := httptest.NewRequest("POST",
		"/api/v1/pos/orders/"+orderID+"/payments",
		bytes.NewReader([]byte(body)))
	req.Header.Set("Idempotency-Key", "idem-legacy")
	req.SetPathValue("id", orderID)
	rec := httptest.NewRecorder()
	srv.handleLocalPosCreatePayment(rec, req)
	if rec.Code != http.StatusCreated && rec.Code != http.StatusOK {
		t.Fatalf("status: %d body=%s", rec.Code, rec.Body.String())
	}

	var resp map[string]any
	_ = json.NewDecoder(rec.Body).Decode(&resp)
	paymentID := resp["data"].(map[string]any)["id"].(string)

	var meta string
	_ = srv.db.QueryRow(
		`SELECT COALESCE(metadata,'') FROM payments WHERE id = ?`, paymentID,
	).Scan(&meta)
	// Single-level: must start with '{' not '"'.
	if !strings.HasPrefix(meta, "{") {
		t.Errorf("legacy quoted-string metadata must be unwrapped on store; got %q", meta)
	}
}
