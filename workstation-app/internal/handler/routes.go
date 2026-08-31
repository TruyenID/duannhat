package handler

import (
	"bytes"
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"net/http"
	"os"
	"runtime"
	"strconv"
	"strings"
	"time"

	"github.com/dxs-platform/workstation-app/internal/audit"
	"github.com/dxs-platform/workstation-app/internal/config"
	"github.com/dxs-platform/workstation-app/internal/printer"
	"github.com/dxs-platform/workstation-app/internal/printer/escpos"
	"github.com/dxs-platform/workstation-app/internal/service"
	"github.com/google/uuid"
)

func (s *Server) registerRoutes(mux *http.ServeMux) {
	// local wraps a legacy desktop-admin/POS handler with localOnly. These
	// routes are the workstation's OWN Wails UI (served in the webview at
	// http://localhost:8080) and are called WITHOUT a bearer token, so they
	// must be reachable from loopback only. LAN tablets/phones (kiosk/pos/kds)
	// never use these — they go through the bearer-authed /api/v1/* surface
	// (see registerLocalReplicaRoutes). Before #83 these sat behind lanOnly
	// alone, so any LAN device could drive orders CRUD / payment / void / and
	// menu/device/settings CRUD unauthenticated.
	local := func(h http.HandlerFunc) http.Handler { return localOnly(h) }

	// Dashboard
	mux.Handle("GET /api/status", local(s.handleStatus))
	mux.Handle("GET /api/dashboard/stats", local(s.handleDashboardStats))
	mux.Handle("GET /api/lan", local(s.handleLANInfo))
	mux.Handle("GET /api/config", local(s.handleGetConfig))
	mux.Handle("GET /api/version", local(s.handleGetVersion))

	// Orders
	mux.Handle("GET /api/orders", local(s.handleListOrders))
	mux.Handle("GET /api/orders/{id}", local(s.handleGetOrder))
	mux.Handle("POST /api/orders", local(s.handleCreateOrder))
	mux.Handle("PUT /api/orders/{id}", local(s.handleUpdateOrder))
	mux.Handle("POST /api/orders/{id}/print", local(s.handlePrintOrder))
	mux.Handle("POST /api/orders/{id}/payment", local(s.handleRecordPayment))

	// Menu
	mux.Handle("GET /api/menu", local(s.handleListMenu))
	mux.Handle("POST /api/menu", local(s.handleCreateMenu))
	mux.Handle("PUT /api/menu/{id}", local(s.handleUpdateMenu))
	mux.Handle("DELETE /api/menu/{id}", local(s.handleDeleteMenu))
	mux.Handle("POST /api/menu/seed", local(s.handleSeedMenu))

	// Devices
	mux.Handle("GET /api/devices", local(s.handleListDevices))
	mux.Handle("POST /api/devices", local(s.handleAddDevice))
	mux.Handle("PATCH /api/devices/{id}/roles", local(s.handleUpdateDeviceRoles))
	mux.Handle("DELETE /api/devices/{id}", local(s.handleRemoveDevice))
	mux.Handle("POST /api/devices/{id}/test", local(s.handleTestDevice))

	// Peripheral registry admin (Settings screen). Reads the local replica;
	// writes forward to Cloud with the device token, then re-pull. localOnly —
	// called only by the Wails frontend on localhost.
	mux.Handle("GET /api/peripheral-devices", local(s.handlePeripheralList))
	mux.Handle("POST /api/peripheral-devices", local(s.handlePeripheralCreate))
	mux.Handle("PUT /api/peripheral-devices/{id}", local(s.handlePeripheralUpdate))
	mux.Handle("DELETE /api/peripheral-devices/{id}", local(s.handlePeripheralDelete))

	// Sync
	mux.Handle("GET /api/sync", local(s.handleGetSync))
	mux.Handle("POST /api/sync/retry", local(s.handleRetrySync))
	// plan-042 recovery actions — operator-only (loopback/Wails desktop).
	mux.Handle("POST /api/sync/{id}/discard", localOnly(http.HandlerFunc(s.handleSyncDiscard)))
	mux.Handle("POST /api/sync/{id}/re-resolve", localOnly(http.HandlerFunc(s.handleSyncReResolve)))
	mux.Handle("POST /api/sync/orders/{orderId}/recover", localOnly(http.HandlerFunc(s.handleSyncRecoverOrder)))

	// Reports
	mux.Handle("GET /api/reports/daily", local(s.handleDailyReport))
	mux.Handle("GET /api/reports/popular", local(s.handlePopularItems))

	// Settings — loopback-only (operator config from the Wails UI). The GET
	// handler additionally enforces a readable-key allowlist so even a
	// loopback caller can never read credential keys like device_token (#84).
	mux.Handle("GET /api/settings/{key}", local(s.handleGetSetting))
	mux.Handle("PUT /api/settings/{key}", local(s.handleSetSetting))

	// Device Auth — pair/unpair/status are admin operations the Wails frontend
	// calls from localhost. localOnly blocks LAN devices: a stolen kiosk token
	// must not be able to unpair the workstation or read pairing state. The
	// Cloud-proxied /api/device/pair still validates the pairing code, but
	// LAN brute-force surface is removed by restricting to loopback.
	// pairLimiter (5/min/IP) layers on top — even from localhost, a runaway
	// script can't sweep the 6-char code space.
	//
	// OPTIONS preflight for browser clients (e.g. Vite dev server at :5174).
	// Without this Go's ServeMux returns 405 for OPTIONS, which carries no
	// Access-Control-Allow-Origin header and causes the browser to abort the
	// real request before localOnly is ever reached.
	mux.Handle("OPTIONS /api/device/", corsMiddleware(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusNoContent)
	})))
	mux.Handle("POST /api/device/pair", localOnly(s.pairLimiter.Middleware(http.HandlerFunc(s.handleDevicePair))))
	mux.Handle("GET /api/device/status", localOnly(http.HandlerFunc(s.handleDeviceStatus)))
	mux.Handle("POST /api/device/unpair", localOnly(http.HandlerFunc(s.handleDeviceUnpair)))

	// Audit & Monitor — sensitive operational data (device IDs, operator
	// actions, LAN client IPs, system load). Loopback-only by default.
	// /api/monitor/health is a Wails-internal probe; LAN devices use
	// /api/lan/health for mDNS discovery instead.
	mux.Handle("GET /api/audit", localOnly(http.HandlerFunc(s.handleAuditLog)))
	mux.Handle("GET /api/monitor", localOnly(http.HandlerFunc(s.handleMonitor)))
	mux.Handle("GET /api/monitor/health", localOnly(http.HandlerFunc(s.handleHealthCheck)))

	// Sync state inspection — read-only dump of the local coupon +
	// promotion replicas with their last-synced timestamps. Loopback-
	// only because it surfaces all org-scoped coupon data (codes,
	// values, branch scope). Operators chasing "HQ edit didn't
	// propagate" can hit this from a local browser to confirm what
	// the device actually has.
	mux.Handle("GET /api/sync/coupons-state", localOnly(http.HandlerFunc(s.handleSyncCouponsState)))
	mux.Handle("GET /api/sync/promotions-state", localOnly(http.HandlerFunc(s.handleSyncPromotionsState)))

	// API Docs (Swagger UI)
	mux.HandleFunc("GET /docs", s.handleSwaggerUI)
	mux.HandleFunc("GET /docs/openapi.yaml", s.handleOpenAPISpec)

	// WebSocket
	mux.HandleFunc("/ws", s.handleWebSocket)
}

