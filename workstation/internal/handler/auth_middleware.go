package handler

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"strings"
	"sync"
	"sync/atomic"
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

const (
	// POS gives a workstation request three seconds. Auth is only the gate in
	// front of the real handler, so it gets a deliberately smaller budget.
	// Misses fail quickly; expired entries may use the bounded stale grace below.
	authVerificationTimeout = time.Second
	authStaleGrace          = time.Hour
)

var (
	errAuthBranchMismatch   = errors.New("device branch mismatch")
	errWorkstationNotPaired = errors.New("workstation is not paired")
)

type authVerifyCall struct {
	done     chan struct{}
	identity *service.Identity
	err      error
}

// AuthMetricsSnapshot is safe to expose in local diagnostics. It contains no
// token, user or device identifiers.
type AuthMetricsSnapshot struct {
	FreshHits         uint64 `json:"fresh_hits"`
	StaleCandidates   uint64 `json:"stale_candidates"`
	StaleFallbacks    uint64 `json:"stale_fallbacks"`
	CacheMisses       uint64 `json:"cache_misses"`
	UpstreamCalls     uint64 `json:"upstream_calls"`
	SharedWaiters     uint64 `json:"shared_waiters"`
	Unauthorized      uint64 `json:"unauthorized"`
	LastUpstreamNanos int64  `json:"last_upstream_nanos"`
}

type authMetrics struct {
	freshHits         atomic.Uint64
	staleCandidates   atomic.Uint64
	staleFallbacks    atomic.Uint64
	cacheMisses       atomic.Uint64
	upstreamCalls     atomic.Uint64
	sharedWaiters     atomic.Uint64
	unauthorized      atomic.Uint64
	lastUpstreamNanos atomic.Int64
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

	flightMu sync.Mutex
	inFlight map[string]*authVerifyCall // token hash -> one bounded Cloud verify
	metrics  authMetrics
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
		inFlight:   make(map[string]*authVerifyCall),
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
		inFlight:   make(map[string]*authVerifyCall),
	}
}

