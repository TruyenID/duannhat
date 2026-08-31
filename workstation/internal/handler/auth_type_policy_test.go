package handler

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/dxs-platform/workstation-app/internal/domain"
)

func TestAuthPolicy_Permits(t *testing.T) {
	kioskOnly := authPolicy{allowDevice: map[string]bool{"kiosk": true}}
	tests := []struct {
		name         string
		policy       authPolicy
		identityType string
		deviceType   string
		want         bool
	}{
		{"posWeb accepts SSO user", policyPosWeb, domain.IdentityTypeUser, "user", true},
		{"posWeb accepts pos device (paired pos-web)", policyPosWeb, domain.IdentityTypeDevice, "pos", true},
		{"posWeb rejects kiosk device", policyPosWeb, domain.IdentityTypeDevice, "kiosk", false},
		{"posWeb rejects workstation device", policyPosWeb, domain.IdentityTypeDevice, "workstation", false},
		{"device-set accepts listed", kioskOnly, domain.IdentityTypeDevice, "kiosk", true},
		{"device-set rejects unlisted", kioskOnly, domain.IdentityTypeDevice, "pos", false},
		{"device-set rejects user", kioskOnly, domain.IdentityTypeUser, "user", false},
		{"anyDevice accepts any device", authPolicy{allowAnyDevice: true}, domain.IdentityTypeDevice, "pos", true},
		{"anyDevice rejects user without allowUser", authPolicy{allowAnyDevice: true}, domain.IdentityTypeUser, "user", false},
		{"zero value rejects user", authPolicy{}, domain.IdentityTypeUser, "user", false},
		{"zero value rejects device", authPolicy{}, domain.IdentityTypeDevice, "kiosk", false},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := tt.policy.permits(tt.identityType, tt.deviceType); got != tt.want {
				t.Errorf("permits(%q,%q) = %v, want %v", tt.identityType, tt.deviceType, got, tt.want)
			}
		})
	}
}

// TestRequireType_Enforcement drives the wrapper directly with an injected
// DeviceContext (the shape AuthMiddleware.Wrap installs) so no cloud is needed.
func TestRequireType_Enforcement(t *testing.T) {
	s := &Server{}

	run := func(dc *DeviceContext) (int, bool) {
		called := false
		next := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			called = true
			w.WriteHeader(http.StatusOK)
		})
		req := httptest.NewRequest("GET", "/api/v1/pos/me", nil)
		if dc != nil {
			req = req.WithContext(context.WithValue(req.Context(), deviceCtxKey, dc))
		}
		w := httptest.NewRecorder()
		s.requireType(policyPosWeb, next).ServeHTTP(w, req)
		return w.Code, called
	}

	t.Run("SSO user passes posWeb", func(t *testing.T) {
		code, called := run(&DeviceContext{IdentityType: domain.IdentityTypeUser, Type: domain.IdentityTypeUser})
		if code != http.StatusOK || !called {
			t.Fatalf("want 200+next, got code=%d called=%v", code, called)
		}
	})
	t.Run("paired POS device passes posWeb", func(t *testing.T) {
		// The fix: pos-web authenticates as a paired `pos` device token (parity
		// with Cloud's auth.sso_or_device) — must reach the handler, not 403.
		code, called := run(&DeviceContext{IdentityType: domain.IdentityTypeDevice, Type: "pos"})
		if code != http.StatusOK || !called {
			t.Fatalf("want 200+next for pos device, got code=%d called=%v", code, called)
		}
	})
	t.Run("kiosk device rejected by posWeb", func(t *testing.T) {
		code, called := run(&DeviceContext{IdentityType: domain.IdentityTypeDevice, Type: "kiosk"})
		if code != http.StatusForbidden || called {
			t.Fatalf("want 403 + no next for kiosk, got code=%d called=%v", code, called)
		}
	})
	t.Run("missing context fails closed", func(t *testing.T) {
		code, called := run(nil)
		if code != http.StatusForbidden || called {
			t.Fatalf("want 403 + no next, got code=%d called=%v", code, called)
		}
	})
}