// registerLocalReplicaRoutes wires the kiosk-facing local-replica endpoints
// (Phase 1). All routes require a Bearer token verified against Cloud's
// /api/v1/kiosk/me (with 5-min cache, see auth_middleware.go).
func (s *Server) registerLocalReplicaRoutes(mux *http.ServeMux) {
	// Kiosk identity & orders & payments. Payment writes get an extra
	// rate-limit layer (60/min/IP, burst 10) — defense against a
	// compromised kiosk hammering /payments. Read endpoints stay
	// unlimited (TanStack Query polling cadence).
	rateLimit := func(h http.HandlerFunc) http.Handler {
		return s.paymentLimiter.Middleware(s.authedTypes(policyKiosk, h))
	}
	mux.Handle("GET /api/v1/kiosk/me", s.authedTypes(policyKiosk, s.handleLocalKioskMe))
	mux.Handle("GET /api/v1/kiosk/effective-payment-options", s.authedTypes(policyKiosk, s.handleLocalEffectivePaymentOptions))
	mux.Handle("GET /api/v1/kiosk/orders", s.authedTypes(policyKiosk, s.handleLocalKioskOrders))
	mux.Handle("POST /api/v1/kiosk/payments", rateLimit(s.handleLocalCreatePayment))
	mux.Handle("GET /api/v1/kiosk/payments/{id}/status", s.authedTypes(policyKiosk, s.handleLocalPaymentStatus))
	mux.Handle("POST /api/v1/kiosk/payments/{id}/confirm", rateLimit(s.handleLocalConfirmPayment))
	mux.Handle("POST /api/v1/kiosk/payments/{id}/fail", rateLimit(s.handleLocalFailPayment))

	// Customer QR token → table lookup
	mux.Handle("GET /api/v1/customer/tables/{qrToken}", s.authedTypes(policyAnyAuthed, s.handleLocalCustomerTable))

	// Kiosk + customer Cloud passthrough. Every kiosk/customer route NOT
	// served locally above — split-by-items/preview, POST /customer/qr/{token},
	// POST /kiosk/audit-logs, and any future addition — must reach Cloud and
	// return its JSON. Without this catch-all the request falls through to the
	// SPA handler mounted at "/" and the kiosk gets index.html back, so
	// `response.json()` throws "Unexpected character: <". The specific local
	// handlers (kiosk/me, kiosk/orders, kiosk/payments*, customer/tables/*)
	// win via Go 1.22 ServeMux precedence; only the gaps proxy. Pure
	// passthrough (no local re-auth) — Cloud is the auth authority for the
	// kiosk token, same as the /auth/ proxy below.
	cloudPassthrough := s.cloudProxy()
	mux.Handle("/api/v1/kiosk/", cloudPassthrough)
	mux.Handle("/api/v1/customer/", cloudPassthrough)

	// TMS read-only (zones + tables)
	mux.Handle("GET /api/v1/tms/zones", s.authedTypes(policyTMS, s.handleLocalListZones))
	mux.Handle("GET /api/v1/tms/tables", s.authedTypes(policyTMS, s.handleLocalListTables))

	// POS web — Phase 2 integration. SSO-authenticated browser client, so
	// requests go through CORS middleware (preflight + Allow-Origin headers)
	// in addition to the standard auth wrapper. Mirrors /kiosk/* + adds order
	// CRUD because POS staff create orders (unlike kiosk which only reads).
	posAuth := func(h http.HandlerFunc) http.Handler {
		// cors → auth → type gate → shop guard → handler. Auth runs before the
		// guard so an unknown slug on an unauthenticated request still 401s first,
		// matching Cloud's middleware order (ResolvePosShop sits behind
		// auth.sso_or_device). policyPosWeb admits an SSO cashier OR a paired `pos`
		// device token — exact parity with Cloud's auth.sso_or_device — so a paired
		// pos-web terminal works identically on LAN and cloud. kiosk/tms device
		// tokens are still rejected here (per-surface isolation); cross-branch access
		// is blocked by the branch check + requirePairedShop.
		return corsForBrowser(s.authMW.Wrap(s.requireType(policyPosWeb, s.requirePairedShop(h))))
	}
	// Preflight catch-all for the POS namespace — browsers send OPTIONS
	// before any non-GET request, and ServeMux pattern matching won't
	// route OPTIONS to the same handler as GET/POST. Wrap with CORS only
	// (no auth) so preflight succeeds without a Bearer token.
	mux.Handle("OPTIONS /api/v1/pos/", corsForBrowser(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusNoContent)
	})))
	// =========================================================================
	//  Local /api/v1/pos/* handlers — kept ONLY for endpoints where the
	//  workstation's JSON shape matches Cloud's. Endpoints that Cloud serves
	//  via rich Eloquent Resources (MenuResource, CustomerOrderResource,
	//  MenuProductResource, CustomerResource — with eager-loaded relations
	//  product_sku.product.gallery, items.toppings, customer, table.zone,
	//  payments[], order_coupons[], decimal-as-string money fields, etc.)
	//  are intentionally NOT registered. The catch-all proxy at the bottom
	//  forwards them to Cloud transparently so pos-web sees the exact same
	//  shape regardless of LAN-vs-cloud routing.
	//
	//  When a Cloud-shape mirror becomes feasible (full schema sync DOWN +
	//  Go-side resource transformer), re-add the corresponding mux.Handle
	//  line; the proxy stops firing for that route immediately.
	//
	//  Handler functions stay in code (local_pos_phase{1..5}.go) so the
	//  service-layer logic can be reused; only the route wiring is gated.
	// =========================================================================

	// /me — identity from auth context, no eager relations.
	mux.Handle("GET /api/v1/pos/me", posAuth(s.handleLocalPosMe))

	// /shop — minimal Branch fields (id, slug, name, code, is_headquarters)
	// match Cloud's ShopInfoController response without nesting.
	mux.Handle("GET /api/v1/pos/shop", posAuth(s.handleLocalPosShop))

	// /settings/order — flat key-value reads; Cloud also returns flat object.
	mux.Handle("GET /api/v1/pos/settings/order", posAuth(s.handleLocalPosOrderSettings))

	// /payment-methods — small, flat list (id, code, name, is_active,
	// sort_order). Matches PaymentMethodResource shape.
	mux.Handle("GET /api/v1/pos/payment-methods", posAuth(s.handleLocalPosPaymentMethods))
	mux.Handle("GET /api/v1/pos/effective-payment-options", posAuth(s.handleLocalEffectivePaymentOptions))

	// 釣銭機 (Glory cash recycler) — async cash collection over LAN. Start returns
	// a session id; the POS polls status; cancel returns the deposited cash. 503
	// when no machine is configured. See docs/guide/cash-changer-glory-adapter.md.
	mux.Handle("POST /api/v1/pos/cash-changer/collect", posAuth(s.handleCashChangerCollect))
	mux.Handle("GET /api/v1/pos/cash-changer/collect/{session}", posAuth(s.handleCashChangerStatus))
	mux.Handle("POST /api/v1/pos/cash-changer/collect/{session}/cancel", posAuth(s.handleCashChangerCancel))

	// Verifone P400 (VescaJS) card terminal. pos-web drives it via the workstation
	// bridge; the workstation frontend polls the localOnly /api/terminal/* pair.
	// See docs/guide/pos-card-terminal-p400-vesca.md.
	mux.Handle("POST /api/v1/pos/terminal/charge", posAuth(s.handleCardTerminalCharge))
	mux.Handle("GET /api/v1/pos/terminal/charge/{session}", posAuth(s.handleCardTerminalStatus))
	mux.Handle("POST /api/v1/pos/terminal/charge/{session}/cancel", posAuth(s.handleCardTerminalCancel))
	mux.Handle("GET /api/terminal/next", localOnly(http.HandlerFunc(s.handleTerminalNext)))
	mux.Handle("POST /api/terminal/result", localOnly(http.HandlerFunc(s.handleTerminalResult)))

	// /staff — LAN-served from the local `staff` replica synced DOWN via
	// PullStaff (migration 019). Open Shift form's cashier dropdown reads
	// this and stays usable when the Cloud uplink is flaky. Gated by
	// posAuth (bearer + shop guard) like every sibling /pos/* route — the
	// dropdown only renders inside pos-web, which is already SSO-authed and
	// sends its Bearer token here (#88: was previously an unauthenticated
	// plain mux.HandleFunc, leaking the staff-name roster to any LAN device).
	mux.Handle("GET /api/v1/pos/staff", posAuth(s.handlePosStaff))

	// /tables — flat list with zone_id; pos-web tolerates a missing
	// zone object because it joins client-side with /zones.
	mux.Handle("GET /api/v1/pos/tables", posAuth(s.handleLocalPosTables))
	// LAN table status change — served locally (mirror update + immediate sync
	// UP), NOT proxied, so it works offline and converges via the sync engine.
	mux.Handle("POST /api/v1/pos/tables/{table}/status", posAuth(s.handleLocalPosTableStatus))

	// /revenue/summary — local aggregate of closed orders + payments.
	// Cloud serves the same shape; pos-web uses whichever the resolver
	// picks. See local_pos_revenue.go.
	mux.Handle("GET /api/v1/pos/revenue/summary", posAuth(s.handleLocalPosRevenueSummary))
	// /revenue/voids — cancellation analytics (whole-order + per-item voids).
	mux.Handle("GET /api/v1/pos/revenue/voids", posAuth(s.handleLocalPosRevenueVoids))
	// /revenue/void-events — paginated drill-down log of individual voids.
	mux.Handle("GET /api/v1/pos/revenue/void-events", posAuth(s.handleLocalPosRevenueVoidEvents))

	// Cashier shift — LAN-offline package. Workstation owns the lifecycle
	// in SQLite and syncs UP via /workstation/till/* on Cloud. Pos-web hits
	// /pos/till/* same as Cloud; these handlers serve from local replicas
	// + write to local SOT tables. Wired BEFORE the catch-all proxy so
	// Cloud is never the source of truth for an active shift while a
	// workstation is online. See local_pos_till.go.
	mux.Handle("GET /api/v1/pos/till/current", posAuth(s.handleLocalPosTillCurrent))
	// plan-044 R2 — gap-payment preview for the shift-open reconciliation panel.
	mux.Handle("GET /api/v1/pos/till/gap-preview", posAuth(s.handleLocalPosTillGapPreview))
	mux.Handle("GET /api/v1/pos/till/denominations", posAuth(s.handleLocalPosTillDenominations))
	mux.Handle("GET /api/v1/pos/till/tender-types", posAuth(s.handleLocalPosTillTenderTypes))
	mux.Handle("GET /api/v1/pos/till/tender-categories", posAuth(s.handleLocalPosTillTenderCategories))
	mux.Handle("POST /api/v1/pos/till/sessions", posAuth(s.handleLocalPosTillOpenSession))
	// Static `stale` must win over the dynamic `{id}` segment — otherwise
	// our local SessionShow handler eats `/sessions/stale` as id=stale and
	// returns 404 instead of letting Cloud serve the manager-only stale
	// list (Plan-032). Same trick for force-abandon / manual-settle below.
	mux.Handle("GET /api/v1/pos/till/sessions/stale", corsForBrowser(s.authMW.Wrap(s.requireType(policyPosWeb, s.posCloudProxy()))))
	mux.Handle("GET /api/v1/pos/till/sessions/{id}", posAuth(s.handleLocalPosTillSessionShow))
	mux.Handle("GET /api/v1/pos/till/sessions/{id}/reconciliation", posAuth(s.handleLocalPosTillReconciliation))
	// plan-044 R2 — close-screen paid / unpaid-carry summary.
	mux.Handle("GET /api/v1/pos/till/sessions/{id}/order-summary", posAuth(s.handleLocalPosTillOrderSummary))
	mux.Handle("POST /api/v1/pos/till/sessions/{id}/cash-events", posAuth(s.handleLocalPosTillCashEvent))
	mux.Handle("POST /api/v1/pos/till/sessions/{id}/draft", posAuth(s.handleLocalPosTillDraft))
	mux.Handle("POST /api/v1/pos/till/sessions/{id}/close", posAuth(s.handleLocalPosTillClose))
	// Plan-046 — handover: settle but keep the chain open (served locally).
	mux.Handle("POST /api/v1/pos/till/sessions/{id}/handover", posAuth(s.handleLocalPosTillHandover))
	mux.Handle("POST /api/v1/pos/till/sessions/{id}/abandon", posAuth(s.handleLocalPosTillAbandon))

	// /revenue/by-product — intentionally NOT registered locally. The
	// cloud version groups by `menu_sections` (per-shop menu sections)
	// and joins through menu_products + product_skus. Workstation's
	// local replica only has `menu_items` (a flat name/category cache)
	// — it can't reproduce the section-aware grouping without a sync
	// expansion of menu_sections + menu_products. Falling through to
	// the cloud proxy below means LAN mode returns the same shape as
	// Cloud for this endpoint. The local handler stays in
	// `local_pos_revenue_by_product.go` so we can re-enable it once
	// the missing tables are sync'd DOWN.

	// LAN-offline order CRUD + customer + revenue lookups. customerOrderShape()
	// transforms the workstation `service.Order` into the same JSON envelope
	// CustomerOrderResource emits so pos-web doesn't crash on missing
	// relations. The list / detail / customer / menu-products / revenue-by-
	// product reads are paginated where Cloud paginates so the
	// `PaginatedResponse` envelope pos-web expects lines up.
	mux.Handle("GET /api/v1/pos/orders", posAuth(s.handleLocalPosOrders))
	mux.Handle("GET /api/v1/pos/orders/{id}", posAuth(s.handleLocalPosGetOrder))
	mux.Handle("POST /api/v1/pos/customers/find-or-create", posAuth(s.handleLocalPosFindOrCreateCustomer))
	mux.Handle("GET /api/v1/pos/customers/{customer}/outstanding", posAuth(s.handleLocalPosCustomerOutstanding))
	// `/menus/{menu}/products` would conflict with `/menus/by-day/{dow}` —
	// Go 1.22 ServeMux flags them as ambiguous (both can match
	// `/menus/by-day/products`) and refuses both even after registering a
	// literal disambiguator. Pos-web uses the paginated /products endpoint
	// only for the in-menu search input; the bundled detail at
	// `/menus/{menu}` already returns menu_products[] so the cart path is
	// LAN-complete. Search input → cloud proxy when offline.
	mux.Handle("GET /api/v1/pos/revenue/by-product", posAuth(s.handleLocalPosRevenueByProduct))

	mux.Handle("POST /api/v1/pos/orders", posAuth(s.handleLocalPosCreateOrder))
	mux.Handle("PUT /api/v1/pos/orders/{id}/init", posAuth(s.handleLocalPosInitOrder))
	mux.Handle("PUT /api/v1/pos/orders/{id}", posAuth(s.handleLocalPosUpdateOrder))
	mux.Handle("DELETE /api/v1/pos/orders/{id}", posAuth(s.handleLocalPosDeleteOrder))
	mux.Handle("POST /api/v1/pos/orders/{id}/void", posAuth(s.handleLocalPosVoidOrder))
	mux.Handle("POST /api/v1/pos/orders/{id}/items", posAuth(s.handleLocalPosAddItems))
	mux.Handle("PATCH /api/v1/pos/orders/{id}/items/{item}", posAuth(s.handleLocalPosUpdateItem))
	mux.Handle("DELETE /api/v1/pos/orders/{id}/items/{item}", posAuth(s.handleLocalPosDeleteItem))
	mux.Handle("POST /api/v1/pos/orders/{id}/items/{item}/void", posAuth(s.handleLocalPosVoidItem))
	// plan-045 — LAN refund: append a negative-qty line reversing N units of a line.
	mux.Handle("POST /api/v1/pos/orders/{id}/items/{item}/refund", posAuth(s.handleLocalPosRefundItem))
	mux.Handle("POST /api/v1/pos/orders/{id}/checkout", posAuth(s.handleLocalPosCheckout))
	mux.Handle("POST /api/v1/pos/orders/{id}/apply-coupon", posAuth(s.handleLocalPosApplyCoupon))
	mux.Handle("DELETE /api/v1/pos/orders/{id}/coupon", posAuth(s.handleLocalPosReleaseCoupon))

	// P5 — table merge / split-bill preview / payment record / refund.
	// Same shape contract as the order CRUD set above. split-bill is
	// read-only; it returns its own SplitBillResult shape (not
	// CustomerOrderResource), so it bypasses customerOrderShape. The
	// /orders/{id}/payments POST is what carries the split-bill
	// metadata to Cloud — pos-web sends `metadata.split_mode='by_items'`
	// + `item_allocations[]` for each sub-bill, the local handler stores
	// it on payments.metadata + the sync UP queue decodes + forwards.
	mux.Handle("POST /api/v1/pos/orders/{id}/merge-table", posAuth(s.handleLocalPosMergeTable))
	mux.Handle("POST /api/v1/pos/orders/{id}/unmerge-table", posAuth(s.handleLocalPosUnmergeTable))
	mux.Handle("GET /api/v1/pos/orders/{id}/split-bill", posAuth(s.handleLocalPosSplitBill))
	mux.Handle("POST /api/v1/pos/orders/{id}/split-bill", posAuth(s.handleLocalPosSplitBill))
	// plan-033 — by-items preview. Read-only; pos-web URL-encodes the
	// candidate allocations into a `?allocations=` query param. Workstation
	// mirrors backend's shape so the LAN client never sees `undefined`
	// from preview_bills / items[].claims.
	mux.Handle("GET /api/v1/pos/orders/{id}/split-by-items/preview", posAuth(s.handleLocalPosSplitByItemsPreview))
	mux.Handle("POST /api/v1/pos/orders/{id}/payments", posAuth(s.handleLocalPosCreatePayment))
	mux.Handle("GET /api/v1/pos/orders/{id}/payments", posAuth(s.handleLocalPosListOrderPayments))
	mux.Handle("POST /api/v1/pos/orders/{id}/payments/{paymentId}/refund", posAuth(s.handleLocalPosRefundPayment))

	// NOTE: GET /orders, GET /orders/{id}, GET /orders/{id}/payments, and
	// the advanced ops (split-bill, merge-table, unmerge-table, refund)
	// remain proxied — those return larger lists / paginated shapes the
	// transformer doesn't yet cover, or are admin-only.
	//
	// LAN-offline menu list/detail/by-day/products. Gallery +
	// topping_groups + product_options stay deferred, but the core
	// MenuCatalog read path (menus → menu_products[].skus[].product +
	// active_promotion overlay) is now served from local SQLite via
	// PullMenuCatalog. The /by-day/{dow} and /{menu}/products endpoints
	// share the same 4-segment URL shape — Go 1.22 ServeMux refuses to
	// register both as separate patterns because each has paths the
	// other doesn't match. We instead register ONE pattern
	// `/menus/{seg1}/{seg2}` and dispatch internally below.
	mux.Handle("GET /api/v1/pos/menus", posAuth(s.handleLocalPosListMenus))
	mux.Handle("GET /api/v1/pos/menus/{menu}", posAuth(s.handleLocalPosMenuDetailLocal))
	mux.Handle("GET /api/v1/pos/menus/{seg1}/{seg2}", posAuth(s.dispatchMenuTwoSeg))
	//
	// NOTE: /customers/* unregistered — CustomerResource has multiple address
	// rows and outstanding requires joining orders.customer_id (workstation
	// schema doesn't store customer_id on orders yet).
	//
	// NOTE: split-bill, apply-coupon, refund, merge-table return rich orders
	// → also proxied.

	// Catch-all — every /api/v1/pos/* path NOT explicitly handled above is
	// reverse-proxied to Cloud, preserving the Bearer token and X-Shop-Slug
	// header. See pos_cloud_proxy.go for the design rationale. The shop guard
	// rejects a mismatched slug up front (#544) so a wrong/ghost shop 404s
	// instead of being silently rewritten to the paired branch by the proxy.
	mux.Handle("/api/v1/pos/", corsForBrowser(s.authMW.Wrap(s.requireType(policyPosWeb, s.requirePairedShop(s.posCloudProxy())))))

	// pos-web also calls /api/v1/auth/* on the login flow (SSO callback)
	// and /api/v1/me/* (context, brands, shops, notifications). These are
	// pure passthrough — no auth token to verify locally; the inner Cloud
	// endpoint will reject if the token is missing / expired. CORS still
	// applies because the browser is making cross-origin requests to the
	// workstation, and the catch-all preserves the body verbatim.
	//
	// Without these, LAN-mode pos-web 404s on token refresh / re-login
	// because the catch-all above only matches /api/v1/pos/.
	mux.Handle("/api/v1/auth/", corsForBrowser(s.posCloudProxy()))
	mux.Handle("/api/v1/me/", corsForBrowser(s.authMW.Wrap(s.requireType(policyPosWeb, s.posCloudProxy()))))

	// KDS (Kitchen Display Screen) — browser-based, requires CORS like POS-web.
	// KDS-web runs at localhost:5460 in dev; production at *.godx.jp.
	kdsAuth := func(h http.HandlerFunc) http.Handler {
		return corsForBrowser(s.authedTypes(policyKDS, h))
	}
	mux.Handle("OPTIONS /api/v1/kds/", corsForBrowser(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusNoContent)
	})))
	mux.Handle("GET /api/v1/kds/me", kdsAuth(s.handleLocalKdsMe))
	mux.Handle("GET /api/v1/kds/orders", kdsAuth(s.handleLocalKdsOrders))
	mux.Handle("PATCH /api/v1/kds/orders/{order}/items/{item}/status", kdsAuth(s.handleLocalKdsBumpItem))

	// Phase 5 — KDS operation endpoints (plan-028). Mirror cloud route paths exactly
	// so KDS-web can target either workstation LAN or cloud without URL differences.
	mux.Handle("POST /api/v1/kds/orders/{customerOrder}/items/{item}/mark-preparing", kdsAuth(s.handleLocalKdsMarkPreparing))
	mux.Handle("POST /api/v1/kds/orders/{customerOrder}/items/{item}/mark-ready", kdsAuth(s.handleLocalKdsMarkReady))
	mux.Handle("POST /api/v1/kds/orders/{customerOrder}/items/{item}/mark-served", kdsAuth(s.handleLocalKdsMarkServed))
	mux.Handle("POST /api/v1/kds/orders/{customerOrder}/items/{item}/revert", kdsAuth(s.handleLocalKdsRevert))
	mux.Handle("POST /api/v1/kds/orders/{customerOrder}/bump-all", kdsAuth(s.handleLocalKdsBumpAll))

	// Handy (staff handheld) — device-token auth, native app (no CORS needed).
	// Routes mirror Cloud's /api/v1/handy/* so the app works against either
	// endpoint by changing only EXPO_PUBLIC_API_URL.
	mux.Handle("GET /api/v1/handy/me", s.authedTypes(policyHandy, s.handleLocalHandyMe))
	mux.Handle("GET /api/v1/handy/tables", s.authedTypes(policyHandy, s.handleLocalHandyTables))
	// Register by-day for each day of week as literal paths — avoids Go ServeMux
	// conflict with {menu}/products. Workstation ignores dayOfWeek (returns all active items).
	for _, day := range []string{"0", "1", "2", "3", "4", "5", "6"} {
		d := day
		mux.Handle("GET /api/v1/handy/menus/by-day/"+d, s.authedTypes(policyHandy, s.handleLocalHandyMenusByDay))
	}
	mux.Handle("GET /api/v1/handy/menus/{menu}/products", s.authedTypes(policyHandy, s.handleLocalHandyMenuProducts))
	mux.Handle("GET /api/v1/handy/settings/order", s.authedTypes(policyHandy, s.handleLocalHandyOrderSettings))
	mux.Handle("GET /api/v1/handy/orders", s.authedTypes(policyHandy, s.handleLocalHandyOrders))
	mux.Handle("POST /api/v1/handy/orders", s.authedTypes(policyHandy, s.handleLocalHandyCreateOrder))
	mux.Handle("GET /api/v1/handy/orders/{order}", s.authedTypes(policyHandy, s.handleLocalHandyGetOrder))
	mux.Handle("PUT /api/v1/handy/orders/{order}/init", s.authedTypes(policyHandy, s.handleLocalHandyInitOrder))
	mux.Handle("POST /api/v1/handy/orders/{order}/items", s.authedTypes(policyHandy, s.handleLocalHandyAddItems))
	mux.Handle("PATCH /api/v1/handy/orders/{order}/items/{item}", s.authedTypes(policyHandy, s.handleLocalHandyUpdateItem))
	mux.Handle("DELETE /api/v1/handy/orders/{order}/items/{item}", s.authedTypes(policyHandy, s.handleLocalHandyRemoveItem))
	mux.Handle("DELETE /api/v1/handy/orders/{order}", s.authedTypes(policyHandy, s.handleLocalHandyVoidOrder))
	mux.Handle("POST /api/v1/handy/orders/{order}/fire", s.authedTypes(policyHandy, s.handleLocalHandyFireOrder))

	// LAN-only endpoints (workstation-specific, not part of Cloud API surface)
	// /api/lan/health is public (no auth) — used for mDNS discovery healthcheck
	// /api/lan/print/* requires auth — Phase 2 POS-web feature
	// /api/lan/images/{hash} is public (no auth) — content-addressable cache
	//     of menu images downloaded from Cloud. Knowing the hash already
	//     implies the tablet got it from an authenticated menu response.
	// pos-web probes health from the browser cross-origin (Vite dev server on
	// :5440 → workstation on :8080), so the response needs the same allow-list
	// headers as /api/lan/print/* or the fetch is blocked and the workstation
	// reads as permanently unreachable.
	mux.Handle("GET /api/lan/health", corsForBrowser(http.HandlerFunc(s.handleLANHealth)))
	mux.Handle("OPTIONS /api/lan/health", corsForBrowser(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusNoContent)
	})))
	mux.HandleFunc("GET /api/lan/images/{hash}", s.handleLANImage)

	// /api/lan/print/* — pos-web browser callers. Wrap with corsForBrowser
	// so the preflight OPTIONS lands the same allow-list as /api/v1/pos/*.
	// Plan-038 T2.1 + T2.2 + T2.3 + T2.6.
	mux.Handle("OPTIONS /api/lan/print/", corsForBrowser(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusNoContent)
	})))
	mux.Handle("POST /api/lan/print/kitchen-ticket",
		corsForBrowser(s.authedTypes(policyAnyAuthed, s.handleLANPrintKitchenTicket)))
	// Full-order bill + QR, on-demand, no reprint limit ("in phiếu order").
	mux.Handle("POST /api/lan/print/order-bill",
		corsForBrowser(s.authedTypes(policyAnyAuthed, s.handleLANPrintOrderBill)))
	mux.Handle("POST /api/lan/print/payment-receipt",
		corsForBrowser(s.authedTypes(policyAnyAuthed, s.handleLANPrintReceipt)))
	// Red invoice ("HOA DON DO") — paid receipt + a named-customer line.
	mux.Handle("POST /api/lan/print/red-invoice",
		corsForBrowser(s.authedTypes(policyAnyAuthed, s.handleLANPrintRedInvoice)))
	// Plan-038 T10.6 — debt slip ("PHIEU GHI NO").
	mux.Handle("POST /api/lan/print/debt-slip",
		corsForBrowser(s.authedTypes(policyAnyAuthed, s.handleLANPrintDebtSlip)))
	// Plan-038 T11.4 — VAT invoice ("HOA DON GIA TRI GIA TANG").
	mux.Handle("POST /api/lan/print/vat-invoice",
		corsForBrowser(s.authedTypes(policyAnyAuthed, s.handleLANPrintVatInvoice)))
	// 精算 cashier-shift settlement (Z) report — printed on shift close.
	mux.Handle("POST /api/lan/print/shift-report",
		corsForBrowser(s.authedTypes(policyAnyAuthed, s.handleLANPrintShiftReport)))
	// Plan-046 — aggregate chain (kết ca cuối) slip.
	mux.Handle("POST /api/lan/print/chain-report",
		corsForBrowser(s.authedTypes(policyAnyAuthed, s.handleLANPrintChainReport)))
	// レジ開け shift-open (opening cash count) report — printed on shift open.
	mux.Handle("POST /api/lan/print/shift-open-report",
		corsForBrowser(s.authedTypes(policyAnyAuthed, s.handleLANPrintShiftOpenReport)))
	mux.Handle("GET /api/lan/print/status",
		corsForBrowser(s.authedTypes(policyAnyAuthed, s.handleLANPrintStatus)))
}