func (m *AuthMiddleware) Metrics() AuthMetricsSnapshot {
	return AuthMetricsSnapshot{
		FreshHits:         m.metrics.freshHits.Load(),
		StaleCandidates:   m.metrics.staleCandidates.Load(),
		StaleFallbacks:    m.metrics.staleFallbacks.Load(),
		CacheMisses:       m.metrics.cacheMisses.Load(),
		UpstreamCalls:     m.metrics.upstreamCalls.Load(),
		SharedWaiters:     m.metrics.sharedWaiters.Load(),
		Unauthorized:      m.metrics.unauthorized.Load(),
		LastUpstreamNanos: m.metrics.lastUpstreamNanos.Load(),
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
	// Keep the same fail-closed rule for HTTP and WebSocket. In particular, a
	// never-paired workstation must not relay a LAN token to the default Cloud.
	if m.branchIDFn() == "" {
		return nil, errWorkstationNotPaired
	}

	hash := service.HashToken(token)
	entry, fresh, stale := m.cache.Get(hash)

	if fresh {
		m.metrics.freshHits.Add(1)
		if !m.branchOKForCache(entry) {
			return nil, errAuthBranchMismatch
		}
		id := identityFromCache(entry)
		if m.seen != nil && entry.IdentityType == domain.IdentityTypeDevice {
			m.seen.Touch(entry.DeviceID, time.Now().UTC())
		}
		return &AuthResult{Identity: id, Stale: false}, nil
	}

	if stale && entry != nil {
		m.metrics.staleCandidates.Add(1)
	} else {
		m.metrics.cacheMisses.Add(1)
	}

	identity, err := m.verifyCloudShared(ctx, hash, token)
	if err == nil {
		return &AuthResult{Identity: identity, Stale: false}, nil
	}

	if errors.Is(err, service.ErrUnauthorized) {
		return nil, service.ErrUnauthorized
	}

	// Cloud unreachable + a recently-expired entry → degraded but accept. The
	// one-hour bound matches the cleanup policy explicitly instead of relying on
	// its ten-minute sweep timing. Reachable Cloud revocation is still checked
	// before this path, within the one-second foreground budget.
	if errors.Is(err, service.ErrCloudUnreachable) && staleCacheUsable(entry) {
		if !m.branchOKForCache(entry) {
			return nil, errAuthBranchMismatch
		}
		m.metrics.staleFallbacks.Add(1)
		return &AuthResult{Identity: identityFromCache(entry), Stale: true}, nil
	}

	return nil, err
}

func staleCacheUsable(entry *domain.AuthTokenCache) bool {
	if entry == nil || entry.ExpiresAt.IsZero() {
		return false
	}
	age := time.Since(entry.ExpiresAt)
	return age >= 0 && age <= authStaleGrace
}

// verifyCloudShared deduplicates token verification across HTTP requests and
// WebSocket handshakes. The verifier owns a bounded background context so the
// first browser to disconnect cannot cancel the single result every waiter is
// relying on; individual waiters may still leave immediately via their ctx.
func (m *AuthMiddleware) verifyCloudShared(ctx context.Context, hash, token string) (*service.Identity, error) {
	m.flightMu.Lock()
	call, exists := m.inFlight[hash]
	if exists {
		m.metrics.sharedWaiters.Add(1)
		m.flightMu.Unlock()
	} else {
		call = &authVerifyCall{done: make(chan struct{})}
		m.inFlight[hash] = call
		m.flightMu.Unlock()
		go m.runCloudVerification(hash, token, call)
	}

	select {
	case <-ctx.Done():
		return nil, ctx.Err()
	case <-call.done:
		return call.identity, call.err
	}
}

func (m *AuthMiddleware) runCloudVerification(hash, token string, call *authVerifyCall) {
	started := time.Now()
	m.metrics.upstreamCalls.Add(1)
	ctx, cancel := context.WithTimeout(context.Background(), authVerificationTimeout)
	identity, err := m.verifier.Verify(ctx, token)
	deadlineErr := ctx.Err()
	cancel()
	if deadlineErr == context.DeadlineExceeded && !errors.Is(err, service.ErrUnauthorized) {
		err = fmt.Errorf("%w: auth verification exceeded %s", service.ErrCloudUnreachable, authVerificationTimeout)
	}
	m.metrics.lastUpstreamNanos.Store(time.Since(started).Nanoseconds())

	if err == nil && !m.branchOKForIdentity(identity) {
		err = errAuthBranchMismatch
	}
	if err == nil {
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
	} else if errors.Is(err, service.ErrUnauthorized) {
		m.metrics.unauthorized.Add(1)
		_ = m.cache.Delete(hash)
	}

	slog.Debug("auth Cloud verification",
		"duration_ms", time.Since(started).Milliseconds(),
		"success", err == nil,
		"unauthorized", errors.Is(err, service.ErrUnauthorized),
	)

	m.flightMu.Lock()
	call.identity = identity
	call.err = err
	delete(m.inFlight, hash)
	close(call.done)
	m.flightMu.Unlock()
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

		// #2442 — refuse BEFORE the outbound verify when this workstation has
		// no branch of its own.
		//
		// branchOK() already fail-closes on an empty workstation_branch_id, but
		// it ran AFTER m.verifier.Verify(), so an unpaired workstation shipped
		// every LAN client's bearer token to whatever cloudAPIURL() resolved —
		// and since #2431 gave that a config fallback, "whatever" is the
		// compiled-in default https://tempo.godx.jp. A machine with no
		// relationship to production was handing production other people's
		// device tokens, purely to be told 403 a moment later.
		//
		// The outcome for the caller is unchanged in kind (the request was
		// always going to be refused); what changes is that the token stays on
		// the LAN, and the reason is now honest.
		result, err := m.VerifyToken(r.Context(), token)
		if err != nil {
			if errors.Is(err, errWorkstationNotPaired) {
				writeWorkstationNotPaired(w)
				return
			}
			if errors.Is(err, errAuthBranchMismatch) {
				writeBranchMismatch(w)
				return
			}
			if errors.Is(err, service.ErrUnauthorized) {
				writeError(w, http.StatusUnauthorized, "invalid token")
				return
			}
			// No cache + Cloud unreachable, or other transient error.
			slog.Warn("auth verify failed", "err", err)
			writeError(w, http.StatusServiceUnavailable, "auth verification unavailable")
			return
		}

		if result.Stale {
			w.Header().Set("X-Auth-Stale", "true")
		}
		next.ServeHTTP(w, m.withIdentity(r, result.Identity))
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

// writeWorkstationNotPaired reports a WORKSTATION-side configuration gap, and
// deliberately does NOT reuse BRANCH_MISMATCH.
//
// BRANCH_MISMATCH means "this device belongs to another shop" — pos-web clears
// the session on it, which is right, because no amount of retrying fixes a
// device pointed at the wrong branch. An unpaired workstation is the opposite
// kind of problem: the POS device may be paired perfectly well, and the fix is
// for someone to pair the WORKSTATION in the ws-app window. Wiping the till's
// session for that would destroy a good pairing over a gap it did not cause —
// the same failure shape as the "invalid token" prose sniffing removed in
// da1f101ef. 503 says retryable, and pos-web leaves the session alone.
func writeWorkstationNotPaired(w http.ResponseWriter) {
	writeJSON(w, http.StatusServiceUnavailable, map[string]string{
		"message": "workstation is not paired",
		"code":    "WORKSTATION_NOT_PAIRED",
	})
}

// writeBranchMismatch returns 403 with a stable code so pos-web can clear the
// session immediately (same contract as Cloud BRANCH_MISMATCH).
func writeBranchMismatch(w http.ResponseWriter) {
	writeJSON(w, http.StatusForbidden, map[string]string{
		"message": "device branch mismatch",
		"code":    "BRANCH_MISMATCH",
	})
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
