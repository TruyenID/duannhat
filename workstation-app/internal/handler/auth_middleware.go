package handler

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/domain"
	"github.com/dxs-platform/workstation-app/internal/service"
)

// DeviceContext is the authenticated identity set into request context by
// AuthMiddleware. Despite the name (kept for backward compat), it covers both
// device tokens (kiosk/tms/workstation) and SSO user tokens (pos-web).
//
// For device tokens: ID = device.id, Type = device.type ("kiosk"/"tms"/...).
// For SSO tokens:    ID = user.id,   Type = "user", UserName/UserEmail populated.
type DeviceContext struct {
	ID           string // device.id OR user.id depending on IdentityType
	Type         string // for devices: kiosk/tms/workstation. For users: "user"
	BranchID     string // device.branch_id; empty for SSO users (deferred to Cloud)
	IdentityType string // "device" | "user"
	UserName     string // SSO only
	UserEmail    string // SSO only
}

type contextKey string

const deviceCtxKey contextKey = "ws.device"

// DeviceFromContext returns the authenticated identity, or nil if the request
// didn't go through AuthMiddleware. Despite the function name, returns both
// device and SSO-user identities — check IdentityType to distinguish.
func DeviceFromContext(ctx context.Context) (*DeviceContext, bool) {
	d, ok := ctx.Value(deviceCtxKey).(*DeviceContext)
	return d, ok && d != nil
}

// tokenVerifier is the subset of CloudVerifier the middleware needs.
// Declared as an interface so tests can stub Cloud reachability /
// errors without spinning up an httptest server. CloudVerifier
// satisfies it via its existing Verify method.
type tokenVerifier interface {
	Verify(ctx context.Context, token string) (*service.Identity, error)
}

// AuthMiddleware enforces Bearer-token auth for local kiosk/tms/customer endpoints.
// On cache hit: serve directly without touching Cloud.
// On miss/expired: forward verify to Cloud. Cache result for cache.ttl.
// On Cloud unreachable + stale cache: serve with X-Auth-Stale: true.
// On Cloud unreachable + no cache: 503 Service Unavailable.
type AuthMiddleware struct {
	cache      *service.AuthCacheStore
	verifier   tokenVerifier
	branchIDFn func() string // workstation's own branch_id, for cross-branch protection
	seen       *service.DeviceSeenBuffer
}

func NewAuthMiddleware(
	cache *service.AuthCacheStore,
	verifier *service.CloudVerifier,
	branchIDFn func() string,
	seen *service.DeviceSeenBuffer,
) *AuthMiddleware {
	return &AuthMiddleware{
		cache:      cache,
		verifier:   verifier,
		branchIDFn: branchIDFn,
		seen:       seen,
	}
}

// newAuthMiddlewareWithVerifier is the test-only constructor that
// accepts any tokenVerifier (production wiring goes through
// NewAuthMiddleware with the concrete CloudVerifier).
func newAuthMiddlewareWithVerifier(
	cache *service.AuthCacheStore,
	verifier tokenVerifier,
	branchIDFn func() string,
	seen *service.DeviceSeenBuffer,
) *AuthMiddleware {
	return &AuthMiddleware{
		cache:      cache,
		verifier:   verifier,
		branchIDFn: branchIDFn,
		seen:       seen,
	}
}

// AuthResult is what VerifyToken returns. Identity is the resolved
// device/user; Stale signals the entry came from the offline-tolerance
// cache path (the caller should communicate degraded mode to its
// client where possible).
type AuthResult struct {
	Identity *service.Identity
	Stale    bool
}