// ─── Dashboard ────────────────────────────────────────────────────────

func (s *Server) handleStatus(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{
		"version":      config.Version,
		"status":       "running",
		"device_count": len(s.devices.ListDevices()),
		"ws_clients":   s.hub.ClientCount(),
		"lan_ip":       GetLANAddress(),
		"port":         s.port,
	})
}

func (s *Server) handleDashboardStats(w http.ResponseWriter, r *http.Request) {
	var activeOrders, todayOrders, todayRevenue int

	s.db.QueryRow("SELECT COUNT(*) FROM orders WHERE status NOT IN ('closed', 'voided')").Scan(&activeOrders)

	today := time.Now().Format("2006-01-02")
	s.db.QueryRow(
		"SELECT COUNT(*), COALESCE(SUM(total_amount), 0) FROM orders WHERE date(opened_at) = ? AND status != 'voided'",
		today,
	).Scan(&todayOrders, &todayRevenue)

	devices := s.devices.ListDevices()
	online := 0
	for _, d := range devices {
		if d.Status == printer.StatusOnline {
			online++
		}
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"active_orders":  activeOrders,
		"today_orders":   todayOrders,
		"today_revenue":  todayRevenue,
		"device_count":   len(devices),
		"online_devices": online,
		"sync_status":    string(s.sync.Status()),
	})
}

