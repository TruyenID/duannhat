# Gap Analysis — Workstation ↔ POS-Web ↔ Kiosk

> **Cập nhật:** 2026-05-28 (post Sprint A→C security hardening — see "Resolved" section below)
> **Mục đích:** Liệt kê chính xác **những gì chưa có** trong code của 3 codebase để triển khai liên kết LAN.
> **Phương pháp:** Đọc trực tiếp source — grep, ls, đọc file. Tất cả "❌ Chưa có" đều đã verify bằng tool.
> **Liên quan:** Plan chi tiết tại [`plan/`](plan/README.md). File này là bản **đối chứng hiện trạng**, plan là bản **đề xuất thực thi**.

## ✅ Sprint A→C resolved (2026-05-28)

Workstation-side security + observability hardening across ~17 commits on `fix/security-hardening`. Each item links to the resolving commit.

| Gap | Resolved by | What changed |
|-----|-------------|--------------|
| `lanOnly` middleware defined but never applied to mux | A.1 `1df40fb` | Wraps mux globally + per-route `localOnly` on `/api/device/*` + `/api/audit` + `/api/monitor*` |
| `RecordPayment` silent error swallow (paid_amount=0 for missing order) | A.2 `a7e6c9d` | Surfaces sql.ErrNoRows wrapped; handler maps to 404 |
| `idempotency_keys` PK mismatch with access pattern + no cleanup job | A.3 `c681e58` | Migration 012 composite PK `(key, device_id)` + `Maintenance.PurgeIdempotencyOnce` 1h tick |
| Workstation zero rate limiting (pair brute-force surface) | B.1 `128b9d8` | `golang.org/x/time/rate` per-IP pools: pair=5/min, payment=60/min, GC + idempotent Stop |
| CORS allow-list too permissive (`.local` wildcard, `.godx.jp` unanchored, HTTP-OK) | B.2 `864a8be` + `39bedf5` | `.local` dropped; `.godx.jp` HTTPS-only + hostname-anchored; IPv6 origin via `url.Parse` |
| 11 `fmt.Sprintf` JSON injection sites in `auditLog` | B.3 `3742ef4` | `auditDetails(map[string]any) string` wraps `json.Marshal` |
| `writeError(500, err.Error())` leaks SQL/Go errors to LAN (×20 sites) | B.4 `5efa7e4` | `writeServerError(w, r, err)` logs server-side, returns generic body |
| `clientIP()` honored X-Forwarded-For (LAN spoofable) | B.4 `5efa7e4` | XFF read dropped; only `RemoteAddr` honored |
| WS handshake bypassed AuthMiddleware cache → Cloud outage cuts LAN realtime | B.7 `6f24599` + `39bedf5` | `authMiddlewareVerifier` adapter; shares fresh→cloud→stale-fallback ladder; `auth_ok` payload carries `stale: true` |
| No baseline security response headers | B fixup `39bedf5` | `corsMiddleware` sets `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Cross-Origin-Opener-Policy: same-origin` on every response |
| Slowloris attack surface (no ReadHeaderTimeout) | B fixup `39bedf5` | `http.Server.ReadHeaderTimeout: 5s` |
| `isPrivateIP` rejected IPv6 ULA | B fixup `273f3ca` | `ip.IsPrivate() \|\| IsLoopback() \|\| IsLinkLocalUnicast()` |
| Wails frontend had no CSP / Permissions-Policy / Referrer-Policy / Sentry | B.5/B.6 + C.5 + fixup `9585b06` | Full CSP + sentry.io ingest allow + Permissions deny-all + `@sentry/react` SDK with beforeSend scrubber |
| Security middleware ring undocumented | B fixup `380d41d` | Full section in `workstation-app/CLAUDE.md` covering ring ordering + per-route wraps |
| Tests + frontend `.env.example` for Sentry vars | D `31f879b` | `frontend/.env.example` with `VITE_SENTRY_DSN` + `VITE_RELEASE` |

## Still open (tracked elsewhere)