// mockDeviceMeCloud is a fake Cloud that verifies a plain (device) token via
// GET /api/v1/devices/me, returning the given device type + branch.
func mockDeviceMeCloud(t *testing.T, deviceType, branchID string) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"id":"dev-1","type":"` + deviceType + `","branch_id":"` + branchID + `","status":"active"}}`))
	}))
	t.Cleanup(srv.Close)
	return srv
}

// TestPosMe_RejectsDeviceToken is the end-to-end guard for the reported bug: a
// same-branch POS *device* token (Cloud-valid, branch matches) must NOT
// authenticate against /pos/*, which is SSO-user-only.
// A paired `pos` device token (pos-web's auth — parity with Cloud auth.sso_or_device)
// must be ADMITTED past the /pos/* type gate; a kiosk device token must still be
// rejected (per-surface isolation). Regression for the LAN "Không tải được ca làm
// việc / Thiết bị không có quyền truy cập" 403 where policyUserOnly rejected pos-web's
// own device token even though Cloud accepted it.
func TestPosMe_AdmitsPosDevice_RejectsKioskDevice(t *testing.T) {
	t.Run("pos device admitted (not 403 at the gate)", func(t *testing.T) {
		cloud := mockDeviceMeCloud(t, "pos", "branch-A")
		s, _ := newServerWithAuth(t, cloud.URL)
		mux := http.NewServeMux()
		s.registerLocalReplicaRoutes(mux)

		req := httptest.NewRequest("GET", "/api/v1/pos/me", nil)
		req.Header.Set("Authorization", "Bearer pos-device-token")
		rec := httptest.NewRecorder()
		mux.ServeHTTP(rec, req)

		if rec.Code == http.StatusForbidden {
			t.Fatalf("pos device token must pass the /pos/me type gate, got 403 body=%s", rec.Body.String())
		}
	})

	t.Run("kiosk device still rejected", func(t *testing.T) {
		cloud := mockDeviceMeCloud(t, "kiosk", "branch-A")
		s, _ := newServerWithAuth(t, cloud.URL)
		mux := http.NewServeMux()
		s.registerLocalReplicaRoutes(mux)

		req := httptest.NewRequest("GET", "/api/v1/pos/me", nil)
		req.Header.Set("Authorization", "Bearer kiosk-device-token")
		rec := httptest.NewRecorder()
		mux.ServeHTTP(rec, req)

		if rec.Code != http.StatusForbidden {
			t.Fatalf("kiosk device token must be 403 on /pos/me, got %d body=%s", rec.Code, rec.Body.String())
		}
	})
}

// TestPerSurface_RejectsWrongDeviceType is the Phase-2 "type → surface" guard: a
// same-branch POS device token (Cloud-valid) must be 403 on every non-POS device
// surface. The 403 comes from requireType, before the handler runs — so no
// per-endpoint DB fixtures are needed.
func TestPerSurface_RejectsWrongDeviceType(t *testing.T) {
	cloud := mockDeviceMeCloud(t, "pos", "branch-A") // pos token: wrong for all below
	s, _ := newServerWithAuth(t, cloud.URL)

	mux := http.NewServeMux()
	s.registerLocalReplicaRoutes(mux)

	for _, path := range []string{
		"/api/v1/kiosk/me",
		"/api/v1/tms/zones",
		"/api/v1/kds/me",
		"/api/v1/handy/me",
	} {
		req := httptest.NewRequest("GET", path, nil)
		req.Header.Set("Authorization", "Bearer pos-token")
		rec := httptest.NewRecorder()
		mux.ServeHTTP(rec, req)
		if rec.Code != http.StatusForbidden {
			t.Errorf("%s with pos token: want 403, got %d body=%s", path, rec.Code, rec.Body.String())
		}
	}
}