func (s *Server) handleLANInfo(w http.ResponseWriter, r *http.Request) {
	ip := GetLANAddress()
	// `candidates` lists every private IPv4 on the box, best first. Auto-detection
	// picks candidates[0], but a shop PC can have several plausible NICs (Wi-Fi +
	// Ethernet, or a hypervisor bridge) and only the operator can tell which one
	// their tablets are actually on. Surfacing the alternatives turns "nothing
	// connects" into "try this other address" without a support call — and
	// WS_APP_LAN_IP pins the answer permanently.
	writeJSON(w, http.StatusOK, map[string]any{
		"ip":         ip,
		"port":       s.port,
		"url":        fmt.Sprintf("http://%s:%d", ip, s.port),
		"ws_clients": s.hub.ClientCount(),
		"candidates": GetLANAddresses(),
		"pinned":     os.Getenv(lanIPOverrideEnv) != "",
	})
}

func (s *Server) handleGetConfig(w http.ResponseWriter, r *http.Request) {
	cfg := s.config.Get()
	writeJSON(w, http.StatusOK, cfg)
}

func (s *Server) handleGetVersion(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]string{"version": config.Version})
}

// ─── Orders ───────────────────────────────────────────────────────────

func (s *Server) handleListOrders(w http.ResponseWriter, r *http.Request) {
	var (
		orders []service.Order
		err    error
	)
	// ?status=closed → recently paid/closed bills (kiosk/customer orders that
	// arrive already closed via pull-down). Default → the live active board.
	if r.URL.Query().Get("status") == "closed" {
		orders, err = s.orders.ListRecentClosed(100)
	} else {
		orders, err = s.orders.ListActive()
	}
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	if orders == nil {
		orders = []service.Order{}
	}
	writeJSON(w, http.StatusOK, map[string]any{"orders": orders})
}

func (s *Server) handleGetOrder(w http.ResponseWriter, r *http.Request) {
	o, err := s.orders.GetByID(r.PathValue("id"))
	if err != nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"order": o})
}

func (s *Server) handleCreateOrder(w http.ResponseWriter, r *http.Request) {
	var input service.CreateOrderInput
	if err := readJSON(r, &input); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	o, err := s.orders.Create(input, s.codeGen)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	s.hub.BroadcastEvent("order_created", o)
	s.auditLog(r, "order.create", "order", o.ID, auditDetails(map[string]any{
		"table": o.TableNumber,
		"total": o.TotalAmount,
	}))

	// Enqueue sync UP — workstation owns orders locally, but Cloud mirrors
	// for HQ visibility. Cloud POST /api/v1/workstation/orders is gated by
	// `device.auth:workstation`, so the queue payload must carry the
	// WORKSTATION's own device token from settings — relaying the LAN
	// caller's bearer (kiosk/POS) would 403 against the workstation-typed
	// middleware. order_type heuristic: dine_in when a table is set,
	// takeaway otherwise. Items are not yet mirrored — Phase 1.5 ships the
	// order shell so HQ sees the order exists; items follow in Phase 1.6.
	if s.sync != nil {
		syncPayload := map[string]any{
			"bearer_token":    s.GetDeviceToken(),
			"idempotency_key": uuid.NewString(),
			// plan-041 — Cloud mints the gapless ORD-#### code; we send
			// client_order_id (local UUID) as the durable idempotency key and
			// reconcile the Cloud-assigned code back via the sync response. No
			// order_code is sent up.
			"order": map[string]any{
				"client_order_id":         o.ID,
				"order_type":              o.OrderType,
				"guest_count":             o.GuestCount,
				"note":                    o.Note,
				"customer_takeaway_name":  o.CustomerTakeawayName,
				"customer_takeaway_phone": o.CustomerTakeawayPhone,
			},
		}
		if err := s.sync.Enqueue("order", o.ID, "create", syncPayload, 1); err != nil {
			// Don't fail the request — order is in local DB, sync will retry.
			s.auditLog(r, "order.sync_enqueue_failed", "order", o.ID, auditDetails(map[string]any{"err": err.Error()}))
		} else {
			// LAN-mode UX: push immediately instead of waiting for the 5s tick.
			s.sync.Wake()
		}
	}

	writeJSON(w, http.StatusCreated, map[string]any{"order": o})
}