- **Wails bindings bypass middleware stack** — `cmd/workstation/main.go` binds Go services directly to the frontend JSContext. Admin actions (`devices.RemoveDevice`, `config.Set`) reachable from any JS in the webview with zero auth/audit/rate-limit. If a future CSP regression allows XSS in the SPA, the attacker gets full admin via Wails directly. Audit which `Bind`ed methods touch admin state; add audit logging at the **service** layer (not handler layer).
- **mDNS advertisement is unauthenticated** — anyone on the LAN sees the workstation IP + port. Defense in depth: bind discovery service to a configured interface (avoid guest VLAN) or sign the TXT record with a shared secret distributed via QR at install time.
- **`sync_queue` unsynced rows grow unbounded** — `maintenance.go::PurgeSyncQueueOnce` only deletes `WHERE synced_at IS NOT NULL`. A sustained Cloud outage + busy kiosk → unbounded growth. Add a cap (count or age) for stuck rows with operator alerting.
- **Source-map upload for Sentry** — `frontend/dist/assets/*.js` ships minified; without sourcemap upload Sentry stack traces are unreadable. `@sentry/vite-plugin` + CI auth-token.

---

## A. WORKSTATION-APP — đang thiếu gì

### A.1 Database / Schema

Migrations hiện có trong [`migrations/`](../migrations/):

```
001_initial_schema.sql       ← orders, menu, etc.
002_device_auth.sql          ← device pair với Cloud (token Workstation ↔ Cloud)
003_audit_log.sql            ← audit
```

| Thành phần | Trạng thái | Ghi chú |
|---|---|---|
| Bảng `lan_devices` (lưu thiết bị LAN đã pair) | ❌ **Chưa có** | Phải tạo migration `004_create_lan_devices.sql` |
| Cột `token_hash` (bcrypt/argon2) | ❌ **Chưa có** | Hệ thống chưa dùng bất kỳ thuật toán hash mật khẩu nào (`grep bcrypt` rỗng) |
| Indexes cho `last_seen_at`, `revoked_at` | ❌ **Chưa có** | Cùng migration với bảng |

### A.2 HTTP Routes (LAN, port 8080)

Routes hiện có trong [`internal/handler/routes.go`](../internal/handler/routes.go):

```
✅ GET    /api/status                      ← health đơn giản
✅ GET    /api/lan                         ← LAN info (IP, etc.)
✅ GET    /api/dashboard/stats
✅ GET    /api/config
✅ GET    /api/version
✅ GET/POST/PUT  /api/orders[/{id}]
✅ POST   /api/orders/{id}/print
✅ POST   /api/orders/{id}/payment
✅ GET/POST/PUT/DELETE  /api/menu[/{id}]
✅ POST   /api/menu/seed
✅ GET/POST/DELETE  /api/devices[/{id}]    ← PRINTERS (USB/TCP), không phải LAN devices
✅ POST   /api/devices/{id}/test
✅ GET/POST  /api/sync[/retry]
✅ GET    /api/reports/{daily,popular}
✅ GET/PUT  /api/settings/{key}
✅ POST   /api/device/pair                 ⚠️ PROXY tới Cloud, KHÔNG phải LAN pair
✅ GET    /api/device/status
✅ POST   /api/device/unpair
✅ GET    /api/audit
✅ GET    /api/monitor[/health]
✅       /ws                               ← WebSocket (xem A.4)
```

**Endpoint đang THIẾU:**

