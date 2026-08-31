# Data Flow: Backend → Screens

> Godx Kiosk — Expo/React Native App
> Updated: 2026-04-17

---

## Tổng quan

```
BACKEND (Laravel API — EXPO_PUBLIC_API_URL)
         │
         │  HTTP/JSON
         ▼
┌─────────────────────────────────────────┐
│  src/lib/api.ts                         │
│  • apiFetch() — wrapper duy nhất        │
│  • Auto-inject: Bearer token,           │
│    Accept-Language header, 15s timeout  │
│  • pairDevice() — riêng (no token)      │
│  • ApiError / TimeoutError              │
└─────────────┬───────────────────────────┘
              │
              ▼
┌─────────────────────────────────────────┐
│  src/providers/query-provider.tsx       │
│  • Khởi tạo QueryClient toàn cục        │
│  • staleTime: 15s                       │
│  • Auto refetch khi app vào foreground  │
│    (AppState → focusManager)            │
│  • Không retry 401/403                  │
└─────────────┬───────────────────────────┘
              │ QueryClientProvider bao toàn app
              ▼
┌─────────────────────────────────────────┐
│  src/providers/auth-provider.tsx        │
│  • Đọc token từ SecureStore khi mount   │
│  • Verify token → GET /api/v1/tms/me   │
│  • pair(code) → pairDevice() →          │
│    lưu token vào SecureStore            │
│  • logout() → xoá token + clear cache  │
│  • Expose: isAuthenticated, device      │
└─────────────┬───────────────────────────┘
              │
              ▼
┌─────────────────────────────────────────┐
│  src/hooks/query-keys.ts                │
│  • Factory functions cho query keys     │
│  • zoneKeys, tableKeys                  │
│  • orderKeys, paymentKeys, deviceKeys   │
│  • KHÔNG hardcode string trong hooks    │
└──────┬──────────────┬────────────┬──────┘
       │              │            │
       ▼              ▼            ▼
┌────────────┐ ┌────────────┐ ┌────────────┐
│use-zones.ts│ │use-order.ts│ │use-payment │
│            │ │            │ │    .ts     │
│• GET zones │ │• GET order │ │• POST pay  │
│• GET tables│ │  by tableId│ │• Poll      │
│• Merge     │ │• GET order │ │  status    │
│  client-   │ │  by orderId│ │  mỗi 3s   │
│  side      │ │• useQuery  │ │• useMut-   │
│• Poll 15s  │ │            │ │  ation     │
└─────┬──────┘ └─────┬──────┘ └─────┬──────┘
      │              │              │
      ▼              ▼              ▼
┌──────────────────┐ ┌──────────────┐ ┌────────────────┐
│ app/select-table │ │ app/bill.tsx │ │ app/payment/   │
│       .tsx       │ │              │ │   *.tsx        │
│                  │ │• Order       │ │                │
│• Grid bàn occupied│ │  summary    │ │• qr.tsx        │
│• Confirm → bill  │ │• Tổng tiền   │ │• cash.tsx      │
│                  │ │• → split-opt │ │• card.tsx      │
│                  │ │              │ │• emoney.tsx    │
└──────────────────┘ └──────────────┘ └────────────────┘
```

> Payment surface (split / custom / payment-method / payment/{method}) is
> documented in detail in [`payment-flow.md`](./payment-flow.md). This doc
> covers the data-fetch layer; the canonical payment-flow reference lives
> there.

---

## Screen Flow (Navigation)

```
app/index.tsx
  │
  ├── isAuthenticated = false → app/login.tsx
  │     └── pair(6-digit) → SecureStore token → /advertise
  │
  └── isAuthenticated = true → app/advertise.tsx
        │
        └── [Khách chạm] → app/select-table.tsx
              │
              └── [Chọn bàn + Confirm] → app/bill.tsx?tableId=xxx
                    │
                    └── [Tiếp tục] → app/split-options.tsx
                          │
                          └── [Chọn split / full] → app/payment-method.tsx
                                │
                                └── [Chọn phương thức] → app/payment/[method].tsx
                                      │
                                      └── [Thành công] → app/success.tsx
                                            │
                                            └── [Auto redirect] → app/advertise.tsx
```

> Note: the old `app/scan.tsx` (camera QR scanner) and `app/checkout.tsx`
> (order review + method picker) screens were removed in
> `fix/payment-flow-critical-trio` (Phase A). Their roles were absorbed by
> `select-table.tsx` (manual table picking — QR-scan path was unused in
> production) and the `bill → split-options → payment-method` triple.

---

## API Endpoints theo Screen

| Screen | Hook | Method | Endpoint |
|--------|------|--------|----------|
| `login.tsx` | `pairDevice()` | POST | `/api/v1/devices/pair` |
| `auth-provider` | `apiFetch()` | GET | `/api/v1/tms/me` |
| `select-table.tsx` | `useZones()` | GET | `/api/v1/tms/zones` |
| `select-table.tsx` | `useZones()` | GET | `/api/v1/tms/tables` |
| `bill.tsx` | `useOrder(tableId)` | GET | `/api/v1/kiosk/orders?table_id=xxx` |
| `payment/*.tsx` | `usePayment().submit()` | POST | `/api/v1/kiosk/payments` |
| `payment/*.tsx` | `usePayment()` poll | GET | `/api/v1/kiosk/payments/:id/status` |

---

## Token Flow

```
app/login.tsx
  → pairDevice(6-digit code)
      └── POST /api/v1/devices/pair
            └── nhận { device_token }
                  └── SecureStore.setItemAsync("tms_device_token")
                                          ▲
                                          │ đọc mỗi request
                                          │
apiFetch(path)
  → SecureStore.getItemAsync("tms_device_token")
  → AsyncStorage.getItem("tms_locale")
  → fetch(API_BASE + path, {
      headers: {
        Authorization: "Bearer <token>",
        Accept-Language: "<locale>",
        Content-Type: "application/json",
      },
      signal: AbortController (15s timeout)
    })
```

---

## Cache & Polling Strategy

| Data | staleTime | refetchInterval | Trigger |
|------|-----------|-----------------|---------|
| Zones + Tables | 15s | 15s | Polling + foreground |
| Order | 15s | — | On demand |
| Payment status | 0 | 3s | Until `paid` / `failed` |

**Foreground refetch:** Khi app từ background về foreground (`AppState = active`), `focusManager` trigger refetch tất cả stale queries.

---

## Vai trò từng file

| File | Vai trò |
|------|---------|
| `src/lib/api.ts` | HTTP client — 1 nơi duy nhất gọi `fetch()` |
| `src/providers/query-provider.tsx` | Cache + polling engine (TanStack Query) |
| `src/providers/auth-provider.tsx` | Auth state + device token lifecycle |
| `src/providers/app-provider.tsx` | Theme + locale (i18n) |
| `src/hooks/query-keys.ts` | Key registry — tránh duplicate cache |
| `src/hooks/use-zones.ts` | Zones + tables data cho select-table screen |
| `src/hooks/use-order.ts` | Order data cho bill screen |
| `src/hooks/use-payment.ts` | Submit payment + poll trạng thái |
| `src/types/kiosk.ts` | TypeScript types: Order, Payment, v.v. |
| `src/types/tms.ts` | TypeScript types: Zone, Table, v.v. |