func (s *Server) handleUpdateOrder(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	var body struct {
		Status   string                    `json:"status"`
		AddItems []service.CreateItemInput `json:"add_items"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	if body.Status != "" {
		if err := s.orders.UpdateStatus(id, service.Status(body.Status)); err != nil {
			writeError(w, http.StatusBadRequest, err.Error())
			return
		}
	}
	if len(body.AddItems) > 0 {
		if _, err := s.orders.AddItems(id, body.AddItems); err != nil {
			writeServerError(w, r, err)
			return
		}
	}
	o, err := s.orders.GetByID(id)
	if err != nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}
	s.hub.BroadcastEvent("order_updated", o)
	s.auditLog(r, "order.status_change", "order", id, auditDetails(map[string]any{"status": body.Status}))
	writeJSON(w, http.StatusOK, map[string]any{"order": o})
}

func (s *Server) handlePrintOrder(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	var body struct {
		Type string `json:"type"`
	}
	if err := readJSON(r, &body); err != nil {
		body.Type = "kitchen"
	}
	o, err := s.orders.GetByID(id)
	if err != nil {
		writeError(w, http.StatusNotFound, "order not found")
		return
	}

	var printErrors []string
	printConfig := service.PrintJobConfig{
		PaperWidth:   42,
		StoreName:    s.settingValue("workstation_branch_name"),
		StoreSubName: s.settingValue("workstation_brand_name"),
		TaxRate:      s.shopTaxRate(),
		Currency:     s.printCurrencySymbol(),
	}

	switch body.Type {
	case "kitchen", "all":
		// "Print Kitchen Ticket" prints ONLY the kitchen ticket (per printer
		// group so bar items hit bar_printer — plan-038 T1.2/T1.3). It must NOT
		// print the runner/hold bill: that slip looks like a receipt and the
		// dedicated "Print Receipt" button (case "receipt") owns it. The LAN
		// fire path still uses printKitchenAndRunnerOn for the hold slip.
		dispatcher := printer.NewDispatcher(s.devices)
		groups := make(map[string][]service.Item)
		for _, item := range o.Items {
			groups[item.PrinterGroup] = append(groups[item.PrinterGroup], item)
		}
		for group, items := range groups {
			kp, _ := dispatcher.RouteKitchenItem(group)
			if kp == nil {
				role, _ := printer.RoleForGroup(group)
				printErrors = append(printErrors, fmt.Sprintf("no %s configured", role))
				continue
			}
			ticketNo, err := s.orders.NextKitchenTicketNumber()
			if err != nil {
				writeServerError(w, r, err)
				return
			}
			if err := kp.Connect(); err != nil {
				printErrors = append(printErrors, fmt.Sprintf("connect: %v", err))
				continue
			}
			// Center the 42-col layout on the printer's real width (48 for 80mm).
			printConfig.PhysicalWidth = kp.CharWidth()
			if err := kp.Print(service.FormatKitchenTicket(o, items, ticketNo, printConfig)); err != nil {
				kp.Disconnect()
				printErrors = append(printErrors, fmt.Sprintf("print: %v", err))
				continue
			}
			kp.Disconnect()
			now := time.Now().UTC().Format(time.RFC3339)
			for _, item := range items {
				// Manual "print all" path — every line prints at full quantity,
				// so printed_quantity = quantity (delta closes to 0).
				_ = s.orders.MarkItemPrinted(item.ID, item.Quantity, now)
			}
		}

	case "hold":
		// "Print Hold" reprints the pass/expo hold slip — the full order bill
		// WITH the order QR ("order có QR") — on the hold printer, falling back
		// to receipt then kitchen for single-station shops. Same slip the fire
		// flow sends to the hold printer, on demand for the whole order. It does
		// NOT touch print_status (a hold reprint isn't a kitchen fire).
		s.localizeOrderForPrint(o, s.printLabelLocale())
		hp := s.devices.GetPrinterByRole(printer.TypeHoldPrinter)
		if hp == nil {
			hp = s.devices.GetPrinterByRole(printer.TypeReceiptPrinter)
		}
		if hp == nil {
			hp = s.devices.GetPrinterByRole(printer.TypeKitchenPrinter)
		}
		if hp == nil {
			printErrors = append(printErrors, "no hold_printer configured")
			break
		}
		printConfig.Locale = s.printLabelLocale()
		printConfig.PhysicalWidth = hp.CharWidth()
		if err := hp.Connect(); err != nil {
			printErrors = append(printErrors, fmt.Sprintf("connect: %v", err))
			break
		}
		// ticketNo is unused by the bill formatter (formatBillTicket ignores it).
		if err := hp.Print(service.FormatRunnerTicket(o, o.Items, 0, printConfig)); err != nil {
			printErrors = append(printErrors, fmt.Sprintf("print: %v", err))
		}
		hp.Disconnect()

	case "receipt":
		// Print the customer receipt on the receipt_printer (falls back per the
		// dispatcher ladder). Show the amount already settled, defaulting to the
		// order total for an unpaid reprint so the slip isn't blank.
		amount := o.PaidAmount
		if amount <= 0 {
			amount = o.TotalAmount
		}
		if err := s.printPaymentReceipt(id, amount, s.printLabelLocale(), ""); err != nil {
			printErrors = append(printErrors, fmt.Sprintf("receipt: %v", err))
		}
	}

	if len(printErrors) > 0 {
		writeJSON(w, http.StatusOK, map[string]any{"status": "partial", "errors": printErrors})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok"})
}

func (s *Server) handleRecordPayment(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	var body struct {
		Method string `json:"method"`
	}
	if err := readJSON(r, &body); err != nil {
		body.Method = "cash"
	}
	if err := s.orders.RecordPayment(id, body.Method); err != nil {
		// RecordPayment now surfaces the "order missing" case (sql.ErrNoRows
		// or zero RowsAffected). Map to 404 so the FE doesn't get a generic
		// 500 + so we don't broadcast/audit a payment for a phantom order.
		if errors.Is(err, sql.ErrNoRows) || strings.Contains(err.Error(), "not found") {
			writeError(w, http.StatusNotFound, "order not found")
			return
		}
		writeServerError(w, r, err)
		return
	}
	s.hub.BroadcastEvent("order_paid", map[string]string{"id": id, "method": body.Method})
	s.auditLog(r, "order.payment", "order", id, auditDetails(map[string]any{"method": body.Method}))
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok"})
}

// ─── Menu ─────────────────────────────────────────────────────────────

func (s *Server) handleListMenu(w http.ResponseWriter, r *http.Request) {
	rows, err := s.db.Query(`
		SELECT id, name, COALESCE(name_ja, ''), COALESCE(category, ''), price, printer_group, is_active,
		       tax_type_id, COALESCE(is_alcohol, 0)
		FROM menu_items WHERE is_active = 1 ORDER BY sort_order, name
	`)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rows.Close()

	type menuItem struct {
		ID           string `json:"id"`
		Name         string `json:"name"`
		NameJa       string `json:"name_ja"`
		Category     string `json:"category"`
		Price        int    `json:"price"`
		PrinterGroup string `json:"printer_group"`
		IsActive     bool   `json:"is_active"`
		// plan-043 (T3.3) — per-item tax resolution inputs re-exposed to LAN
		// clients. TaxTypeID is null when the item inherits the branch/brand
		// default. IsAlcohol drives 酒類 escalation on the client-side preview.
		TaxTypeID *string `json:"tax_type_id"`
		IsAlcohol bool    `json:"is_alcohol"`
	}

	var items []menuItem
	for rows.Next() {
		var item menuItem
		var isActive, isAlcohol int
		var taxTypeID sql.NullString
		if err := rows.Scan(&item.ID, &item.Name, &item.NameJa, &item.Category, &item.Price, &item.PrinterGroup, &isActive, &taxTypeID, &isAlcohol); err != nil {
			continue
		}
		item.IsActive = isActive == 1
		item.IsAlcohol = isAlcohol == 1
		if taxTypeID.Valid && taxTypeID.String != "" {
			v := taxTypeID.String
			item.TaxTypeID = &v
		}
		items = append(items, item)
	}
	if items == nil {
		items = []menuItem{}
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"items":     items,
		"tax_types": s.loadTaxTypes(),
	})
}

// loadTaxTypes returns the synced brand tax types for LAN clients (plan-043
// T3.3). Empty slice (never nil) when nothing has synced yet.
func (s *Server) loadTaxTypes() []map[string]any {
	rows, err := s.db.Query(`
		SELECT id, code, name, rate_dine_in, rate_takeaway, is_default, is_active
		FROM tax_types ORDER BY rate_dine_in`)
	if err != nil {
		return []map[string]any{}
	}
	defer rows.Close()
	out := []map[string]any{}
	for rows.Next() {
		var id, code, name string
		var rateDineIn, rateTakeaway float64
		var isDefault, isActive int
		if err := rows.Scan(&id, &code, &name, &rateDineIn, &rateTakeaway, &isDefault, &isActive); err != nil {
			continue
		}
		out = append(out, map[string]any{
			"id":            id,
			"code":          code,
			"name":          name,
			"rate_dine_in":  rateDineIn,
			"rate_takeaway": rateTakeaway,
			"is_default":    isDefault == 1,
			"is_active":     isActive == 1,
		})
	}
	return out
}

func (s *Server) handleCreateMenu(w http.ResponseWriter, r *http.Request) {
	var body struct {
		Name         string `json:"name"`
		NameJa       string `json:"name_ja"`
		Category     string `json:"category"`
		Price        int    `json:"price"`
		PrinterGroup string `json:"printer_group"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	id := uuid.New().String()
	now := time.Now().UTC().Format(time.RFC3339)
	_, err := s.db.Exec(`
		INSERT INTO menu_items (id, name, name_ja, category, price, printer_group, local_updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?)
	`, id, body.Name, body.NameJa, body.Category, body.Price, body.PrinterGroup, now)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	s.auditLog(r, "menu.create", "menu_item", id, auditDetails(map[string]any{
		"name":  body.Name,
		"price": body.Price,
	}))
	writeJSON(w, http.StatusCreated, map[string]any{
		"item": map[string]any{
			"id": id, "name": body.Name, "name_ja": body.NameJa,
			"category": body.Category, "price": body.Price,
			"printer_group": body.PrinterGroup, "is_active": true,
		},
	})
}

func (s *Server) handleUpdateMenu(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	var body struct {
		Name         string `json:"name"`
		NameJa       string `json:"name_ja"`
		Category     string `json:"category"`
		Price        int    `json:"price"`
		PrinterGroup string `json:"printer_group"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	now := time.Now().UTC().Format(time.RFC3339)
	_, err := s.db.Exec(`
		UPDATE menu_items SET name = ?, name_ja = ?, category = ?, price = ?, printer_group = ?, local_updated_at = ?
		WHERE id = ?
	`, body.Name, body.NameJa, body.Category, body.Price, body.PrinterGroup, now, id)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	s.auditLog(r, "menu.update", "menu_item", id, auditDetails(map[string]any{
		"name":  body.Name,
		"price": body.Price,
	}))
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok"})
}

func (s *Server) handleDeleteMenu(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	_, err := s.db.Exec("UPDATE menu_items SET is_active = 0 WHERE id = ?", id)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	s.auditLog(r, "menu.delete", "menu_item", id, "")
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok"})
}

func (s *Server) handleSeedMenu(w http.ResponseWriter, r *http.Request) {
	items := []struct {
		name, nameJa, category string
		price                  int
		group                  string
	}{
		{"Pho Bo", "フォーボー", "food", 90000, "kitchen"},
		{"Com Ga", "チキンライス", "food", 80000, "kitchen"},
		{"Bun Cha", "ブンチャー", "food", 85000, "kitchen"},
		{"Goi Cuon", "生春巻き", "food", 45000, "kitchen"},
		{"Banh Mi", "バインミー", "food", 35000, "kitchen"},
		{"Ca Phe Sua Da", "アイスカフェオレ", "drink", 35000, "bar"},
		{"Tra Da", "アイスティー", "drink", 15000, "bar"},
		{"Bia Saigon", "サイゴンビール", "drink", 25000, "bar"},
		{"Nuoc Chanh", "レモンジュース", "drink", 20000, "bar"},
		{"Che Ba Mau", "三色チェー", "dessert", 30000, "kitchen"},
	}
	now := time.Now().UTC().Format(time.RFC3339)
	for _, item := range items {
		s.db.Exec(`
			INSERT OR IGNORE INTO menu_items (id, name, name_ja, category, price, printer_group, local_updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?)
		`, uuid.New().String(), item.name, item.nameJa, item.category, item.price, item.group, now)
	}
	s.auditLog(r, "menu.seed", "menu_item", "", auditDetails(map[string]any{"count": len(items)}))
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok"})
}

// ─── Devices ──────────────────────────────────────────────────────────

// deviceStatusMaxAge bounds how stale a printer's reachability badge may get
// while the device page is open. The page polls every 5s; re-dialing that
// often would be pointless traffic, and never re-dialing means moving the
// workstation to another network leaves every badge permanently wrong.
const deviceStatusMaxAge = 30 * time.Second

func (s *Server) handleListDevices(w http.ResponseWriter, r *http.Request) {
	// Refresh in the background: the response must not wait on a dial. The
	// caller polls, so a status that lands a moment later still reaches the UI.
	if s.devices != nil {
		go s.devices.RefreshStale(deviceStatusMaxAge)
	}
	devices := s.devices.ListDevices()
	if devices == nil {
		devices = []printer.DeviceInfo{}
	}
	missing := s.devices.RolesWithoutPrinter()
	if missing == nil {
		missing = []printer.DeviceType{}
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"devices":       devices,
		"missing_roles": missing,
	})
}

func (s *Server) handleAddDevice(w http.ResponseWriter, r *http.Request) {
	var body struct {
		Name           string                 `json:"name"`
		Type           printer.DeviceType     `json:"type"`  // legacy single-role
		Roles          []printer.DeviceType   `json:"roles"` // multi-role (preferred)
		ConnectionType printer.ConnectionType `json:"connection_type"`
		Address        string                 `json:"address"`
		PaperWidth     int                    `json:"paper_width"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}

	// Accept either `roles` (preferred) or a single legacy `type`.
	roles := body.Roles
	if len(roles) == 0 && body.Type != "" {
		roles = []printer.DeviceType{body.Type}
	}
	if len(roles) == 0 {
		writeError(w, http.StatusBadRequest, "at least one role is required")
		return
	}

	// Validate here so a bad address answers 400 with the reason. AddPrinter
	// runs the same check, but routing its error through writeServerError
	// turned every rejected address into a 500 "internal error" — the caller
	// was told the workstation broke, not that the address was wrong.
	if err := printer.ValidateAddress(body.ConnectionType, body.Address); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}

	p, err := s.devices.AddPrinter(body.Name, roles, body.ConnectionType, body.Address, printer.PrinterConfig{
		PaperWidth: body.PaperWidth,
	})
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	// Reachability in the background: the response must not wait on a dial,
	// but by the time the UI refreshes its list the badge reflects reality
	// instead of the constructor's default "offline".
	go p.Probe()
	s.auditLog(r, "device.add", "device", p.ID(), auditDetails(map[string]any{
		"name":  body.Name,
		"roles": roles,
	}))
	writeJSON(w, http.StatusCreated, map[string]any{
		"device": map[string]any{
			"id": p.ID(), "type": p.Type(), "roles": p.Roles(), "name": p.Name(),
			"connection_type": body.ConnectionType, "address": body.Address,
			"status": p.Status(),
		},
	})
}