// VerifyToken runs the same fresh → cloud → stale-fallback ladder the
// HTTP middleware uses, but as a plain function so the WebSocket
// handshake can share the same offline-tolerance semantics. Without
// this the WS handshake (ws.go) called verifier.Verify directly and a
// Cloud outage cut every LAN realtime channel — defeating the
// workstation's whole offline-first story.
func (m *AuthMiddleware) VerifyToken(ctx context.Context, token string) (*AuthResult, error) {
	hash := service.HashToken(token)
	entry, fresh, stale := m.cache.Get(hash)

	if fresh {
		if !m.branchOKForCache(entry) {
			return nil, errors.New("device branch mismatch")
		}
		id := identityFromCache(entry)
		if m.seen != nil && entry.IdentityType == domain.IdentityTypeDevice {
			m.seen.Touch(entry.DeviceID, time.Now().UTC())
		}
		return &AuthResult{Identity: id, Stale: false}, nil
	}

	identity, err := m.verifier.Verify(ctx, token)
	if err == nil {
		if !m.branchOKForIdentity(identity) {
			return nil, errors.New("device branch mismatch")
		}
		if putErr := m.cache.PutIdentity(hash, identity, ""); putErr != nil {
			slog.Error("auth cache put", "err", putErr)
		}
		if m.seen != nil && identity.Type == domain.IdentityTypeDevice {
			if regErr := m.seen.Register(service.DeviceInfo{
				ID:             identity.DeviceID,
				Type:           identity.DeviceType,
				BranchID:       identity.BranchID,
				OrganizationID: identity.OrganizationID,
			}); regErr != nil {
				slog.Warn("auth device register", "err", regErr, "device_id", identity.DeviceID)
			}
			m.seen.Touch(identity.DeviceID, time.Now().UTC())
		}
		return &AuthResult{Identity: identity, Stale: false}, nil
	}

	if errors.Is(err, service.ErrUnauthorized) {
		_ = m.cache.Delete(hash)
		return nil, service.ErrUnauthorized
	}

	// Cloud unreachable + stale cache → degraded but accept.
	if errors.Is(err, service.ErrCloudUnreachable) && stale && entry != nil {
		if !m.branchOKForCache(entry) {
			return nil, errors.New("device branch mismatch")
		}
		return &AuthResult{Identity: identityFromCache(entry), Stale: true}, nil
	}

	return nil, err
}

// identityFromCache rebuilds a service.Identity from a cached entry so
// both VerifyToken and the HTTP middleware share the same shape.
// OrganizationID isn't carried in the cache (yet) — callers that need
// it must re-verify; for the WS handshake path branch+device is enough.
func identityFromCache(e *domain.AuthTokenCache) *service.Identity {
	id := &service.Identity{
		Type:      e.IdentityType,
		BranchID:  e.BranchID,
		UserName:  e.UserName,
		UserEmail: e.UserEmail,
	}
	if e.IdentityType == domain.IdentityTypeUser {
		id.UserID = e.UserID
	} else {
		id.DeviceID = e.DeviceID
		id.DeviceType = e.DeviceType
	}
	return id
}

func (m *AuthMiddleware) Wrap(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		token := extractBearer(r)
		if token == "" {
			writeError(w, http.StatusUnauthorized, "missing bearer token")
			return
		}

		hash := service.HashToken(token)
		entry, fresh, stale := m.cache.Get(hash)

		// Fast path: cache hit + not expired
		if fresh {
			if !m.branchOKForCache(entry) {
				writeError(w, http.StatusForbidden, "device branch mismatch")
				return
			}
			if m.seen != nil && entry.IdentityType == domain.IdentityTypeDevice {
				m.seen.Touch(entry.DeviceID, time.Now().UTC())
			}
			next.ServeHTTP(w, m.withCacheEntry(r, entry))
			return
		}

		// Cache miss or stale → ask Cloud
		identity, err := m.verifier.Verify(r.Context(), token)
		if err == nil {
			// Cloud verified
			if !m.branchOKForIdentity(identity) {
				writeError(w, http.StatusForbidden, "device branch mismatch")
				return
			}
			if putErr := m.cache.PutIdentity(hash, identity, ""); putErr != nil {
				slog.Error("auth cache put", "err", putErr)
			}
			if m.seen != nil && identity.Type == domain.IdentityTypeDevice {
				// Populate local devices table so Touch has a row to UPDATE.
				if regErr := m.seen.Register(service.DeviceInfo{
					ID:             identity.DeviceID,
					Type:           identity.DeviceType,
					BranchID:       identity.BranchID,
					OrganizationID: identity.OrganizationID,
				}); regErr != nil {
					slog.Warn("auth device register", "err", regErr, "device_id", identity.DeviceID)
				}
				m.seen.Touch(identity.DeviceID, time.Now().UTC())
			}
			next.ServeHTTP(w, m.withIdentity(r, identity))
			return
		}

		// Cloud rejected the token → make sure we don't have a stale entry
		if errors.Is(err, service.ErrUnauthorized) {
			_ = m.cache.Delete(hash)
			writeError(w, http.StatusUnauthorized, "invalid token")
			return
		}

		// Cloud unreachable + we have a stale (expired) entry → degraded mode
		if errors.Is(err, service.ErrCloudUnreachable) && stale && entry != nil {
			if !m.branchOKForCache(entry) {
				writeError(w, http.StatusForbidden, "device branch mismatch")
				return
			}
			w.Header().Set("X-Auth-Stale", "true")
			next.ServeHTTP(w, m.withCacheEntry(r, entry))
			return
		}

		// No cache + Cloud unreachable, or other transient error
		slog.Warn("auth verify failed", "err", err)
		writeError(w, http.StatusServiceUnavailable, "auth verification unavailable")
	})
}

