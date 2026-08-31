package handler

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// Pos-web's Open Shift form reads /pos/staff to populate the cashier
// dropdown. The replica + local handler must keep the Cloud-shape
// envelope (id, name, email, avatar_url) so the existing TS type lines
// up. Missing fields (email/avatar) are NULL in the replica today.

func TestLocalPosStaff_ListsActiveOnly(t *testing.T) {
	srv, db := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	mustExec(t, db, `INSERT INTO staff (id, full_name, is_active) VALUES ('u1','Alice',1)`)
	mustExec(t, db, `INSERT INTO staff (id, full_name, is_active) VALUES ('u2','Bob Disabled',0)`)
	mustExec(t, db, `INSERT INTO staff (id, full_name, is_active) VALUES ('u3','Carol',1)`)

	req := httptest.NewRequest("GET", "/api/v1/pos/staff", nil)
	w := httptest.NewRecorder()
	srv.handlePosStaff(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("want 200, got %d body=%s", w.Code, w.Body.String())
	}
	body := w.Body.String()
	for _, frag := range []string{`"name":"Alice"`, `"name":"Carol"`, `"email":null`, `"avatar_url":null`} {
		if !strings.Contains(body, frag) {
			t.Errorf("missing %q in %s", frag, body)
		}
	}
	if strings.Contains(body, `"name":"Bob Disabled"`) {
		t.Errorf("disabled staff leaked: %s", body)
	}
}

// #88 — /api/v1/pos/staff must not be readable by an arbitrary LAN device.
// Before the fix it was a plain mux.HandleFunc with neither bearer auth nor
// loopback gating, so any phone on the restaurant Wi-Fi could pull the full
// staff-name roster. It now rides posAuth like every sibling /pos/* route:
// a request with no Bearer token gets 401 (no roster leaked), regardless of
// source IP.
func TestLocalPosStaff_RejectsUnauthenticatedLAN(t *testing.T) {
	cloud := mockSSOCloud(t, "user-1", "Alice", "alice@x.com")
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	req := httptest.NewRequest("GET", "/api/v1/pos/staff", nil)
	// Arbitrary LAN client, no Authorization header.
	req.RemoteAddr = "192.168.1.50:54321"
	w := httptest.NewRecorder()
	mux.ServeHTTP(w, req)

	if w.Code != http.StatusUnauthorized {
		t.Fatalf("expected 401 without token, got %d body=%s", w.Code, w.Body.String())
	}
	if strings.Contains(w.Body.String(), `"data"`) {
		t.Errorf("staff roster leaked to unauthenticated caller: %s", w.Body.String())
	}
}

func TestLocalPosStaff_EmptyWhenNoRows(t *testing.T) {
	srv, _ := newServerWithAuth(t, "http://unused")
	srv.hub = NewHub()

	req := httptest.NewRequest("GET", "/api/v1/pos/staff", nil)
	w := httptest.NewRecorder()
	srv.handlePosStaff(w, req)

	if w.Code != http.StatusOK {
		t.Errorf("want 200, got %d", w.Code)
	}
	if !strings.Contains(w.Body.String(), `"data":[]`) {
		t.Errorf("expected empty data array: %s", w.Body.String())
	}
}