// handleUpdateDeviceRoles changes which roles an existing printer answers for.
// PATCH /api/devices/{id}/roles  { "roles": ["kitchen_printer","bar_printer"] }
//
// Lets a shop point a group at a device it already has — e.g. give the kitchen
// printer the `bar_printer` role when there is no separate bar station — instead
// of deleting and re-adding the device (which mints a new id).
func (s *Server) handleUpdateDeviceRoles(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	var body struct {
		Roles []printer.DeviceType `json:"roles"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	if len(body.Roles) == 0 {
		writeError(w, http.StatusBadRequest, "at least one role is required")
		return
	}
	if err := s.devices.UpdateDeviceRoles(id, body.Roles); err != nil {
		// Not-found / unknown-role / no-roles are caller errors, not 500s.
		msg := err.Error()
		if strings.Contains(msg, "not found") || strings.Contains(msg, "unknown role") ||
			strings.Contains(msg, "not a printer") || strings.Contains(msg, "at least one role") {
			writeError(w, http.StatusBadRequest, msg)
			return
		}
		writeServerError(w, r, err)
		return
	}
	s.auditLog(r, "device.update_roles", "device", id, auditDetails(map[string]any{
		"roles": body.Roles,
	}))
	writeJSON(w, http.StatusOK, map[string]any{"id": id, "roles": body.Roles})
}

func (s *Server) handleRemoveDevice(w http.ResponseWriter, r *http.Request) {
	id := r.PathValue("id")
	if err := s.devices.RemoveDevice(id); err != nil {
		writeServerError(w, r, err)
		return
	}
	s.auditLog(r, "device.remove", "device", id, "")
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok"})
}

func (s *Server) handleTestDevice(w http.ResponseWriter, r *http.Request) {
	p, ok := s.devices.GetPrinter(r.PathValue("id"))
	if !ok {
		writeError(w, http.StatusNotFound, "printer not found")
		return
	}
	if err := p.Connect(); err != nil {
		writeServerError(w, r, err)
		return
	}
	// Build the test print via the shared encoder so it uses the exact same
	// StarPRNT commands (alignment + ESC d 3 cut) as real receipts. Hand-rolled
	// ESC/POS bytes (GS V 0) are ignored by Star mC-Print3 and never eject paper
	// even though Print() succeeds — see #438.
	e := escpos.New()
	e.Align(escpos.AlignCenter).Line("=== TEST PRINT ===")
	e.Line(fmt.Sprintf("Time: %s", time.Now().Format("2006-01-02 15:04:05")))
	e.Line("Printer OK!")
	e.FullCut()
	if err := p.Print(e.Bytes()); err != nil {
		writeServerError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok"})
}

// ─── Sync ─────────────────────────────────────────────────────────────

func (s *Server) handleGetSync(w http.ResponseWriter, r *http.Request) {
	pending, _ := s.sync.PendingCount()
	failed, _ := s.sync.FailedCount()
	// Sync Queue card shows only the actionable backlog (pending/failed);
	// synced rows live in the trace feed below it.
	history, _ := s.sync.PendingHistory(50)
	if history == nil {
		history = []service.QueueItem{}
	}
	// Live sync activity feed across all flows (up/down/kds/conn). Optional
	// ?flow= filter narrows it; default returns the merged timeline.
	trace := s.sync.RecentTrace(100, r.URL.Query().Get("flow"))
	if trace == nil {
		trace = []service.SyncTraceEvent{}
	}
	// plan-042: dead-letter surface + rate-limit state for the recovery banner.
	deadLetterCount, _ := s.sync.DeadLetterCount()
	paymentOrphanCount, _ := s.sync.PaymentOrphanCount()
	deadLetters, _ := s.sync.DeadLetters(200)
	if deadLetters == nil {
		deadLetters = []service.DeadLetterItem{}
	}
	throttled, cooldownUntil := s.sync.ThrottleState()
	var cooldownStr any
	if throttled {
		cooldownStr = cooldownUntil.UTC().Format(time.RFC3339)
	}

	writeJSON(w, http.StatusOK, map[string]any{
		"status":               string(s.sync.Status()),
		"pending_count":        pending,
		"failed_count":         failed,
		"dead_letter_count":    deadLetterCount,
		"payment_orphan_count": paymentOrphanCount,
		"dead_letters":         deadLetters,
		"throttled":            throttled,
		"cooldown_until":       cooldownStr,
		"history":              history,
		"trace":                trace,
	})
}

func (s *Server) handleRetrySync(w http.ResponseWriter, r *http.Request) {
	count, err := s.sync.RetryFailed()
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"count": count})
}

// handleSyncDiscard marks a dead-lettered sync row reconciled-by-hand. plan-042.
func (s *Server) handleSyncDiscard(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.Atoi(r.PathValue("id"))
	if err != nil {
		writeError(w, http.StatusBadRequest, "invalid id")
		return
	}
	switch err := s.sync.Discard(id); {
	case err == nil:
		s.auditLog(r, "sync.discard", "sync_queue", strconv.Itoa(id), "")
		writeJSON(w, http.StatusOK, map[string]any{"id": id, "resolution": "discarded"})
	case errors.Is(err, sql.ErrNoRows):
		writeError(w, http.StatusNotFound, "sync row not found")
	case errors.Is(err, service.ErrNotDeadLettered):
		writeError(w, http.StatusConflict, "NOT_DEAD_LETTERED")
	default:
		writeServerError(w, r, err)
	}
}

// handleSyncReResolve returns a dead-lettered row to the active queue. plan-042.
func (s *Server) handleSyncReResolve(w http.ResponseWriter, r *http.Request) {
	id, err := strconv.Atoi(r.PathValue("id"))
	if err != nil {
		writeError(w, http.StatusBadRequest, "invalid id")
		return
	}
	switch err := s.sync.ReResolve(id); {
	case err == nil:
		s.auditLog(r, "sync.re_resolve", "sync_queue", strconv.Itoa(id), "")
		writeJSON(w, http.StatusOK, map[string]any{"id": id, "resolution": "re_resolved"})
	case errors.Is(err, sql.ErrNoRows):
		writeError(w, http.StatusNotFound, "sync row not found")
	case errors.Is(err, service.ErrNotDeadLettered):
		writeError(w, http.StatusConflict, "NOT_DEAD_LETTERED")
	default:
		writeServerError(w, r, err)
	}
}

// handleSyncRecoverOrder re-creates a 404-gone order on Cloud (plan-042 GAP-2).
func (s *Server) handleSyncRecoverOrder(w http.ResponseWriter, r *http.Request) {
	orderID := r.PathValue("orderId")
	if orderID == "" {
		writeError(w, http.StatusBadRequest, "missing orderId")
		return
	}
	switch err := s.sync.RecoverOrderOnCloud(r.Context(), orderID); {
	case err == nil:
		s.auditLog(r, "sync.recover_order", "order", orderID, "")
		writeJSON(w, http.StatusOK, map[string]any{"order_id": orderID, "resolution": "recovered"})
	case errors.Is(err, sql.ErrNoRows):
		writeError(w, http.StatusNotFound, "order not found")
	case errors.Is(err, service.ErrOrderStillExistsOnCloud):
		writeError(w, http.StatusConflict, "ORDER_STILL_EXISTS_ON_CLOUD")
	case errors.Is(err, service.ErrCloudUnreachable):
		writeError(w, http.StatusServiceUnavailable, "CLOUD_UNREACHABLE")
	default:
		writeServerError(w, r, err)
	}
}

// ─── Reports ──────────────────────────────────────────────────────────

func (s *Server) handleDailyReport(w http.ResponseWriter, r *http.Request) {
	date := r.URL.Query().Get("date")
	if date == "" {
		date = time.Now().Format("2006-01-02")
	}
	var totalOrders, paid, revenue, cancelled, avg int
	s.db.QueryRow("SELECT COUNT(*) FROM orders WHERE date(opened_at) = ?", date).Scan(&totalOrders)
	s.db.QueryRow("SELECT COUNT(*), COALESCE(SUM(total_amount), 0) FROM orders WHERE date(opened_at) = ? AND status = 'closed'", date).Scan(&paid, &revenue)
	s.db.QueryRow("SELECT COUNT(*) FROM orders WHERE date(opened_at) = ? AND status = 'voided'", date).Scan(&cancelled)
	if paid > 0 {
		avg = revenue / paid
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"date": date, "total_orders": totalOrders, "paid_orders": paid,
		"cancelled_orders": cancelled, "total_revenue": revenue, "avg_order_value": avg,
	})
}

func (s *Server) handlePopularItems(w http.ResponseWriter, r *http.Request) {
	date := r.URL.Query().Get("date")
	if date == "" {
		date = time.Now().Format("2006-01-02")
	}
	limit := 10
	if l := r.URL.Query().Get("limit"); l != "" {
		if n, err := strconv.Atoi(l); err == nil && n > 0 {
			limit = n
		}
	}
	rows, err := s.db.Query(`
		SELECT oi.menu_item_name, COALESCE(mi.category, ''), SUM(oi.quantity), SUM(oi.quantity * oi.unit_price)
		FROM order_items oi
		JOIN orders o ON o.id = oi.customer_order_id
		LEFT JOIN menu_items mi ON mi.id = oi.menu_item_id
		WHERE date(o.opened_at) = ? AND o.status != 'voided'
		GROUP BY oi.menu_item_name
		ORDER BY SUM(oi.quantity) DESC LIMIT ?
	`, date, limit)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	defer rows.Close()

	type item struct {
		Name     string `json:"name"`
		Category string `json:"category"`
		Quantity int    `json:"quantity"`
		Revenue  int    `json:"revenue"`
	}
	var items []item
	for rows.Next() {
		var i item
		rows.Scan(&i.Name, &i.Category, &i.Quantity, &i.Revenue)
		items = append(items, i)
	}
	if items == nil {
		items = []item{}
	}
	writeJSON(w, http.StatusOK, map[string]any{"items": items})
}

// ─── Settings ─────────────────────────────────────────────────────────

// readableSettingKeys is the allowlist of settings a GET /api/settings/{key}
// may return. The Cloud bearer token lives in this same table under
// `device_token` (plus other credential-ish keys), so an allowlist — not a
// denylist — is the safe default: a newly-added key is unreadable over HTTP
// until it is explicitly listed here. Before #84 the handler echoed ANY key,
// so a caller could read `device_token` and steal the cloud credential.
var readableSettingKeys = map[string]bool{
	"store_name":                true,
	"store_address":             true,
	"auto_print_bill":           true,
	"auto_print_kitchen":        true,
	"kds_show_only_fired":       true,
	"enable_quick_order":        true,
	"default_order_item_status": true,
	"cart_timeout_minutes":      true,
	"service_charge_rate":       true,
	"tax_rate":                  true,
	"default_tax_type_id":       true,
	"timezone":                  true,
	"device_id":                 true,
	"device_name":               true,
	"device_type":               true,
	"device_status":             true,
	"workstation_branch_id":     true,
	"cloud_api_url":             true,
}

func (s *Server) handleGetSetting(w http.ResponseWriter, r *http.Request) {
	key := r.PathValue("key")
	if !readableSettingKeys[key] {
		// Credential keys (notably device_token — the Cloud bearer) and any
		// non-allowlisted key are refused. 403 without disclosing whether the
		// key exists. (#84)
		writeError(w, http.StatusForbidden, "setting not readable")
		return
	}
	var value string
	err := s.db.QueryRow("SELECT value FROM settings WHERE key = ?", key).Scan(&value)
	if err == sql.ErrNoRows {
		writeJSON(w, http.StatusOK, map[string]any{"value": ""})
		return
	}
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"value": value})
}

func (s *Server) handleSetSetting(w http.ResponseWriter, r *http.Request) {
	key := r.PathValue("key")
	var body struct {
		Value string `json:"value"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	_, err := s.db.Exec(
		"INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
		key, body.Value,
	)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	s.auditLog(r, "settings.update", "settings", key, auditDetails(map[string]any{"value": body.Value}))
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok"})
}

// ─── WebSocket ────────────────────────────────────────────────────────

func (s *Server) handleWebSocket(w http.ResponseWriter, r *http.Request) {
	s.hub.ServeWS(w, r, s.authVerifier)
}

// ─── Device Auth ─────────────────────────────────────────────────────

func (s *Server) handleDevicePair(w http.ResponseWriter, r *http.Request) {
	var body struct {
		PairingCode string `json:"pairing_code"`
	}
	if err := readJSON(r, &body); err != nil {
		writeError(w, http.StatusBadRequest, err.Error())
		return
	}
	if body.PairingCode == "" {
		writeError(w, http.StatusBadRequest, "pairing_code is required")
		return
	}

	// Build device_info
	hostname, _ := os.Hostname()
	deviceInfo := map[string]string{
		"hostname": hostname,
		"os":       runtime.GOOS,
		"arch":     runtime.GOARCH,
	}

	// Get cloud API URL from config (nil-safe: some test servers wire the DB
	// and auth middleware but not the config Manager; the settings fallback
	// below still resolves the URL).
	cloudURL := ""
	if s.config != nil {
		cloudURL = s.config.Get().CloudAPIURL
	}
	if cloudURL == "" {
		// Try from settings table
		var val string
		if err := s.db.QueryRow("SELECT value FROM settings WHERE key = 'cloud_api_url'").Scan(&val); err == nil && val != "" {
			cloudURL = val
		}
	}
	if cloudURL == "" {
		writeError(w, http.StatusBadRequest, "cloud_api_url is not configured")
		return
	}

	// Proxy to cloud API
	payload, _ := json.Marshal(map[string]any{
		"pairing_code": body.PairingCode,
		"device_info":  deviceInfo,
	})

	// Cloud exposes the pairing endpoint at /api/v1/devices/pair (public,
	// shared by all device types: tms, kiosk, workstation). The pairing_code
	// itself encodes the device type — backend looks up the row by code.
	req, err := http.NewRequestWithContext(r.Context(), "POST", cloudURL+"/api/v1/devices/pair", bytes.NewReader(payload))
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	client := &http.Client{Timeout: 15 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		writeError(w, http.StatusBadGateway, fmt.Sprintf("cloud API unreachable: %v", err))
		return
	}
	defer resp.Body.Close()

	respBody, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		writeServerError(w, r, err)
		return
	}

	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusCreated {
		// Forward the error from cloud API
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(resp.StatusCode)
		w.Write(respBody)
		return
	}

	// Parse response — pull full device metadata so workstation knows its
	// own branch/org/status. Earlier code only kept id/name/type, which
	// silently broke branch-isolation in AuthMiddleware (workstation_branch_id
	// always empty → branchOK() always returned true).
	var cloudResp struct {
		DeviceToken string `json:"device_token"`
		Device      struct {
			ID             string `json:"id"`
			Name           string `json:"name"`
			Type           string `json:"type"`
			Status         string `json:"status"`
			BranchID       string `json:"branch_id"`
			OrganizationID string `json:"organization_id"`
		} `json:"device"`
	}
	if err := json.Unmarshal(respBody, &cloudResp); err != nil {
		writeError(w, http.StatusInternalServerError, "invalid cloud response")
		return
	}

	// Device-type guard: this app is a WORKSTATION. Cloud's /devices/pair accepts any
	// device type (the pairing code encodes the type), so without this check a
	// POS/kiosk/tms code would pair the workstation AS that type — its stored
	// identity + device token would then carry the wrong scope and every LAN
	// client would authenticate against a mis-typed workstation. Reject BEFORE
	// persisting anything.
	if cloudResp.Device.Type != "workstation" {
		s.auditLog(r, "device.pair_rejected_type", "device", cloudResp.Device.ID, auditDetails(map[string]any{
			"expected": "workstation",
			"got":      cloudResp.Device.Type,
		}))
		writeJSON(w, http.StatusUnprocessableEntity, map[string]any{
			"error":    "device_type_mismatch",
			"expected": "workstation",
			"got":      cloudResp.Device.Type,
		})
		return
	}
	// Gap-A: a 2xx with an empty token or branch must not become "paired".
	// handleDeviceStatus derives `paired` from token presence, and an empty
	// branch_id fails-close branchOK for every LAN client — a silently broken
	// pairing. Treat a malformed success as a bad cloud response.
	if cloudResp.DeviceToken == "" || cloudResp.Device.BranchID == "" {
		writeError(w, http.StatusBadGateway, "invalid cloud response")
		return
	}

	// Store device info in settings. workstation_branch_id is the key
	// AuthMiddleware reads via Server.workstationBranchID() for branch
	// isolation checks against LAN clients.
	settings := map[string]string{
		"device_token":          cloudResp.DeviceToken,
		"device_id":             cloudResp.Device.ID,
		"device_name":           cloudResp.Device.Name,
		"device_type":           cloudResp.Device.Type,
		"device_status":         cloudResp.Device.Status,
		"workstation_branch_id": cloudResp.Device.BranchID,
		"organization_id":       cloudResp.Device.OrganizationID,
		"cloud_api_url":         cloudURL,
		"sync.auth_lost":        "0", // fresh pairing clears any prior auth-lost banner (#437)
	}
	for key, value := range settings {
		s.db.Exec(
			"INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
			key, value,
		)
	}

	// plan-818: if a prior forced unpair KEPT unsynced data (unpair.prev_branch_id
	// set) and this re-pair is to the SAME branch, clear the gate so the
	// reconcilers resume pushing the kept orders/payments. A DIFFERENT-branch
	// re-pair leaves the gate set, so branch-A's data is never auto-pushed to
	// branch-B (the operator recovers it manually / after re-pairing back).
	var prevBranch string
	s.db.QueryRow("SELECT COALESCE(value, '') FROM settings WHERE key = 'unpair.prev_branch_id'").Scan(&prevBranch)
	if prevBranch != "" && prevBranch == cloudResp.Device.BranchID {
		s.db.Exec("INSERT INTO settings (key, value) VALUES ('unpair.prev_branch_id', '') ON CONFLICT(key) DO UPDATE SET value = ''")
	}

	// UPSERT into local omnify devices table so DeviceSeenBuffer.Touch has
	// a row to UPDATE for this workstation's own heartbeat.
	if s.seenBuffer != nil {
		if regErr := s.seenBuffer.Register(service.DeviceInfo{
			ID:             cloudResp.Device.ID,
			Name:           cloudResp.Device.Name,
			Type:           cloudResp.Device.Type,
			Status:         cloudResp.Device.Status,
			BranchID:       cloudResp.Device.BranchID,
			OrganizationID: cloudResp.Device.OrganizationID,
		}); regErr != nil {
			slog.Warn("pair device register", "err", regErr, "device_id", cloudResp.Device.ID)
		}
	}

	s.auditLog(r, "device.pair", "device", cloudResp.Device.ID, auditDetails(map[string]any{
		"name":   cloudResp.Device.Name,
		"branch": cloudResp.Device.BranchID,
	}))

	// Post-pair immediate pulls — fire-and-forget so the pair response returns
	// immediately. The regular 60 s tick would eventually populate these, but
	// the UX expectation is that menu/tables/zones appear as soon as the
	// operator finishes pairing, not after an arbitrary wait.
	if s.puller != nil {
		go func(deviceID string) {
			ctx := context.Background()

			// Pull menu, zones, and tables immediately so the WS UI shows data
			// right after pairing without waiting for the next 60 s tick.
			if err := s.puller.PullMenu(ctx); err != nil {
				slog.Warn("post-pair menu pull failed", "err", err, "device_id", deviceID)
			}
			if err := s.puller.PullZones(ctx); err != nil {
				slog.Warn("post-pair zones pull failed", "err", err, "device_id", deviceID)
			}
			if err := s.puller.PullTables(ctx); err != nil {
				slog.Warn("post-pair tables pull failed", "err", err, "device_id", deviceID)
			}

			// Recovery: pull 30 days of historical orders so a re-pair after crash
			// rebuilds local audit trail + reports. Idempotent under SyncPuller's loop.
			n, err := s.puller.Recover(ctx, 30*24*time.Hour)
			if err != nil {
				slog.Warn("recovery pull orders failed", "err", err, "device_id", deviceID)
				return
			}
			if n > 0 {
				slog.Info("recovery restored orders", "count", n, "device_id", deviceID)
			}
		}(cloudResp.Device.ID)
	}

	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	w.Write(respBody)
}

