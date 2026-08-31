# workstation-app - Workstation Desktop Application

Go + Wails v3 desktop app for restaurant/shop workstation management.

## Tech Stack

- **Backend**: Go 1.25, Wails v3 (alpha.74)
- **Frontend**: React 19, TypeScript, Tailwind CSS v4, Vite
- **Database**: SQLite via `modernc.org/sqlite` (pure Go, no CGO)
- **Printers**: ESC/POS protocol (USB + TCP)

## Development

```sh
# Dev mode with hot reload
make dev
# or directly:
~/go/bin/wails3 dev

# Build for current platform
make build

# Run Go tests
make test

# Generate Wails bindings after changing Go services
~/go/bin/wails3 generate bindings
```

## Project Structure

- `cmd/workstation/main.go` - App entry point, wires all services
- `frontend.go` - Root-level embedded frontend assets (required for go:embed)
- `migrations.go` - Root-level embed for omnify-generated SQL (must live at root for go:embed)
- `api/` - OpenAPI spec
- `internal/store/migrations/` - **Hand-written** SQL migrations (versions 1..999) — source of truth for workstation-owned schema (orders, payments, auth_token_cache, etc.)
- `migrations/omnify/` - **Omnify-generated** SQL migrations (versions 1000+) — cloud-mirror tables (branches, customer_orders, products, etc.). Regenerate via `npm run omnify:gen` at the umbrella root.
- `sqlc.yaml` - sqlc configuration for code generation
- `internal/domain/` - Business types and interfaces (hand-written)
- `internal/store/` - Database layer (connection, migrations, queries)
- `internal/handler/` - HTTP handlers, routes, middleware, WebSocket, Swagger
- `internal/service/` - Business logic (order engine, print formatting, sync engine)
- `internal/printer/` - ESC/POS printer support (manager, driver, encoding)
- `internal/config/` - Config management (~/.ws-app/)
- `internal/audit/` - Audit logging
- `internal/monitor/` - Load monitoring
- `internal/discovery/` - mDNS advertisement
- `internal/generated/sqlc/` - sqlc auto-generated code (placeholder)
- `internal/generated/oapi/` - oapi-codegen auto-generated code (placeholder)
- `frontend/` - React app served by Wails

## Security Middleware Ring

Request flow into the LAN server (`internal/handler/server.go::New`):

```
ListenAndServe → lanOnly → corsMiddleware → mux → ( per-route wraps ) → handler
```

- `lanOnly` (outer ring): Rejects requests whose RemoteAddr is not RFC1918 / loopback / IPv6 ULA / link-local. The bind addr is 0.0.0.0:8080 so this is the only barrier against a misconfigured router or VPN bridge that exposes the workstation to the public internet.
- `corsMiddleware`: sets baseline security headers on every response (`X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Cross-Origin-Opener-Policy: same-origin`) + LAN CORS allow-list for private/loopback origins.

Per-route wraps:

- `localOnly` (`/api/device/{pair,status,unpair}`, `/api/audit`, `/api/monitor*`): loopback-only. These are admin endpoints called only from the Wails frontend via `http://localhost:8080`.
- `pairLimiter.Middleware` (`POST /api/device/pair`): 5/min/IP, burst 5 — defense in depth against pairing-code brute force even though the endpoint is already localOnly.
- `paymentLimiter.Middleware` (`POST /api/v1/{kiosk,pos}/payments` + confirm/fail): 60/min/IP, burst 10.
- `authed = authMW.Wrap`: Bearer-token verify against Cloud with cache + stale-fallback ladder.
- `corsForBrowser` (`/api/v1/{pos,kds}/*`): per-origin allow-list for browser clients (pos-web, kds-web). Production origins must be HTTPS + match `.godx.jp` suffix.

The WS path (`/ws`) goes through `lanOnly + corsMiddleware` like everything else, then uses `authMiddlewareVerifier` (adapter in `ws.go`) so the first-message auth handshake reuses the same cache + stale ladder as HTTP. Cloud outage no longer cuts LAN realtime. WS `auth_ok` payload includes `stale: true` when the session was accepted via stale cache so clients can show a degraded-mode banner.