| Endpoint | Mục đích | Plan task |
|---|---|---|
| `POST /api/lan/pairing-codes` | Wails UI sinh pairing code cho child device | [01 Task 2](plan/01-workstation-server-prep.md#task-2--pairing-code-generator--endpoint-admin) |
| `GET /api/lan/pairing-codes` | List code còn hạn | [01 Task 2](plan/01-workstation-server-prep.md#task-2--pairing-code-generator--endpoint-admin) |
| `DELETE /api/lan/pairing-codes/{code}` | Hủy code | [01 Task 2](plan/01-workstation-server-prep.md#task-2--pairing-code-generator--endpoint-admin) |
| `POST /api/lan/pair` | Kiosk/pos-web đổi code lấy bearer token | [01 Task 3](plan/01-workstation-server-prep.md#task-3--endpoint-pairing-cho-child-device) |
| `GET /api/lan/health` | Discovery + connectivity check (không cần auth) | [01 Task 5](plan/01-workstation-server-prep.md#task-5--endpoint-discovery--health-không-auth) |
| `POST /api/lan/print/payment-receipt` | In bill thanh toán cho pos-web | [01 Task 6](plan/01-workstation-server-prep.md#task-6--endpoint-print-payment-receipt) |
| `GET /api/lan/devices` | Wails UI list devices đã pair | [01 Task 8](plan/01-workstation-server-prep.md#task-8--endpoint-quản-lý-lan-devices-từ-wails-ui) |
| `DELETE /api/lan/devices/{id}` | Revoke 1 device | [01 Task 8](plan/01-workstation-server-prep.md#task-8--endpoint-quản-lý-lan-devices-từ-wails-ui) |

**Lưu ý quan trọng về `POST /api/device/pair` hiện có:**
Đọc [`routes.go:636-680`](../internal/handler/routes.go) — endpoint này nhận `pairing_code` từ Wails UI rồi **forward lên Cloud** (`cloud_url/api/v1/workstation/pair`). Đây là pair giữa **Workstation và Cloud**, không phải child device pair với Workstation. **Hai luồng pair khác nhau hoàn toàn** — đừng nhầm.

### A.3 Middleware / Authentication

Middleware hiện có trong [`internal/handler/middleware.go`](../internal/handler/middleware.go):

| Middleware | Trạng thái | Tác dụng |
|---|---|---|
| `lanOnly` | ✅ Có | Chỉ accept request từ private IP range (10/8, 172.16/12, 192.168/16, loopback) |
| `corsMiddleware` | ✅ Có | CORS, allow private origins |
| **`lanAuthMiddleware` (Bearer token)** | ❌ **Chưa có** | LAN endpoints hiện chỉ check IP, **không check token** |

→ Hệ quả: bất kỳ thiết bị nào trong LAN đều gọi được mọi endpoint mà không cần auth. Phải thêm Bearer auth cho route group `/api/lan/*` (trừ `/health` và `/pair`).

### A.4 WebSocket

WebSocket route `/ws` có trong [`internal/handler/ws.go`](../internal/handler/ws.go) ([`routes.go:80`](../internal/handler/routes.go)).

| Thành phần | Trạng thái |
|---|---|
| Connection upgrade | ✅ Có |
| Auth trong handshake | ❌ **Chưa có** (không validate token) |
| Subscription model (`{action: "subscribe", topics: [...]}`) | ❌ **Chưa có** |
| Event taxonomy chuẩn (`order.created`, `order.status_changed`, `menu.updated`, ...) | ❌ **Chưa định nghĩa rõ** |
| Per-device topic filtering | ❌ **Chưa có** |
| Heartbeat / ping-pong | ⚠️ **Cần verify** (đọc thêm `ws.go`) |

### A.5 mDNS / Discovery

Trong [`internal/discovery/mdns.go`](../internal/discovery/mdns.go):

| Thành phần | Trạng thái |
|---|---|
| Service type `_workstation-app._tcp.local.` | ✅ Đã advertise |
| TXT records với `branch_id`, `version`, `name` | ⚠️ **Cần verify** — code hiện có pass `branchID` qua constructor nhưng cần kiểm tra TXT entry đầy đủ chưa |
| Auto re-advertise khi IP đổi (DHCP) | ⚠️ **Cần test** |

### A.6 Tài liệu

| Tài liệu | Trạng thái |
|---|---|
| OpenAPI spec [`openapi-workstation.yaml`](../internal/handler/openapi-workstation.yaml) | ✅ Có cho endpoint hiện tại |
| OpenAPI spec cho `/api/lan/*` mới | ❌ **Chưa có** |
| Tài liệu WebSocket event schema | ❌ **Chưa có** trong [`docs/LOCAL_SERVER.md`](LOCAL_SERVER.md) |

---

## B. POS-WEB — đang thiếu gì

Cấu trúc hiện có trong [`pos-web/src/`](../../pos-web/src/):

```
src/
├── app/             ← Next.js pages
├── components/
├── hooks/           ← (không có workstation hook)
├── lib/
├── providers/
│   ├── app-provider.tsx
│   ├── auth-provider.tsx
│   ├── query-provider.tsx
│   └── use-auth.ts   ← (không có WorkstationProvider)
└── services/
    └── workstation-print-service.ts   ✅ stub duy nhất
```

### B.1 Đã CÓ

| Thành phần | File | Ghi chú |
|---|---|---|
| Print service stub | [`src/services/workstation-print-service.ts`](../../pos-web/src/services/workstation-print-service.ts) | Đọc `VITE_WORKSTATION_URL`, gọi `POST /print/payment-receipt` (chưa có Auth header) |
| Call sites wired | `app/pos/page.tsx`, `payment-receipt-dialog.tsx`, `split-bill-equal-tab.tsx`, `split-bill-receipt-dialog.tsx` | UI sẵn sàng dùng — chỉ no-op khi `enabled=false` |
| Env var `VITE_WORKSTATION_URL` | `.env.example` | Default trống |

### B.2 ĐANG THIẾU

| Thành phần | File cần tạo | Plan task |
|---|---|---|
| Workstation API client (với Authorization header) | `src/lib/workstation/client.ts` | [02 Task 2](plan/02-pos-web-integration.md#task-2--workstation-api-client) |
| Token storage (localStorage wrapper) | trong client.ts | [02 Task 2](plan/02-pos-web-integration.md#task-2--workstation-api-client) |
| Workstation Provider + hook `useWorkstation` | `src/providers/workstation-provider.tsx` | [02 Task 4](plan/02-pos-web-integration.md#task-4--websocket-client--reconnect-logic) |
| WebSocket client (browser không cần lib, dùng native `WebSocket`) | `src/lib/workstation/socket.ts` | [02 Task 4](plan/02-pos-web-integration.md#task-4--websocket-client--reconnect-logic) |
| Reconnect + heartbeat logic | trong socket.ts | [02 Task 4](plan/02-pos-web-integration.md#task-4--websocket-client--reconnect-logic) |
| Pairing modal UI | `src/components/workstation/pairing-modal.tsx` | [02 Task 3](plan/02-pos-web-integration.md#task-3--pairing-flow-ui) |
| Settings page cho workstation | `src/app/settings/workstation/page.tsx` | [02 Task 1](plan/02-pos-web-integration.md#task-1--cấu-hình-env--settings-ui) |
| Connection status banner | `src/components/workstation/connection-banner.tsx` | [02 Task 6](plan/02-pos-web-integration.md#task-6--connection-status-banner-toàn-site) |
| Refactor `workstation-print-service.ts` để có Authorization header | sửa file hiện có | [02 Task 2](plan/02-pos-web-integration.md#task-2--workstation-api-client) |
| Order status real-time consumer | sửa `app/[shopSlug]/orders/page.tsx` | [02 Task 7](plan/02-pos-web-integration.md#task-7--order-status-real-time-updates-consume-ws) |

### B.3 Verify đã chạy

```sh
grep -rn "WebSocket\|ws://" pos-web/src/
# → Không kết quả: pos-web KHÔNG có WebSocket nào hiện tại
```

```sh
ls pos-web/src/providers/
# → app-provider.tsx, auth-provider.tsx, query-provider.tsx, use-auth.ts
# → KHÔNG có workstation-provider.tsx
```

---

## C. GODX-KIOSK — đang thiếu gì

### C.1 Đã CÓ

| Thành phần | File | Ghi chú |
|---|---|---|
| Cloud device pairing flow | `src/providers/auth-provider.tsx` | Pattern có thể tái sử dụng cho Workstation pair |
| `expo-secure-store` đã cài | `package.json` | Sẵn sàng lưu thêm `workstation_token`, `workstation_url` |
| Enum `DeviceType.Workstation` | `src/types/models/enum/DeviceType.ts` | Chỉ schema, không có logic |
| Cloud API client | `src/services/*` | Dùng làm template cho Workstation client |

### C.2 ĐANG THIẾU (gần như hết)

| Thành phần | Cần thêm | Plan task |
|---|---|---|
| Dependency mDNS browser | `react-native-zeroconf` (hoặc tương đương) — **chưa cài** (`grep zeroconf\|bonjour\|mdns` trong `package.json` rỗng) | [03 Task 1](plan/03-kiosk-integration.md#task-1--thêm-dependency-mdns--iosandroid-permissions) |
| iOS `NSBonjourServices` Info.plist | Cần thêm `["_workstation-app._tcp"]` | [03 Task 1](plan/03-kiosk-integration.md#task-1--thêm-dependency-mdns--iosandroid-permissions) |
| iOS `NSLocalNetworkUsageDescription` | Bắt buộc từ iOS 14+ | [03 Task 1](plan/03-kiosk-integration.md#task-1--thêm-dependency-mdns--iosandroid-permissions) |
| Workstation discovery service | `src/services/workstation/discovery.ts` | [03 Task 2](plan/03-kiosk-integration.md#task-2--workstation-discovery-service) |
| Workstation API client (RN fetch wrapper) | `src/services/workstation/client.ts` | [03 Task 3](plan/03-kiosk-integration.md#task-3--workstation-api-client) |
| Token storage wrapper (expo-secure-store) | `src/services/workstation/storage.ts` | [03 Task 3](plan/03-kiosk-integration.md#task-3--workstation-api-client) |
| Discovery & pair UI screens | `src/app/(setup)/workstation-discovery.tsx`, `workstation-pair.tsx` | [03 Task 4](plan/03-kiosk-integration.md#task-4--ui-chọn--pair-workstation) |
| WorkstationProvider | `src/providers/workstation-provider.tsx` | [03 Task 5](plan/03-kiosk-integration.md#task-5--workstation-provider--auto-reconnect) |
| Order routing (LAN-first, Cloud fallback) | sửa `src/services/order-service.ts` | [03 Task 7](plan/03-kiosk-integration.md#task-7--order-submission-lan-first) |
| Menu routing (LAN-first) | sửa `src/services/menu-service.ts` | [03 Task 6](plan/03-kiosk-integration.md#task-6--menu-source-từ-workstation-lan-first-fallback-cloud) |
| WebSocket subscription | trong provider | [03 Task 8](plan/03-kiosk-integration.md#task-8--order-status-websocket-subscription) |
| Print receipt qua Workstation | sửa post-payment screen | [03 Task 9](plan/03-kiosk-integration.md#task-9--in-hóa-đơn-cho-khách-receipt-qua-workstation) |
| Admin settings để đổi/unpair Workstation | sửa settings screen | [03 Task 10](plan/03-kiosk-integration.md#task-10--settings-ui-để-admin-reset-workstation-pairing) |
| AppState + NetInfo handling | trong provider | [03 Task 5](plan/03-kiosk-integration.md#task-5--workstation-provider--auto-reconnect) |

### C.3 Verify đã chạy

```sh
grep -rn "workstation\|ws-app\|localhost:8080\|mdns\|bonjour" godx-kiosk/src/
# → Chỉ 2 match: enum DeviceType + Device schema — KHÔNG có logic
```

```sh
grep -E "zeroconf|bonjour|mdns|network" godx-kiosk/package.json
# → Không kết quả: chưa có dep
```

---

## D. Bảng tổng hợp

| Hạng mục | Workstation | POS-Web | Kiosk |
|---|---|---|---|
| **Database / Persistence** |
| Bảng/storage thiết bị LAN | ❌ | localStorage (chưa code) | SecureStore (chưa code) |
| Token hashing | ❌ | N/A | N/A |
| **HTTP API** |
| LAN pairing endpoint | ❌ | N/A (consumer) | N/A (consumer) |
| Print payment receipt | ❌ | ⚠️ Stub call sẵn | N/A |
| Health endpoint chuyên LAN | ❌ | — | — |
| Bearer auth middleware | ❌ | ❌ thiếu Authorization header | ❌ chưa có client |
| **WebSocket** |
| Server-side topic/auth | ⚠️ Có route nhưng thiếu auth/sub | ❌ Chưa có client | ❌ Chưa có client |
| **Discovery (mDNS)** |
| Advertise (server) | ✅ Có | N/A | — |
| Browse (client) | — | ❌ Browser không hỗ trợ → config tay | ❌ Chưa cài dep |
| **UI** |
| Pair modal/screen | ❌ Wails UI chưa có "Generate code" | ❌ | ❌ |
| Settings page | ❌ | ❌ | ❌ |
| Connection status indicator | ❌ | ❌ | ❌ |
| **Business logic** |
| Order routing LAN-first | N/A | N/A (Phase 2) | ❌ |
| Menu routing LAN-first | N/A | N/A (Phase 2) | ❌ |
| Cloud fallback | N/A | N/A | ❌ |
| Print integration E2E | ❌ (endpoint thiếu) | ⚠️ Stub gọi sẵn | ❌ |

**Chú thích:** ✅ Đã có | ⚠️ Có 1 phần / cần verify | ❌ Chưa có | — Không áp dụng

---

## E. Khối lượng work còn lại (ước tính)

| Codebase | Files cần CREATE mới | Files cần MODIFY | Ước tính người-ngày |
|---|---|---|---|
| `workstation-app` | ~12 (1 migration, 1 domain, 4 handlers, 1 middleware, 1 service, 1 store query, 1 docs, 2 tests) | ~5 (routes.go, ws.go, mdns.go, openapi.yaml, LOCAL_SERVER.md) | 5–7 ngày |
| `pos-web` | ~8 (client, types, storage, socket, provider, pairing-modal, banner, settings page) | ~4 (print service, layout, env, 1 settings link) | 4–5 ngày |
| `godx-kiosk` | ~10 (discovery, client, storage, types, 2 setup screens, provider, hook, docs) | ~6 (order/menu service, package.json, app.json, Info.plist, settings, root layout) | 7–9 ngày |
| **Tổng** | **~30 file mới** | **~15 file sửa** | **16–21 ngày** (3 dev song song) |

---

## F. Yếu tố ngoài code (không nằm trong scope codebase nhưng phải có)

| Hạng mục | Trạng thái | Ghi chú |
|---|---|---|
| Router LAN cho phép multicast mDNS | ⚠️ Cần verify | Một số router enterprise chặn — cần test thực tế |
| iOS App Store review chấp nhận Local Network permission | ⚠️ Rủi ro | Cần screenshot + clear description |
| UPS cho Workstation | 📋 Recommendation | Để Workstation không chết khi cúp điện chớp nhoáng |
| Subnet duy nhất giữa Workstation + Kiosk + POS-Web | 📋 Requirement | Hoặc bật multicast routing qua VLAN |

---

## G. Rủi ro vận hành SQLite (do tải mới từ integration)

Workstation dùng SQLite WAL mode. Integration plan làm tăng tải nhiều hướng → có một số rủi ro cụ thể **cần xử lý ngay trong plan code**, không phải để vận hành sau:

### G.1 Write storm từ `last_seen_at`

**Bản chất:** SQLite chỉ có **1 writer tại 1 thời điểm** (kể cả WAL). Update `last_seen_at` mỗi authenticated request = 30+ writes/giây ở peak → block các write thật (orders, payments) → có thể chạm `SQLITE_BUSY`.

**Giải pháp:** Buffer trong RAM + flush mỗi 30s (xem [plan 01 Task 4](plan/01-workstation-server-prep.md#task-4--auth-middleware-cho-apilan-trừ-pair) phần "Last-seen batching"). Trade-off: mất chính xác ±30s, không ảnh hưởng business.

### G.2 CPU saturation từ bcrypt validation

**Bản chất:** Bcrypt cost 10 ≈ 100ms CPU/lần. 30 req/s × 100ms = **3000ms CPU/giây** → CPU saturated.

**Giải pháp:** LRU token cache trong RAM, TTL 5 phút (xem [plan 01 Task 4](plan/01-workstation-server-prep.md#task-4--auth-middleware-cho-apilan-trừ-pair) phần "Token validation cache"). Khi revoke → invalidate cache entry tương ứng.

### G.3 WAL file phình to nếu có long-running readers

**Bản chất:** WAL không tự thu hồi nếu có reader đang giữ snapshot. WebSocket connection kéo dài + sync engine + heartbeat có thể giữ snapshot → WAL grow vô hạn.

**Triệu chứng:** File `workstation-app.db-wal` lớn dần, ăn disk, checkpoint chậm.

**Giải pháp:**
- `PRAGMA wal_autocheckpoint = 1000` (default — đã ok)
- Job định kỳ chạy `PRAGMA wal_checkpoint(TRUNCATE)` mỗi 1h
- Monitor `wal_size` qua heartbeat, alert nếu > 100MB
- **Đề xuất thêm vào plan 01** (chưa có task này)

### G.4 Mất dữ liệu khi cúp điện đột ngột

**Bản chất:** WAL an toàn hơn rollback journal, nhưng vẫn có thể mất transaction chưa fsync khi cúp điện giữa checkpoint.

**Giải pháp:**
- **UPS bắt buộc** (đã ở mục F)
- `PRAGMA synchronous = NORMAL` — đủ an toàn với WAL, nhanh hơn FULL
- **Auto backup** `VACUUM INTO 'backup.db'` mỗi 1h sang USB/cloud
- **Đề xuất thêm vào plan 01** (chưa có task backup)

### G.5 Sync queue bùng nổ khi mất Internet lâu

**Bản chất:** Mỗi order khi offline = 1 row sync_queue chứa JSON payload (~5-10KB). 12h offline × 100 orders/h = ~1,200 rows, ~10MB. Không nguy hiểm về dung lượng, nhưng khi có lại mạng → 1,200 HTTP requests dồn dập → có thể rate-limit cloud.

**Giải pháp:**
- Batch push (50-100 ops/request) — sync engine hiện đã có pattern này, **verify lại**
- Exponential backoff giữa các batch
- Cloud-side: increase rate limit cho workstation token, hoặc dedicated endpoint cho bulk push

### G.6 Audit log bloat

**Bản chất:** Mỗi authenticated request → 1 audit row. ~50 req/s × 8h × 365 = ~50M rows/năm.

**Giải pháp:**
- Retention policy: xóa audit > 90 ngày
- Hoặc archive sang file JSON định kỳ rồi xóa
- **Đề xuất thêm vào plan 01** (chưa có task này)

### G.7 Connection pool tuning

**Bản chất:** Driver `modernc.org/sqlite` mặc định có thể mở nhiều connection cho writer → mỗi connection cạnh tranh write lock vô ích.

**Giải pháp:**
- `db.SetMaxOpenConns(1)` cho writer-pool (chỉ 1 connection ghi)
- Separate reader-pool với `MaxOpenConns = 10` (đọc thoải mái với WAL)
- **Đề xuất thêm vào plan 01** hoặc làm 1 task riêng "SQLite tuning"

### G.8 PRAGMA khuyến nghị (cần áp dụng nếu chưa có)

```
PRAGMA journal_mode = WAL;        ← đã có
PRAGMA synchronous = NORMAL;       ← verify
PRAGMA busy_timeout = 5000;        ← verify (5s retry thay vì fail ngay)
PRAGMA wal_autocheckpoint = 1000;  ← default ok
PRAGMA temp_store = MEMORY;        ← verify
PRAGMA mmap_size = 268435456;      ← 256MB, tăng performance đọc
PRAGMA cache_size = -32768;        ← 32MB cache (âm = KB)
PRAGMA foreign_keys = ON;          ← verify
```

→ Kiểm tra `internal/store/db.go` (hoặc tương đương) xem các PRAGMA này đã set chưa. Nếu chưa, thêm vào plan.

### Tóm tắt task cần BỔ SUNG vào [plan 01](plan/01-workstation-server-prep.md)

| Task đề xuất thêm | Lý do | Mức ưu tiên |
|---|---|---|
| WAL checkpoint định kỳ + monitor `wal_size` | G.3 | 🟡 Trung |
| Auto backup `VACUUM INTO` mỗi 1h | G.4 | 🔴 Cao |
| Audit log retention 90 ngày | G.6 | 🟡 Trung |
| SQLite connection pool tuning (1 writer, N readers) | G.7 | 🔴 Cao |
| Verify + add PRAGMAs còn thiếu | G.8 | 🔴 Cao |
| Bulk push batching cho sync queue | G.5 | 🟢 Thấp (đã có pattern) |

---

## H. Liên kết tới plan thực thi

- [📁 plan/README.md](plan/README.md) — overview, thứ tự, glossary
- [📄 plan/01-workstation-server-prep.md](plan/01-workstation-server-prep.md) — fix gaps mục **A** (PREREQ)
- [📄 plan/02-pos-web-integration.md](plan/02-pos-web-integration.md) — fix gaps mục **B**
- [📄 plan/03-kiosk-integration.md](plan/03-kiosk-integration.md) — fix gaps mục **C**

---

## I. Verify lại file này bằng cách nào

Mọi claim "❌ Chưa có" trong file này có thể tự verify:

```sh
# A.1 - không có migration lan_devices
ls workstation-app/migrations/ | grep -i lan

# A.2 - không có route /api/lan/pair
grep "/api/lan/pair\b" workstation-app/internal/handler/routes.go

# A.3 - không có Bearer auth middleware
grep -i "Bearer\|authMiddleware" workstation-app/internal/handler/middleware.go

# B.2 - pos-web chưa có WebSocket
grep -rn "WebSocket\|ws://" pos-web/src/

# B.2 - pos-web chưa có workstation provider
ls pos-web/src/providers/ | grep -i workstation

# C.2 - kiosk chưa có zeroconf
grep -E "zeroconf|bonjour|mdns" godx-kiosk/package.json

# C.2 - kiosk không có code workstation (chỉ enum)
grep -rn "workstation" godx-kiosk/src/ | grep -v "enum\|DeviceType"
```

Nếu trong tương lai bất kỳ command nào trả kết quả khác thì file này đã cũ — cần update.

---

## plan-027 KDS Phase 2 notes

### Dual-table situation (existing tech debt, NOT introduced by plan-027)

Workstation has two parallel item tables:
- `order_items` (post-Sprint-4 aligned legacy, has full status/served_at/voided_at/customer_order_id FK, created in migration 007) — populated by POS flow (`order_service.go`) + cloud sync DOWN (Task 2.8 + existing recovery)
- `customer_order_items` (omnify-generated shell, missing status column, currently unused)

KDS reads + writes `order_items` (post-Sprint-4 aligned). KDS does NOT touch `customer_order_items`. The omnify shell exists but is dead code at runtime — separate consolidation/cleanup is out of plan-027 scope.

Future direction: complete the omnify schema migration started 2026-04-12 (per `schemas/Workstation/.gitkeep` comment) by populating `schemas/Workstation/Product/CustomerOrderItem.yaml` and unifying writers onto `customer_order_items`. Not blocking KDS.

### Workstation schema sync with cloud

`schemas/Workstation/` only contains `.gitkeep` placeholder. Workstation's omnify codegen generates against this empty set — only enums (from `schemas/Shared/Enum/`) get regenerated. Object tables (orders, customer_order_items) are frozen at their 2026-04 state.

Risk: when cloud `schemas/Backend/` evolves, workstation's omnify-generated tables drift. KDS work uses post-Sprint-4 aligned `order_items` which is hand-maintained and not subject to this drift, so KDS is unaffected. Other future workstation features depending on omnify replicas may hit this.

### KDS Phase 2 deliverables

**Completed by plan-027:**
- Local KDS endpoints: `GET /api/v1/kds/me`, `GET /api/v1/kds/orders`, `PATCH /api/v1/kds/orders/{o}/items/{i}/status` (Task 2.1–2.3)
- WebSocket first-message auth protocol (Task 2.4)
- Cloud sync UP handler for `customer_order_item.update_status` (Task 2.7)
- Cloud sync DOWN for `customer_orders` with 5s cadence + cursor management (Task 2.8)
- Order items status detection + WS event broadcast on pull-DOWN (Task 2.8)
- 409 revert path on sync UP conflict (Task 2.6)
- OpenAPI spec for KDS endpoints + WebSocket auth doc (Task 2.9)
- Idempotency cache for item-status bumps (Task 2.5)

**Not in scope (Phase 3+):**
- KDS web/mobile clients (separate repos)
- Integration with physical kitchen printers for KDS items (existing printer integration only covers POS)
- Menu/recipe pull-DOWN for kitchen (currently only menu for POS)
- Staff pairing for KDS devices (only device_token auth implemented)