func (m *AuthMiddleware) withIdentity(r *http.Request, id *service.Identity) *http.Request {
	d := &DeviceContext{
		IdentityType: id.Type,
		BranchID:     id.BranchID,
		UserName:     id.UserName,
		UserEmail:    id.UserEmail,
	}
	if id.Type == domain.IdentityTypeUser {
		d.ID = id.UserID
		d.Type = domain.IdentityTypeUser
	} else {
		d.ID = id.DeviceID
		d.Type = id.DeviceType
	}
	return r.WithContext(context.WithValue(r.Context(), deviceCtxKey, d))
}

func (m *AuthMiddleware) withCacheEntry(r *http.Request, e *domain.AuthTokenCache) *http.Request {
	d := &DeviceContext{
		IdentityType: e.IdentityType,
		BranchID:     e.BranchID,
		UserName:     e.UserName,
		UserEmail:    e.UserEmail,
	}
	if e.IdentityType == domain.IdentityTypeUser {
		d.ID = e.UserID
		d.Type = domain.IdentityTypeUser
	} else {
		d.ID = e.DeviceID
		d.Type = e.DeviceType
	}
	return r.WithContext(context.WithValue(r.Context(), deviceCtxKey, d))
}

// branchOK enforces branch isolation between LAN device and workstation.
// Fail-close: empty workstation branch (not paired or unpaired) → reject all
// LAN auth; empty device branch (Cloud schema bug) → reject. Sprint 3 (S3.6b)
// upgrade from "fail-open skip" to "fail-close deny" because the open mode
// silently allowed cross-branch access whenever pair flow hadn't persisted
// workstation_branch_id (which happened pre-Sprint 1 bug-03 fix).
func (m *AuthMiddleware) branchOK(deviceBranch string) bool {
	wsBranch := m.branchIDFn()
	if wsBranch == "" {
		slog.Warn("branchOK fail-close: workstation_branch_id empty (not paired?)")
		return false
	}
	if deviceBranch == "" {
		slog.Warn("branchOK fail-close: device has empty branch_id", "ws_branch", wsBranch)
		return false
	}
	return wsBranch == deviceBranch
}

// branchOKForIdentity routes per identity type:
//   - device: enforce device.branch_id == workstation_branch_id (existing rule)
//   - user (SSO): workstation must be paired (workstation_branch_id non-empty);
//     branch authorization is delegated to Cloud at sync-UP time. This is a
//     demo-grade simplification — /me/context doesn't expose user→branches
//     mapping, so per-request branch enforcement would need an extra Cloud
//     call. Production should add proper authorization (e.g. check
//     /me/shops for workstation's branch slug, or extend the verify endpoint
//     to return branch_ids the user can access).
func (m *AuthMiddleware) branchOKForIdentity(id *service.Identity) bool {
	if id.Type == domain.IdentityTypeUser {
		wsBranch := m.branchIDFn()
		if wsBranch == "" {
			slog.Warn("branchOK fail-close: workstation not paired, rejecting SSO user")
			return false
		}
		return true
	}
	return m.branchOK(id.BranchID)
}

// branchOKForCache mirrors branchOKForIdentity for cached entries.
func (m *AuthMiddleware) branchOKForCache(e *domain.AuthTokenCache) bool {
	if e.IdentityType == domain.IdentityTypeUser {
		wsBranch := m.branchIDFn()
		if wsBranch == "" {
			slog.Warn("branchOK fail-close: workstation not paired, rejecting cached SSO user")
			return false
		}
		return true
	}
	return m.branchOK(e.BranchID)
}

func extractBearer(r *http.Request) string {
	h := r.Header.Get("Authorization")
	if h == "" {
		return ""
	}
	const prefix = "bearer "
	if len(h) < len(prefix) || !strings.EqualFold(h[:len(prefix)], prefix) {
		return ""
	}
	return strings.TrimSpace(h[len(prefix):])
}