func (s *Server) handleDeviceStatus(w http.ResponseWriter, r *http.Request) {
	var token, deviceName, deviceType, authLost string
	s.db.QueryRow("SELECT value FROM settings WHERE key = 'device_token'").Scan(&token)
	s.db.QueryRow("SELECT value FROM settings WHERE key = 'device_name'").Scan(&deviceName)
	s.db.QueryRow("SELECT value FROM settings WHERE key = 'device_type'").Scan(&deviceType)
	s.db.QueryRow("SELECT value FROM settings WHERE key = 'sync.auth_lost'").Scan(&authLost)

	writeJSON(w, http.StatusOK, map[string]any{
		"paired":       token != "",
		"device_name":  deviceName,
		"device_type":  deviceType,
		"needs_repair": token == "" && authLost == "1", // cloud revoked token mid-session → re-pair
		"token":        token,                          // localOnly — safe to expose to Wails UI for WS auth
	})
}

// unsyncedSummary is a snapshot of local data that has NOT reached Cloud yet.
// It drives the unpair guard (plan-818). The "money at risk" figure comes from
// the payments table keyed on cloud_id (the ONLY reliably-stamped signal —
// payments.synced_at is a dead column that is never written), and the "real
// money" definition mirrors sumActivePaymentsForOrder (#562: phantom-expired
// pending is excluded). All reads go through direct SQL on s.db so the guard
// never depends on a non-nil SyncEngine.
type unsyncedSummary struct {
	Payments     int   `json:"unsynced_payments"`
	Amount       int64 `json:"unsynced_amount"`
	Refunds      int   `json:"unsynced_refunds"`
	Orders       int   `json:"unsynced_orders"`
	Items        int   `json:"unsynced_items"`
	QueuePending int   `json:"queue_pending"`
	QueueDead    int   `json:"queue_dead_letter"`
	HasUnsynced  bool  `json:"has_unsynced"`
}

func (s *Server) unsyncedSummary() unsyncedSummary {
	var sum unsyncedSummary
	now := time.Now().UTC().Format(time.RFC3339Nano)

	// Money at risk: payments Cloud never acknowledged (cloud_id empty), counting
	// only real money — confirmed, plus pending that has NOT expired (an abandoned
	// non-auto-confirm auth past expires_at is phantom revenue, #562).
	s.db.QueryRow(`
		SELECT COUNT(*), COALESCE(SUM(amount - refunded_amount), 0)
		FROM payments
		WHERE (cloud_id IS NULL OR cloud_id = '')
		  AND status IN ('pending', 'confirmed', 'succeeded')
		  AND (
		        status IN ('confirmed', 'succeeded')
		     OR expires_at IS NULL OR expires_at = ''
		     OR datetime(expires_at) > datetime(?)
		  )`, now).Scan(&sum.Payments, &sum.Amount)

	// Refund sync-state has no cloud_id/synced_at writeback, so infer an unsynced
	// refund from its still-active queue row.
	s.db.QueryRow(`SELECT COUNT(*) FROM sync_queue
		WHERE entity_type = 'payment' AND operation = 'refund'
		  AND synced_at IS NULL AND dead_lettered_at IS NULL`).Scan(&sum.Refunds)

	// orders/order_items.synced_at ARE reliably stamped (on push AND on pull), so
	// a NULL here is genuinely a local row Cloud has not seen. Voided rows carry
	// no recoverable revenue, so they don't block.
	s.db.QueryRow(`SELECT COUNT(*) FROM orders
		WHERE synced_at IS NULL AND status != 'voided' AND voided_at IS NULL`).Scan(&sum.Orders)
	s.db.QueryRow(`SELECT COUNT(*) FROM order_items
		WHERE synced_at IS NULL AND (status IS NULL OR status != 'voided')`).Scan(&sum.Items)

	s.db.QueryRow(`SELECT COUNT(*) FROM sync_queue
		WHERE synced_at IS NULL AND dead_lettered_at IS NULL`).Scan(&sum.QueuePending)
	s.db.QueryRow(`SELECT COUNT(*) FROM sync_queue
		WHERE dead_lettered_at IS NOT NULL AND resolved_at IS NULL`).Scan(&sum.QueueDead)

	sum.HasUnsynced = sum.Payments > 0 || sum.Refunds > 0 || sum.Orders > 0 ||
		sum.Items > 0 || sum.QueuePending > 0 || sum.QueueDead > 0
	return sum
}

