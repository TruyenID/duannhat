package handler

// Regression test for the writeServerError gate.
//
// The previous pattern was
//   writeError(w, http.StatusInternalServerError, err.Error())
// which leaked SQLite errors (including the bound SQL with parameter
// hints) to any LAN client. That's a real information disclosure
// surface for fingerprinting the local replica schema, and not useful
// to a kiosk/POS client anyway. writeServerError replaces it with a
// generic "internal error" body + slog at error level for forensics.

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestWriteServerError_HidesRawError(t *testing.T) {
	rec := httptest.NewRecorder()
	req := httptest.NewRequest("POST", "/api/secret", nil)

	// Simulate a SQLite error that previously leaked verbatim.
	dbErr := errors.New("SQL logic error: no such table: customer_orders (1) in INSERT INTO customer_orders ($1, $2, $3)")
	writeServerError(rec, req, fmt.Errorf("wrap: %w", dbErr))

	if rec.Code != http.StatusInternalServerError {
		t.Errorf("status: got %d, want 500", rec.Code)
	}

	var body map[string]string
	if err := json.NewDecoder(rec.Body).Decode(&body); err != nil {
		t.Fatalf("decode body: %v", err)
	}

	// Envelope key is `message` per plan-028 Phase 5.3 standardization
	// (cloud + workstation both ship `{"message": "..."}` for errors).
	msg := body["message"]
	if msg != "internal error" {
		t.Errorf("body message: got %q, want %q", msg, "internal error")
	}
	if strings.Contains(msg, "SQL") || strings.Contains(msg, "customer_orders") || strings.Contains(msg, "INSERT") {
		t.Errorf("server error leaked DB internals to client: %q", msg)
	}
}
