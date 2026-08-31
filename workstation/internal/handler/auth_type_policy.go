package handler

import (
	"log/slog"
	"net/http"

	"github.com/dxs-platform/workstation-app/internal/domain"
)

// authPolicy declares which authenticated identities a route accepts, keyed by
// the identity discriminator (device vs SSO user) and — for devices — the
// device type.
//
// AuthMiddleware verifies a Bearer token against Cloud and enforces branch
// isolation, but it historically never checked the *type* of the resolved
// identity: any Cloud-valid, same-branch device token (kiosk/tms/pos/…) was
// accepted on every authed endpoint. That let a wrong-type token — e.g. a POS
// device token — authenticate against surfaces meant for another client type.
// authPolicy + requireType is the missing type gate.
//
// The zero value permits nothing (fail-closed): a route wrapped with an empty
// policy rejects every identity.
type authPolicy struct {
	allowUser      bool            // accept SSO user identities (IdentityType == "user")
	allowDevice    map[string]bool // accepted device types, e.g. {"kiosk": true}
	allowAnyDevice bool            // accept any device type (identity-agnostic routes)
}

// permits reports whether an identity is allowed. identityType is the
// discriminator ("device" | "user"); deviceType is the concrete device type for
// devices (ignored for users). Both the HTTP path (DeviceContext.IdentityType,
// DeviceContext.Type) and the WS path (Identity.Type, Identity.DeviceType) pass
// the same two fields, so they share one decision.
func (p authPolicy) permits(identityType, deviceType string) bool {
	if identityType == domain.IdentityTypeUser {
		return p.allowUser
	}
	if p.allowAnyDevice {
		return true
	}
	return p.allowDevice[deviceType]
}

// Per-surface policies implementing "each token type logs in only to its own
// surface" (POS→POS, WS→WS, WS✗POS). Cloud already tells WS the device type via
// /devices/me; these gates just enforce it locally.
var (
	// policyPosWeb guards /pos/* + /me/* — the pos-web cashier surface. pos-web
	// authenticates EITHER as an SSO cashier (admin-web user token) OR as a paired
	// `pos` DEVICE token from /api/v1/devices/pair. Cloud's /pos/* accepts BOTH
	// (mounted under auth.sso_or_device), so the workstation LAN MUST too — otherwise
	// a paired pos-web terminal gets 403 "device type not allowed" on LAN while it
	// works fine against Cloud (the LAN/cloud routing must be transparent). Only the
	// `pos` device type is admitted; a kiosk/tms/kds/handy token is still rejected
	// (per-surface isolation), and cross-branch access is blocked separately by
	// AuthMiddleware's branch check + requirePairedShop.
	policyPosWeb = authPolicy{allowUser: true, allowDevice: map[string]bool{"pos": true}}

	// Single-type device surfaces.
	policyKiosk = authPolicy{allowDevice: map[string]bool{"kiosk": true}}
	policyTMS   = authPolicy{allowDevice: map[string]bool{"tms": true}}
	policyKDS   = authPolicy{allowDevice: map[string]bool{"kds": true}}
	policyHandy = authPolicy{allowDevice: map[string]bool{"handy": true}}

	// policyWS guards the /ws realtime channel — a shared surface. Allowed:
	// SSO users + a paired `pos` device (pos-web authenticates as either — see
	// policyPosWeb; its /ws connection reuses the same pos_device_token, so
	// without `pos` here a paired pos-web terminal silently loses realtime and
	// falls back to polling) + kds/handy/workstation devices (the WS desktop
	// dashboard connects with its own workstation token). kiosk-web does NOT use
	// /ws (REST-poll only); a tms device token is rejected.
	policyWS = authPolicy{allowUser: true, allowDevice: map[string]bool{
		"pos": true, "kds": true, "handy": true, "workstation": true,
	}}

	// policyAnyAuthed accepts any authenticated identity. For deliberately
	// shared surfaces that are a *capability*, not a per-type home: /lan/print
	// (POS/kiosk/kds/workstation all print via WS) and the identity-agnostic
	// /customer/tables QR lookup.
	policyAnyAuthed = authPolicy{allowUser: true, allowAnyDevice: true}
)

// requireType wraps next with a device-type gate. It MUST sit behind
// AuthMiddleware.Wrap (which populates DeviceContext); it reads that context and
// rejects with 403 when the identity is not permitted by p. A missing context
// (should be impossible behind Wrap) fails closed.
//
// Rejections are logged but NOT written to the audit table on purpose: these
// LAN endpoints have no per-IP limiter, so auditing every rejected request would
// be a log-flood vector for a hostile LAN client.
func (s *Server) requireType(p authPolicy, next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		d, ok := DeviceFromContext(r.Context())
		if !ok || !p.permits(d.IdentityType, d.Type) {
			idType, devType := "", ""
			if d != nil {
				idType, devType = d.IdentityType, d.Type
			}
			slog.Warn("auth type rejected",
				"identity_type", idType, "device_type", devType, "path", r.URL.Path)
			writeError(w, http.StatusForbidden, "device type not allowed")
			return
		}
		next.ServeHTTP(w, r)
	})
}

// authedTypes is the type-aware analogue of s.authed: it wraps h with the auth
// middleware and then the device-type gate. Chain: auth → requireType → h.
func (s *Server) authedTypes(p authPolicy, h http.HandlerFunc) http.Handler {
	return s.authMW.Wrap(s.requireType(p, http.HandlerFunc(h)))
}