Audit log writes go through `auditLog(r, ...)` which calls `clientIP(r)` — XFF is intentionally NOT honored (workstation has no reverse proxy; XFF would let a LAN client spoof). Audit `details` payloads use `auditDetails(map[string]any)` (json.Marshal) instead of `fmt.Sprintf` so a value containing `"` or `\` can't forge JSON keys.

5xx responses go through `writeServerError(w, r, err)` — the raw error is logged server-side via `slog.Error` with method+path correlation, the client gets a generic "internal error" body. The previous `writeError(w, 500, err.Error())` pattern leaked SQLite SQL strings to LAN clients.

`http.Server.ReadHeaderTimeout: 5s` is set as slowloris defense.

## Unpair guard + kept-data recovery (plan-818)

`POST /api/device/unpair` (`handleDeviceUnpair`, `localOnly`) no longer blindly wipes the transaction tables — that path silently destroyed offline cash. Now:

- **Guard first.** `unsyncedSummary()` (direct SQL on `s.db`) measures money at risk from the **`payments` table keyed on `cloud_id IS NULL/''`** — NOT `sync_queue` and NOT `payments.synced_at` (a dead column nothing ever stamps). A payment commits before its enqueue and enqueue failure is non-fatal, so `cloud_id` is the only reliable "Cloud saw it" signal. "Real money" mirrors `sumActivePaymentsForOrder` (phantom-expired pending excluded, #562). Orders/items use their reliably-stamped `synced_at`. Refunds are inferred from an unresolved `payment.refund` queue row.
- If `HasUnsynced && !force` → **HTTP 409** `unsynced_data_present` with counts + `unsynced_amount`, and **nothing changes** (no cloud self-revoke, no wipe, no token clear). The guard runs BEFORE `notifyCloudUnpair` so a blocked attempt keeps the token valid.
- `?force=true` with unsynced data → **keeps** `orders/order_items/payments/payment_refunds/sync_queue` on disk (only the branch-scoped Cloud mirror `menu_items/zones/tables/inventory_lots/devices/auth_token_cache` is wiped) + records `settings['unpair.prev_branch_id']`. Nothing unsynced → full wipe as before (start fresh). The wipe skips tables absent from the DB instead of 500ing.
- **Recovery is automatic, not via the SyncRecovery UI.** After re-pairing to the SAME branch (which clears `unpair.prev_branch_id` in `handleDevicePair`), the backfill reconcilers re-push the kept rows. `cloudPost` re-stamps the current device token for `/api/v1/workstation/*`, and a new `reconcileUnsyncedPayments()` re-enqueues a fresh `payment.create` (reusing the payment's `idempotency_key`) for workstation-origin payments with no live queue row — this also fixes the pre-existing "payment orphan" gap. All three backfill reconcilers are gated by `shouldAutoRecover()` (`prev_branch_id` empty or == current branch) so a cross-branch re-pair never pushes branch-A data onto branch-B.
- **Origin tracking:** migration `040` adds `payments.sync_target` ('workstation' | 'kiosk'), set at insert. The reconciler only heals `'workstation'` rows — kiosk payments (terminal-baked token, un-re-stampable) are a follow-up; their money still blocks unpair via the guard so it is never silently lost.

Audit: `device.unpair_blocked` / `device.unpair_forced` (with amount) / `device.unpair`.

## Cashier shift endpoints (plan-030 / 032)

`/api/v1/pos/till/sessions/*` are NOT served locally by workstation — they
fall through to the catch-all proxy in `internal/handler/routes.go` (~line
223) which forwards every unmirrored `/api/v1/pos/*` request to Cloud
verbatim, preserving the cashier's Bearer token + `X-Shop-Slug` header +
request body. This covers:

| Plan | Endpoint | Cloud handler |
|---|---|---|
| plan-030 | `POST /pos/till/sessions` (open) | `TillSessionController@store` |
| plan-030 | `POST /pos/till/sessions/{id}/{cash-events,draft,close,abandon}` | same controller |
| plan-030 | `GET /pos/till/{current,denominations,tender-types}` + `GET /pos/till/sessions/{id}` | `TillController` / `TillSessionController` |
| plan-032 | `POST /pos/till/sessions/{id}/force-abandon` | `TillSessionController@forceAbandon` (manager-only) |
| plan-032 | `POST /pos/till/sessions/{id}/manual-settle` | `TillSessionController@manualSettle` (manager-only) |
| plan-032 | `GET /pos/till/sessions/stale[?filter=…][?group_by=actor]` | `TillSessionController@stale` (manager-only) |

The TillSession shape with all plan-032 fields (`expired_at`,
`force_abandoned`, `force_abandon_reason_code`, etc.) reaches pos-web /
admin-web unchanged because the proxy doesn't transform JSON. Workstation's
Go domain enums for `TillSessionStatus`, `ExpireReason`, and
`ForceAbandonReasonCode` are regenerated via `omnify:gen` at the umbrella
root and live under `internal/domain/generated/enums/` for any future
local consumer.

Proxy guarantees verified by `internal/handler/pos_cloud_proxy_test.go`:
`TestPosCloudProxy_Plan032_*` covers POST body forwarding for
force-abandon + manual-settle, GET query-string forwarding for the stale
listing, and the actor-summary mode.

## Cashier shift — gap reconciliation (plan-044 R2)

`POST /pos/till/sessions` (open) is served **locally** by
`handleLocalPosTillOpenSession` (SQLite `till_sessions` + sync UP via
`till_session.open`), not proxied — the session id is verbatim local↔cloud
(Cloud upserts by the workstation-supplied id). Two read endpoints back the
pos-web reconciliation UI, both served from the LOCAL replica (see
`local_pos_till.go`) so kiosk/customer LAN payments not yet synced UP are still
visible:

| Method | Path | Handler | Purpose |
|---|---|---|---|
| GET | `/pos/till/gap-preview` | `handleLocalPosTillGapPreview` | NULL-attributed payments in the window `(prev terminal close, now]`, `is_cash = payment_method=='cash'`. |
| GET | `/pos/till/sessions/{id}/order-summary` | `handleLocalPosTillOrderSummary` | Paid orders (distinct, payment in-session) + unpaid-carry (not `closed`/`voided`, no payment). |

**Gap claim + two-way sync.** At open, `claimed_gap_payment_ids` +
`gap_cash_held_separately_ack` stamp the confirmed payments' local
`till_session_id` to the new session (eligibility mirrors gap-preview exactly;
cash included). The claim propagates UP to Cloud via the **`payment.attribute`**
sync op (`sync_service.go`): `POST /workstation/payments/{cloud_id}/attribution`
with the session id, remapping the payment local→cloud (`errDependencyNotReady`
until `payment.create` synced). A not-yet-synced claimed payment instead carries
its `till_session_id` on the create itself (send-time read in
`handlePaymentCreate`). Convergence is guaranteed: the attribute op retries until
Cloud echoes the sent session id (Cloud-authoritative R6), then adopts Cloud's
value onto the local mirror so both DBs are byte-identical. `PullTillSessions`
upserts `ON CONFLICT(id)` (no replace-all) so a locally-settled session — the
gap-window lower bound — survives the active-feed pull. Orders never carry
`till_session_id` (Cloud stamps its own open session at create per R6).

## LAN print endpoints (plan-038)

All LAN-print endpoints sit inside the `lanOnly + corsMiddleware + corsForBrowser + authed` ring (see Security Middleware Ring above). Browser-callable from pos-web (`localhost:5440` / `https://*.godx.jp`).

| Method | Path | Handler | Purpose |
|---|---|---|---|
| POST | `/api/lan/print/kitchen-ticket` | `handleLANPrintKitchenTicket` | Per-`printer_group` ticket fire; force-pulls (1.5s) when order missing locally. Broadcasts `order.kitchen_printed` to KDS. |
| POST | `/api/lan/print/payment-receipt` | `handleLANPrintReceipt` | PAID + remaining slip. New `payment_id` targets one split row; `reprint_reason` drives audit + reprint counter. |
| POST | `/api/lan/print/debt-slip` | `handleLANPrintDebtSlip` | PHIEU GHI NO — rejects 422 when payment method.type ≠ `on_account`. |
| POST | `/api/lan/print/vat-invoice` | `handleLANPrintVatInvoice` | HOA DON GIA TRI GIA TANG from local mirror (T11.4). 410 on voided. |
| POST | `/api/lan/print/shift-report` | `handleLANPrintShiftReport` | 精算 cashier-shift settlement (Z) report — printed on shift close from `till_sessions` + orders/payments. Times → shop timezone (`shopLocation`). `report_kind=handover` prints a 引き継ぎ header (plan-046). |
| POST | `/api/lan/print/chain-report` | `handleLANPrintChainReport` | Plan-046 chain aggregate (kết ca cuối) — condensed block per shift + GRAND TOTAL, summed from each member's immutable `settlement_snapshot`. |
| POST | `/api/lan/print/shift-open-report` | `handleLANPrintShiftOpenReport` | レジ開け shift-open (opening cash count) report — printed on shift open from `till_sessions` + opening-phase `till_cash_denomination_counts`. |
| GET  | `/api/lan/print/status` | `handleLANPrintStatus` | Printer role probe + sync cursor age + optional `?order_id=` pending count. |

Both shift reports render via `service.Format{ShiftReport,ShiftOpenReport}` with a locale label catalog (ja/en/vi; Vietnamese ASCII-folded for Shift_JIS) — see `print_shift_report_i18n.go` / `print_shift_open_report_i18n.go`.

Dispatch goes through `printer/dispatcher.go` (`RouteKitchenItem` + `RouteReceipt`) so bar items hit `bar_printer` instead of being silently routed to `kitchen_printer`. All endpoints use `s.fireKitchenForOrder` or its receipt-side equivalents — single source of truth for the per-group loop.

**Delta kitchen printing.** Fire selection, the 422 pre-check, and the `open_items_pending_print` badge count all key off `unprintedQty(item) = quantity - printed_quantity` (clamp 0) — see `handler/fire_kitchen.go`. `order_items.printed_quantity` (migration `034`) records how many units of a line have been sent to the kitchen; `MarkItemPrinted(itemID, printedQty, printedAt)` sets it to the line's full quantity after a fire. Bumping the quantity of an already-fired line reopens a delta, and `fireKitchenForOrder` prints **delta-quantity copies** so the ticket shows only the newly-added units (toppings/note carry over). The upgrade migration backfills `printed_quantity = quantity` for existing `sent_to_kitchen` lines so it never re-prints open orders.

WS event `order.kitchen_printed` is recorded into the 60s `kdsReplayBuffer` (T9.1) so KDS clients reconnecting with `?since=<RFC3339>` see missed fires before live events resume.

## Key Conventions

- Module: `github.com/dxs-platform/workstation-app`
- Config dir: `~/.ws-app/` (override: `WS_APP_CONFIG_DIR`)
- Database: `~/.ws-app/ws-app.db` (SQLite WAL mode)

## Runtime configuration (`.env`)

`config.LoadDotEnv()` runs first in both `cmd/workstation` and `cmd/ws-server`,
**before** `config.NewManager()` — the manager reads `WS_APP_*` during
construction and persists the result into `config.json` on first run, so loading
later would silently no-op on a fresh install.

Search order (first readable file wins): `$WS_APP_ENV_FILE` → `<exe dir>/.env` →
`<config dir>/.env` → `./.env`. **The real process environment always beats the
file** — a `.env` only fills gaps, which is what keeps the `make dev` /
`wails3 task dev` overrides authoritative on a machine carrying a production
`.env`. Parser handles CRLF and UTF-8 BOM (Notepad); malformed lines are skipped,
never fatal. See `.env.example` for the full variable list.

| Variable | Notes |
|---|---|
| `WS_APP_CLOUD_URL` | **First run only** — `config.json` wins afterwards |
| `WS_APP_SERVER_PORT` | 1024–65535, else reset to 8080 |
| `WS_APP_LAN_IP` | Pins the advertised LAN address; leave empty normally |
| `WS_APP_CONFIG_DIR` | Must be a real env var — it locates the `.env` itself |
| `WS_APP_TUNNEL_HOSTS` | Opt-in CORS/WS host allow-list; unset = strict |

## LAN address detection + endpoint export

`GetLANAddress()` (`internal/handler/lanaddr.go`) asks the OS which source
address it would use to reach a public host (UDP dial — no packets sent, no
internet required, only a default gateway), because virtual adapters never carry
the default route. Ranked enumeration is the fallback; `GetLANAddresses()`
returns every private IPv4, best first, with `virtual` and `preferred` flags.

This replaced a prefix test (`ip[:3] == "192"`) that would happily return
VirtualBox's `192.168.56.1` — an address no LAN client can route to.

`StartEndpointExporter()` writes `<config dir>/endpoint.json` at startup and
re-checks every 60s, logging a warning when the address changes under a running
process (DHCP lease). Same data on `GET /api/lan` (loopback-only), plus a
`pinned` flag when `WS_APP_LAN_IP` is set.
- Wails bindings: Run `wails3 generate bindings` after changing Go service methods
- Frontend calls Go via Wails bindings (not REST) for desktop UI
- Local HTTP server (port 8080) is for LAN tablet/phone clients only
