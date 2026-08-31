package service

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

// mockCloud returns a fake Cloud server that responds to GET /api/v1/devices/me
// based on the Authorization header.
func mockCloud(t *testing.T, handler http.HandlerFunc) *httptest.Server {
	t.Helper()
	srv := httptest.NewServer(handler)
	t.Cleanup(srv.Close)
	return srv
}

func TestCloudVerifierSuccess(t *testing.T) {
	srv := mockCloud(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/devices/me" {
			t.Errorf("unexpected path: %s", r.URL.Path)
		}
		if r.Header.Get("Authorization") != "Bearer good-token" {
			t.Errorf("unexpected auth: %s", r.Header.Get("Authorization"))
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"data":{"id":"dev-1","name":"Kiosk 1","type":"kiosk","status":"active","branch_id":"branch-1"}}`))
	})

	v := NewCloudVerifier(func() string { return srv.URL })
	info, err := v.Verify(context.Background(), "good-token")
	if err != nil {
		t.Fatalf("verify: %v", err)
	}
	if info.DeviceID != "dev-1" || info.DeviceType != "kiosk" || info.BranchID != "branch-1" {
		t.Errorf("info mismatch: %+v", info)
	}
	if info.Type != "device" {
		t.Errorf("expected Type=device, got %s", info.Type)
	}
}

func TestCloudVerifierUnauthorized(t *testing.T) {
	srv := mockCloud(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
	})

	v := NewCloudVerifier(func() string { return srv.URL })
	_, err := v.Verify(context.Background(), "bad-token")
	if !errors.Is(err, ErrUnauthorized) {
		t.Errorf("expected ErrUnauthorized, got %v", err)
	}
}

func TestCloudVerifierForbidden(t *testing.T) {
	srv := mockCloud(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusForbidden)
	})

	v := NewCloudVerifier(func() string { return srv.URL })
	_, err := v.Verify(context.Background(), "wrong-scope")
	if !errors.Is(err, ErrUnauthorized) {
		t.Errorf("expected ErrUnauthorized for 403, got %v", err)
	}
}

func TestCloudVerifierUnreachable(t *testing.T) {
	// Point to a closed port
	v := NewCloudVerifier(func() string { return "http://127.0.0.1:1" })
	_, err := v.Verify(context.Background(), "any")
	if !errors.Is(err, ErrCloudUnreachable) {
		t.Errorf("expected ErrCloudUnreachable, got %v", err)
	}
}

func TestCloudVerifierTransientHTTPFailureIsUnavailable(t *testing.T) {
	statuses := []int{
		http.StatusRequestTimeout,
		http.StatusTooEarly,
		http.StatusTooManyRequests,
		http.StatusInternalServerError,
		http.StatusBadGateway,
		http.StatusServiceUnavailable,
		http.StatusGatewayTimeout,
	}
	for _, status := range statuses {
		t.Run(http.StatusText(status), func(t *testing.T) {
			srv := mockCloud(t, func(w http.ResponseWriter, _ *http.Request) {
				http.Error(w, "temporary auth outage", status)
			})

			v := NewCloudVerifier(func() string { return srv.URL })
			_, err := v.Verify(context.Background(), "cached-token")

			if !errors.Is(err, ErrCloudUnreachable) {
				t.Fatalf("HTTP %d = %v, want ErrCloudUnreachable", status, err)
			}
			if errors.Is(err, ErrUnauthorized) {
				t.Fatalf("HTTP %d must not revoke a cached token", status)
			}
		})
	}
}

func TestCloudVerifierContract4xxDoesNotUseStaleClassification(t *testing.T) {
	for _, status := range []int{
		http.StatusBadRequest,
		http.StatusNotFound,
		http.StatusUnprocessableEntity,
	} {
		t.Run(http.StatusText(status), func(t *testing.T) {
			srv := mockCloud(t, func(w http.ResponseWriter, _ *http.Request) {
				http.Error(w, "bad auth contract", status)
			})

			v := NewCloudVerifier(func() string { return srv.URL })
			_, err := v.Verify(context.Background(), "cached-token")

			if err == nil {
				t.Fatalf("HTTP %d unexpectedly succeeded", status)
			}
			if errors.Is(err, ErrCloudUnreachable) || errors.Is(err, ErrUnauthorized) {
				t.Fatalf("HTTP %d got unsafe classification: %v", status, err)
			}
		})
	}
}

func TestCloudVerifierNotConfigured(t *testing.T) {
	v := NewCloudVerifier(func() string { return "" })
	_, err := v.Verify(context.Background(), "any")
	if !errors.Is(err, ErrCloudNotConfig) {
		t.Errorf("expected ErrCloudNotConfig, got %v", err)
	}
}

func TestCloudVerifierSSOToken(t *testing.T) {
	srv := mockCloud(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/me/context" {
			t.Errorf("expected SSO path /me/context, got %s", r.URL.Path)
		}
		if r.Header.Get("Authorization") != "Bearer 5|abc123hash" {
			t.Errorf("unexpected auth: %s", r.Header.Get("Authorization"))
		}
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"user":{"id":"user-1","name":"Alice","email":"alice@x.com","locale":"vi","timezone":"Asia/Ho_Chi_Minh"},"brand_count":2,"shop_count":3}`))
	})

	v := NewCloudVerifier(func() string { return srv.URL })
	identity, err := v.Verify(context.Background(), "5|abc123hash")
	if err != nil {
		t.Fatalf("verify SSO: %v", err)
	}
	if identity.Type != "user" {
		t.Errorf("expected Type=user, got %s", identity.Type)
	}
	if identity.UserID != "user-1" || identity.UserName != "Alice" || identity.UserEmail != "alice@x.com" {
		t.Errorf("SSO identity mismatch: %+v", identity)
	}
	if identity.DeviceID != "" {
		t.Errorf("SSO identity should NOT have DeviceID, got %q", identity.DeviceID)
	}
}

func TestDetectTokenType(t *testing.T) {
	cases := []struct {
		token string
		want  string
	}{
		{"abc123", "device"},
		{"5|abc123hash", "user"},
		{"plain64charstoken00000000000000000000000000000000000000000000000000", "device"},
		{"1|xyz", "user"},
	}
	for _, c := range cases {
		got := detectTokenType(c.token)
		if got != c.want {
			t.Errorf("detectTokenType(%q) = %q, want %q", c.token, got, c.want)
		}
	}
}

func TestCloudVerifierMalformedResponse(t *testing.T) {
	srv := mockCloud(t, func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"data":{}}`))
	})

	v := NewCloudVerifier(func() string { return srv.URL })
	_, err := v.Verify(context.Background(), "any")
	if err == nil || !strings.Contains(err.Error(), "missing device id") {
		t.Errorf("expected malformed response error, got %v", err)
	}
}