// handleDeviceUnpair tears down the workstation's pairing.
//
// plan-818: BEFORE touching anything it checks unsyncedSummary(). If unsynced
// revenue exists and the caller did not pass ?force=true, it returns 409 with
// the counts + amount and changes NOTHING (no cloud revoke, no wipe, no token
// clear) — the operator sees exactly how much cash is at risk. A forced unpair
// with unsynced data KEEPS the money/recovery tables (orders / order_items /
// payments / payment_refunds / sync_queue) on disk so the reconcilers re-sync
// them after re-pair; only the branch-scoped Cloud mirror is wiped. A clean
// unpair (nothing unsynced) wipes everything as before so the workstation
// starts fresh.
//
// See docs/bugs/2026-05-21-incomplete-unpair-flow.md for the original bug class.
func (s *Server) handleDeviceUnpair(w http.ResponseWriter, r *http.Request) {
	// Capture identity BEFORE clearing — audit log + cloud notify need them.
	var deviceID, branchID, token string
	s.db.QueryRow("SELECT value FROM settings WHERE key = 'device_id'").Scan(&deviceID)
	s.db.QueryRow("SELECT value FROM settings WHERE key = 'workstation_branch_id'").Scan(&branchID)
	s.db.QueryRow("SELECT value FROM settings WHERE key = 'device_token'").Scan(&token)

	// GUARD (plan-818): block a destructive unpair while unsynced revenue exists,
	// UNLESS explicitly forced. This runs BEFORE the cloud self-revoke so a
	// blocked attempt leaves the pairing (and its token) fully intact.
	sum := s.unsyncedSummary()
	force := r.URL.Query().Get("force") == "true"
	if sum.HasUnsynced && !force {
		s.auditLog(r, "device.unpair_blocked", "device", deviceID, auditDetails(map[string]any{
			"branch":            branchID,
			"unsynced_payments": sum.Payments,
			"unsynced_amount":   sum.Amount,
			"unsynced_orders":   sum.Orders,
			"unsynced_items":    sum.Items,
			"queue_pending":     sum.QueuePending,
			"queue_dead_letter": sum.QueueDead,
		}))
		writeJSON(w, http.StatusConflict, map[string]any{
			"error":             "unsynced_data_present",
			"message":           "Unpair blocked: this device holds revenue that has not reached Cloud. Retry with force=true to unpair anyway — the transaction data is kept on disk and re-syncs after you pair again.",
			"unsynced_payments": sum.Payments,
			"unsynced_amount":   sum.Amount,
			"unsynced_refunds":  sum.Refunds,
			"unsynced_orders":   sum.Orders,
			"unsynced_items":    sum.Items,
			"queue_pending":     sum.QueuePending,
			"queue_dead_letter": sum.QueueDead,
			"has_unsynced":      true,
		})
		return
	}

	// keepData: a forced unpair that still has unsynced revenue. Preserve the
	// money/recovery tables; wipe only the Cloud mirror.
	keepData := force && sum.HasUnsynced

	// Best-effort cloud notify in background — don't block the unpair on cloud
	// being unreachable. Only fires on the path that actually proceeds (never on
	// a 409 block).
	if token != "" {
		go s.notifyCloudUnpair(token)
	}

	// Clear device identity/credential settings so the UI lands on the pair
	// screen. cloud_api_url intentionally kept so operator can re-pair without
	// re-entering URL.
	keysToClear := []string{
		"device_token", "device_id", "device_name", "device_type",
		"device_status", "workstation_branch_id", "organization_id",
		"last_sync_at",
	}

	// Branch-scoped Cloud mirror — always wiped (stale/wrong after re-pair).
	// auth_token_cache invalidation is critical: cached kiosk tokens from the
	// old branch must not authenticate against a new pairing.
	wipeTables := []string{
		"menu_items", "zones", "tables",
		"inventory_lots", "devices", "auth_token_cache",
	}
	if !keepData {
		// Clean unpair (or nothing unsynced): also wipe the transaction tables so
		// the workstation starts fresh. Order matters for FK: order_items → orders.
		wipeTables = append([]string{"order_items", "orders", "sync_queue"}, wipeTables...)
	}

	err := s.db.Transaction(func(tx *sql.Tx) error {
		for _, key := range keysToClear {
			if _, err := tx.Exec(
				"INSERT INTO settings (key, value) VALUES (?, '') ON CONFLICT(key) DO UPDATE SET value = ''",
				key,
			); err != nil {
				return err
			}
		}
		if keepData {
			// Record which branch's unsynced data we kept so the reconcilers only
			// auto-resume on a SAME-branch re-pair (guards cross-branch push).
			if _, err := tx.Exec(
				"INSERT INTO settings (key, value) VALUES ('unpair.prev_branch_id', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
				branchID,
			); err != nil {
				return err
			}
		}
		for _, table := range wipeTables {
			// Skip tables that don't exist on this DB (e.g. an omnify mirror table
			// not yet created). The original code claimed to do this but errored
			// out instead; being tolerant keeps unpair from 500ing on a partially
			// migrated DB while still wiping every table that IS present.
			var exists int
			if err := tx.QueryRow(
				"SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?", table,
			).Scan(&exists); err != nil {
				continue // sql.ErrNoRows → table absent → nothing to wipe
			}
			if _, err := tx.Exec("DELETE FROM " + table); err != nil {
				return fmt.Errorf("wipe %s: %w", table, err)
			}
		}
		return nil
	})
	if err != nil {
		slog.Error("unpair wipe failed", "err", err, "device_id", deviceID)
		writeServerError(w, r, err)
		return
	}

	if keepData {
		s.auditLog(r, "device.unpair_forced", "device", deviceID, auditDetails(map[string]any{
			"branch":      branchID,
			"kept_orders": sum.Orders,
			"kept_amount": sum.Amount,
			"note":        "transaction data kept on disk for recovery after re-pair",
		}))
	} else {
		s.auditLog(r, "device.unpair", "device", deviceID, auditDetails(map[string]any{"branch": branchID}))
	}
	writeJSON(w, http.StatusOK, map[string]any{
		"status":          "ok",
		"device_id":       deviceID,
		"data_kept":       keepData,
		"unsynced_amount": sum.Amount,
	})
}

// notifyCloudUnpair POSTs /api/v1/workstation/self-revoke with the device's own
// token so Cloud marks status=revoked + clears the token. Fire-and-forget;
// errors logged but don't block the local unpair.
func (s *Server) notifyCloudUnpair(token string) {
	cloudURL := s.cloudAPIURL()
	if cloudURL == "" {
		return
	}
	req, err := http.NewRequest("POST", cloudURL+"/api/v1/workstation/self-revoke", nil)
	if err != nil {
		slog.Warn("cloud self-revoke build request", "err", err)
		return
	}
	req.Header.Set("Authorization", "Bearer "+token)
	req.Header.Set("Accept", "application/json")

	client := &http.Client{Timeout: 5 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		slog.Warn("cloud self-revoke unreachable", "err", err)
		return
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		slog.Warn("cloud self-revoke non-200", "status", resp.StatusCode)
		return
	}
	slog.Info("cloud self-revoke success")
}

// GetDeviceToken returns the stored device token for cloud API authentication.
func (s *Server) GetDeviceToken() string {
	var token string
	s.db.QueryRow("SELECT value FROM settings WHERE key = 'device_token'").Scan(&token)
	return token
}

// clearDeviceToken wipes the device token from settings so the next request
// to the Wails UI lands on the pair screen instead of retrying with a bad token.
// Called once by SyncPuller when cloud returns 401.
func (s *Server) clearDeviceToken() {
	// Clear the invalid credential + identity, but deliberately KEEP
	// device_name (and workstation_branch_name, never in this list) so the
	// re-pair banner can tell the operator WHICH device/branch lost its link.
	// Set sync.auth_lost so the UI distinguishes "cloud revoked us mid-session"
	// (needs re-pair — show a warning) from a pristine first-time pairing.
	// Without this the workstation became a silent zombie — LAN server up,
	// sync dead — until someone happened to restart the app. See #437.
	keys := []string{"device_token", "device_id", "device_type", "device_status", "workstation_branch_id"}
	for _, key := range keys {
		s.db.Exec("INSERT INTO settings (key, value) VALUES (?, '') ON CONFLICT(key) DO UPDATE SET value = ''", key)
	}
	s.db.Exec("INSERT INTO settings (key, value) VALUES ('sync.auth_lost', '1') ON CONFLICT(key) DO UPDATE SET value = '1'")
	slog.Warn("device token cleared after cloud 401 — sync stopped, workstation must re-pair")
}

// ─── Audit & Monitor ─────────────────────────────────────────────────

func (s *Server) handleAuditLog(w http.ResponseWriter, r *http.Request) {
	if s.audit == nil {
		writeJSON(w, http.StatusOK, map[string]any{"entries": []any{}})
		return
	}

	q := r.URL.Query()
	filter := audit.AuditFilter{
		Action:     q.Get("action"),
		EntityType: q.Get("entity_type"),
	}
	if from := q.Get("from"); from != "" {
		if t, err := time.Parse(time.RFC3339, from); err == nil {
			filter.From = t
		}
	}
	if to := q.Get("to"); to != "" {
		if t, err := time.Parse(time.RFC3339, to); err == nil {
			filter.To = t
		}
	}
	if l := q.Get("limit"); l != "" {
		if n, err := strconv.Atoi(l); err == nil && n > 0 {
			filter.Limit = n
		}
	}

	entries, err := s.audit.Query(filter)
	if err != nil {
		writeServerError(w, r, err)
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"entries": entries})
}

func (s *Server) handleMonitor(w http.ResponseWriter, r *http.Request) {
	if s.monitor == nil {
		writeJSON(w, http.StatusOK, map[string]any{})
		return
	}
	writeJSON(w, http.StatusOK, s.monitor.Snapshot())
}

func (s *Server) handleHealthCheck(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{
		"status": "ok",
		"time":   time.Now().UTC().Format(time.RFC3339),
	})
}

// auditLog is a convenience wrapper that extracts the client IP from the request.
func (s *Server) auditLog(r *http.Request, action, entityType, entityID, details string) {
	if s.audit == nil {
		return
	}
	ip := clientIP(r)
	actor := "admin"
	if ip == "127.0.0.1" || ip == "::1" {
		actor = "system"
	}
	s.audit.Log(actor, action, entityType, entityID, details, ip)
}

// auditDetails marshals the details payload to JSON safely. The previous
// pattern across this file built JSON via fmt.Sprintf(`{"name":"%s"}`,
// body.Name) — a value containing `\` or `"` produced invalid JSON,
// and a deliberate payload like `evil","admin":"true` forged extra
// fields. json.Marshal escapes correctly; on the very rare encode
// failure we fall back to an empty object so the audit row still lands.
func auditDetails(m map[string]any) string {
	b, err := json.Marshal(m)
	if err != nil {
		slog.Warn("audit details marshal failed", "err", err)
		return "{}"
	}
	return string(b)
}

// clientIP returns the requester's RemoteAddr host.
//
// IMPORTANT: this intentionally IGNORES `X-Forwarded-For`. Workstation
// has no reverse proxy in front of it (LAN-only, mDNS-discovered);
// honoring XFF would let a LAN client set the header and spoof the IP
// recorded in audit logs. The earlier implementation read XFF first —
// which made device IDs in `device.pair` / `device.unpair` / payment
// trails plausibly forgeable by any device with a token. Drop the
// XFF read entirely.
func clientIP(r *http.Request) string {
	host, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		return r.RemoteAddr
	}
	return host
}

// ─── Helpers ──────────────────────────────────────────────────────────

func writeJSON(w http.ResponseWriter, status int, data any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(data)
}

func writeError(w http.ResponseWriter, status int, message string) {
	writeJSON(w, status, map[string]string{"message": message})
}

// requirePairedShop rejects a /pos/* request whose X-Shop-Slug names a shop
// other than this workstation's paired branch.
//
// The workstation is a single-shop device: local handlers serve the paired
// branch and the Cloud proxy rewrites X-Shop-Slug to the paired slug. Without
// this guard, pos-web at /shop/<any-slug> — a typo, a stale bookmark, or a
// genuinely non-existent shop — is silently served the paired branch's tables,
// orders and till while the URL claims a different shop (#544). Worse, a URL
// naming a *real but different* branch would ring the cashier up against the
// wrong shop under a mislabeled header.
//
// Cloud's ResolvePosShop returns 404 "Shop not found." for an unknown slug;
// this makes LAN mode behave identically so pos-web's ShiftGate surfaces the
// same error instead of dropping the operator into the open-shift flow.
//
// Fail-open when the paired slug can't be resolved (unpaired, or `branches`
// table missing in tests) and when the header is absent (workstation-native
// calls) — both preserve prior behavior.
func (s *Server) requirePairedShop(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if slug := r.Header.Get("X-Shop-Slug"); slug != "" {
			if paired := s.workstationBranchSlug(); paired != "" && !strings.EqualFold(slug, paired) {
				writeError(w, http.StatusNotFound, "Shop not found.")
				return
			}
		}
		next.ServeHTTP(w, r)
	})
}

// writeServerError handles the 500-path — never echo the raw error to
// the LAN client (modernc.org/sqlite errors include the full SQL with
// bound parameter hints, leaking the local replica schema for
// fingerprinting; Go errors may also wrap file paths or internal IDs).
// Log it server-side with the request URL for correlation, return a
// generic message. Use this in place of
// `writeError(w, http.StatusInternalServerError, err.Error())`.
func writeServerError(w http.ResponseWriter, r *http.Request, err error) {
	slog.Error("server error",
		"method", r.Method,
		"path", r.URL.Path,
		"err", err,
	)
	writeError(w, http.StatusInternalServerError, "internal error")
}

func readJSON(r *http.Request, v any) error {
	body, err := io.ReadAll(io.LimitReader(r.Body, 1<<20))
	if err != nil {
		return err
	}
	return json.Unmarshal(body, v)
}
