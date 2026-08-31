# pos-web — In-store POS terminal

Cash-handling POS web client for restaurants/shops. Vite + React 19 + TypeScript + TanStack Query v5 + `@godxjp/ui` (Radix + Tailwind v4). Lives in-tree at `web/pos/` in the umbrella monorepo; the former repo `godx-jp/godx-tempo-pos-web` is archived read-only.

## Quick start

```sh
# From web/pos/ — this app carries its own lockfile
pnpm install
pnpm dev             # → http://localhost:5440
pnpm test            # vitest run
pnpm build           # tsc -b && vite build

# From the umbrella root
pnpm dev:pos         # = cd web/pos && pnpm install && pnpm dev
```

A bare `pnpm install` at the umbrella root does **not** install this app:
`pnpm-workspace.yaml` lists only `packages/*`, so pos-web is not a workspace
member. Use `pnpm install:all` from the root, or install here — `pnpm dev:pos`
does it for you.

## Architecture

POS staff hit pos-web in a browser on the store's checkout tablet. Every API call
goes through `resolveBaseUrl()` (`src/services/workstation/base-url-resolver.ts`),
which picks **the workstation over the LAN** or **the backend (Cloud)** according
to the API mode. Realtime rides the same choice.

Auth is SSO via the `tempo` service slug on `dev-console.godx.jp` — a cashier who already has a tempo account can log into the POS without separate provisioning. Token persisted in BOTH `localStorage` and a `SameSite=Lax` cookie (1-year `max-age`).

### API mode — the default depends on WHICH BUILD is running

| Build | `base` | Default mode | Workstation URL | Cloud URL |
|---|---|---|---|---|
| cloud (`build:cloud`, Amplify) | `/` | **cloud** — LAN is opt-in | stored pairing / `VITE_WORKSTATION_API_URL` | `VITE_API_URL` |
| workstation (`build:workstation`, served at `/pos/`) | `/pos/` | **workstation** — same-origin | `location.origin` (always wins) | `<meta name="x-pos-cloud-url">` injected by the workstation from `WS_APP_CLOUD_URL`, else `VITE_API_URL` |

A stored operator preference (`localStorage.pos_api_mode`, Settings → Connection)
beats the default; only `VITE_POS_API_MODE` beats the operator — leave it unset.

**Why the split default exists.** The embedded build once shared the cloud
default, so a POS opened from the workstation sent every `/api/v1/pos/*` call to
the baked `VITE_API_URL` — a host that never issued that terminal's token
(pairing is relayed through the workstation to ITS `WS_APP_CLOUD_URL`) and that
cannot allowlist a shop LAN origin for CORS. The visible symptom was the payment
dialog claiming the shop had **no payment methods configured** while the
workstation's own mirror held them. Pinned by
`base-url-resolver.test.ts` → "REGRESSION: a fresh workstation terminal never
sends /pos/* to the baked cloud host".

```
Operator browser → pos-web (Vite SPA on a checkout tablet)
                     ↓
         resolveBaseUrl()  ← mode: workstation | auto | cloud
                     ↓                              ↓
   http://<ws-ip>:<port>/api/v1/pos/*        https://<backend>/api/v1/*
   (LAN; same-origin for the /pos build)     (Cloud; direct, never proxied)
```

## Provider nesting

```
StrictMode
  └─ ErrorBoundary  ← Sentry-wired; catches everything below
      └─ BrowserRouter
          └─ AppProvider          ← theme + locale
              └─ QueryProvider     ← TanStack Query, Devtools gated to DEV
                  └─ AuthProvider  ← SSO session
                      └─ WorkstationProvider  ← LAN discovery + base URL
                          └─ App
                              └─ Toaster
```

## Folder structure

```
web/pos/
├── src/
│   ├── main.tsx              ← initSentry() before mount
│   ├── App.tsx
│   ├── app/
│   │   └── pos/
│   │       ├── page.tsx          ← main POS surface, 905 dòng (ĐANG ĐÚNG TRẦN, xem dưới)
│   │       ├── components/       ← 43 components: order-cart, payment-dialog,
│   │       │                        split-bill-by-items, void-order, etc
│   │       ├── hooks/            ← 9 hook lát-cắt: receipt-flow, cart-item-actions,
│   │       │                        order-lifecycle, table-assignment, tab-sync,
│   │       │                        payment-actions…
│   │       └── types.ts          ← Manual TS mirror of backend Eloquent resources
│   ├── components/
│   │   ├── error-boundary.tsx    ← Sentry captureException
│   │   └── error-fallback.tsx
│   ├── providers/
│   │   ├── auth-provider.tsx
│   │   ├── query-provider.tsx    ← Devtools gated to import.meta.env.DEV
│   │   ├── app-provider.tsx
│   │   └── workstation-provider.tsx
│   ├── hooks/api/                ← 9 TanStack Query hooks per domain
│   ├── lib/
│   │   ├── api.ts                ← apiFetch + ApiError
│   │   ├── auth.ts               ← Token persist (localStorage + cookie)
│   │   ├── sentry.ts             ← Error tracking init
│   │   └── query-retry.ts
│   └── services/
│       ├── workstation/
│       │   └── base-url-resolver.ts  ← API mode + LAN/cloud target (see above)
│       ├── order-service.ts
│       └── ...
├── index.html                    ← CSP + Referrer-Policy + Permissions-Policy meta
├── .env.example
└── eslint.config.js
```

## Security posture

### CSP

`index.html` ships a strict-ish CSP meta tag:

- `script-src 'self'` — only the bundled JS, no inline scripts
- `style-src 'self' 'unsafe-inline' https://fonts.googleapis.com`
- `connect-src 'self' http: https: ws: wss:` — wide because pos-web talks to mDNS-discovered private IPs over HTTP, cloud HTTPS, AND Pusher wss. Tightening to specific RFC1918 wildcards is a tracked follow-up.
- `frame-ancestors 'none'` — clickjacking defense
- `object-src 'none'` + `base-uri 'self'` — defensive baseline

Plus `Referrer-Policy: strict-origin-when-cross-origin` and `Permissions-Policy` denying camera/mic/geo/usb/bluetooth.

### Token storage

**Current state** (`src/lib/auth.ts`):

- `localStorage.setItem("token", ...)` — readable by any JS
- `document.cookie = "token=...; SameSite=Lax; Secure"` — Secure flag added when `window.location.protocol === "https:"`

Token persists for 1 year. **HttpOnly migration is tracked as a follow-up** — would require server-issued `Set-Cookie` at the SSO callback, not currently in scope. Comment in `persistSession()` spells out the TODO.

XSS surface is mitigated by CSP `script-src 'self'`, but a CVE in any dep (Radix, sonner, TipTap, recharts, etc) bypasses this. Treat the bearer cookie as security-critical.

### React Query Devtools

Gated to `import.meta.env.DEV` via lazy import + Suspense (`src/providers/query-provider.tsx`). Production bundle does NOT include the Devtools panel — verified by grepping `dist/assets/*.js` for `react-query-devtools` after `pnpm build` (returns empty).

## Observability

- **Sentry SDK** wired via `src/lib/sentry.ts`. Init gated on `VITE_SENTRY_DSN`; silent no-op when unset. `sendDefaultPii: false`, Sentry Replay disabled, `tracesSampleRate: 0.1`.
- **beforeSend** scrubs `Authorization: Bearer ...` + `token=...` cookie fragments + email addresses from breadcrumbs AND from exception messages + `componentStack` + `extra` (the Sentry-internal paths that breadcrumbs don't cover).
- **ErrorBoundary** routes `componentDidCatch` to `captureException` with `tags: { boundary: "global" }` + componentStack as extra.

### Two boundaries, not one (#1738)

| Boundary | Where | What it saves |
|---|---|---|
| app | `main.tsx` → `components/error-boundary.tsx`, `boundary: "global"` | the white screen |
| **per dialog** | `components/ui/dialog.tsx` → `DialogBoundary`, `boundary: "dialog"` | **the cashier's open order** |

The app boundary saves the page but **unmounts the whole tree** — a render crash
inside the payment or topping dialog takes the order screen with it, which is the
loss of context #279 is about, one order of magnitude smaller. The per-dialog
boundary keeps the order: only the dialog body collapses into a retry/close card,
the cashier reopens it, the order is still there.

It is wired **inside** a pos-web-local `DialogContent`, not around it — the
`@godxjp/ui` `DialogContent` is what renders the portal, overlay and close
button, so a boundary outside it would make the modal vanish with no way to
dismiss it. `DialogBoundary` is a class component returning `children`, so the
happy path adds **no DOM node** and the 22 dialogs' grid/flex layout is unchanged.

**Always `import { DialogContent } from "@/components/ui/dialog"`** — never from
`@godxjp/ui`. `src/__tests__/dialog-boundary.arch.test.ts` fails the build
otherwise, because a bypassed boundary compiles, renders identically, and shows
up only the day a dialog crashes over a real order.

See [`docs/explanation/observability.md`](../../docs/explanation/observability.md) for the umbrella-wide deployment guide.

## Error envelope

`src/lib/api.ts::ApiError` parses `{message}`, the Laravel validation
envelope `{message, errors: {field: [...]}}` (first per-field message becomes
`error.message`; the full map rides `error.fieldErrors` /
`firstFieldError()`), and RFC 7807 `application/problem+json`
(`detail` → `title`) — #284 hardening, 2026-07-27.

## Idempotency

Mutation hooks send `idempotency_key` in the **request body**, NOT the `Idempotency-Key` header. Backend currently accepts both shapes for `/pos/payments` but the header is the canonical contract per the workstation-side mirror. Comment in `src/providers/query-retry.ts:15` previously claimed header-style — the comment was wrong (corrected in Sprint A.5).

## Design system

- Font: **M PLUS 2** loaded from Google Fonts with `latin-ext` + `vietnamese` subsets (`index.html`). Fixes "bằng" → "bă`ng" rendering on Windows where Yu Gothic fallback doesn't support combining marks.
- Colors: OKLCH tokens in `src/styles/globals.css`. Chroma ≤ 0.18 for primaries per the SmartHR/渋み design system.
- Density: **h-11 / h-12 control heights** (44–48px) — deliberately bigger than the 32px umbrella default because POS is a touch terminal. The taller buttons reduce mis-touch on a tablet held by a cashier mid-checkout.

### Padding của `@godxjp/ui` KHÔNG tự có — phải truyền tay

pos-web **không import** `@godxjp/ui/styles` (theme.css). Mà một số primitive
của thư viện tự chừa padding bằng utility dựng trên token nằm TRONG file đó:

| Primitive | Class nó tự thêm | Token cần |
|---|---|---|
| `Card` / `CardHeader` / `CardContent` | `gap-card` · `px-card` · `pt-card` · `pb-card` | `--spacing-card` |
| `SheetHeader` / `SheetFooter` | `p-[var(--density-sheet)]` | `--density-sheet` |

Token không tồn tại ⇒ Tailwind **không sinh ra class nào** ⇒ padding bằng 0, và
chữ dính sát viền. Nó hỏng **im lặng**: không lỗi build, không cảnh báo, chỉ là
một cái thẻ trông như bị vỡ.

Vì vậy quy ước ở đây là **luôn truyền padding tường minh**:
`<Card className="gap-0 p-0">` + `<CardHeader className="border-b px-5 py-4">` +
`<CardContent className="px-5 py-5">` (hoặc `p-0` rồi tự pad từng dòng). Mọi màn
shift đã làm vậy từ đầu; trang Cài đặt là chỗ duy nhất từng tin vào token và đó
đúng là chỗ đã hiện ra lỗi.

Đừng "sửa tận gốc" bằng cách khai `--spacing-card` trong `globals.css`: mọi Card
hiện có đều đã truyền padding tường minh, nên thêm token là thêm một lớp padding
thứ hai vào chúng — tailwind-merge không nhận ra `px-card` cùng nhóm với `px-5`,
nên hai class cùng tồn tại và thắng thua do thứ tự CSS quyết định.

## i18n

Custom `useTranslation()` hook reading flat-dot-key JSON: **977 lines each, ja = en = vi**
(the `430 / 429 / 429` written here before was stale by ~550 keys, and it also implied a
parity gap that does not exist — `src/i18n/catalogue.test.ts` fails on any). Default locale
is `vi` (`VITE_DEFAULT_LOCALE`).

**Drift to fix**: `src/components/error-fallback.tsx:8-27` has its own inline STRINGS table duplicated from `i18n/*.json`. Tracked as a Sprint follow-up.

## TypeScript

`tsconfig.app.json` has `"strict": true` (Sprint B). Verified by `npx tsc -b` returning zero errors on the current tree. `noUncheckedIndexedAccess` is NOT enabled yet — flipping it would flood Array/Map access sites.

## Offline app shell (PWA) — cloud build ONLY (#1170 tier 1)

`vite-plugin-pwa` precaches the shell so losing the network shows the app, not a white
screen. Options live in `src/lib/pwa-options.ts` (not inline in `vite.config.ts` — see why
below); registration lives in `src/components/offline-shell.tsx`.

**This is NOT offline-sales.** Trustworthy offline money needs Ed25519 evidence + catalog
revision + Cloud re-price (#1092) and that is the **workstation's** role — a shared tablet
browser holding a signing key is a weaker trust model, deliberately not replicated. A shop
that must sell offline installs the workstation. Tier 2 (idb read cache, offline banner,
light non-money action queue) shipped under #1501 — see the next section.

Four decisions that all fail **silently** if reversed — each pinned by
`src/lib/pwa-options.test.ts`:

| Decision | What breaks if reversed |
|---|---|
| `disable: mode === "workstation"` | The workstation build is served same-origin off the shop PC's disk and already versions itself via `pos-bundle-version.json`. A second SW cache = two layers with two opinions about "latest" — the plan-052 P-19 version skew. |
| Plugin stays in `plugins`, switched off via `disable` (never removed from the array) | `virtual:pwa-register/react` is a virtual module **the plugin provides**. Drop the plugin and the import fails to resolve — the **workstation build breaks at build time**. |
| `injectRegister: null`, app registers itself | `index.html` sets `script-src 'self'` with no `'unsafe-inline'`, so a plugin-injected inline register script is CSP-blocked. The page still works; the SW simply never registers, and you find out only after a cashier has already lost network. |
| `/api/**` → `NetworkOnly` + `navigateFallbackDenylist: [/^\/api\//]` | Money must never be read from cache, and returning the SPA shell for an API call turns "offline" into "parse error" one layer up. |

`registerType: "prompt"` — a POS must not reload itself mid-order. A new SW raises a toast
(ja/en/vi under `pwa.update.*`) and waits for a human.

No SW in `pnpm dev` (`devOptions.enabled: false`): it fights HMR and serves stale assets
between edits. To exercise it by hand: `pnpm build:cloud && pnpm preview`.

Testing the boundary: **plugin names are identical in both modes** (`disable: true` still
registers all five `vite-plugin-pwa:*` plugins), so asserting on the plugin list yields a
test that is green either way. Assert on the options; prove the artifacts from a real build
— `dist/sw.js` + `manifest.webmanifest` exist for cloud and are absent for
`--mode workstation`.

`workbox-window` is a required devDependency: the prompt flow's virtual module imports it,
and without it the cloud build fails to resolve. (godx-kds doesn't need it — it uses
`autoUpdate` and never imports the virtual module.)

## Offline tier 2 — read cache, banner, light queue (#1501)

Tier 1 stops the white screen. Tier 2 is about the **data on that screen** and what a
cashier is allowed to do with it. Still **not** offline-sales: nothing here creates,
prices, or settles money.

| Piece | File | The decision that matters |
|---|---|---|
| idb store | `src/lib/idb.ts` | Best-effort — every function swallows. No cache is never "POS broken". |
| **Cache policy** | `src/lib/offline-cache-policy.ts` | **ALLOWLIST, not a denylist.** `isCacheableQueryKey` defaults to `false`, so a domain added next week is not cached by accident. |
| Query wiring | `src/lib/offline-cache.ts` | Hydrates with `updatedAt: cachedAt` — the **real age**. |
| Offline signal | `src/lib/network-status.ts` | Fed by `apiFetch`, not by `navigator.onLine`. |
| Banner | `src/components/offline-banner.tsx` | Says the data's age, out loud. |
| Blocked actions | `src/hooks/use-network-required.ts` | Payment / shift open / shift close are **disabled**, never queued. |
| Light queue | `src/lib/offline-action-queue.ts` | Closed union, one member, none of it money. |

**Cached:** `shop` (incl. order settings / tax context), `shop-menus`, `tables`,
`void-reasons`, `floating-sections`. **Never cached:** `orders`, `order-payments`,
`payment-methods`, `effective-payment-options`, `till`, `revenue`,
`customer-outstanding` — those are the numbers a cashier reads aloud before taking cash,
and 40 minutes stale means taking the wrong amount. Pinned by
`offline-cache-policy.test.ts`, which asserts against the live key factories rather than
retyped strings.

The service-worker layer is untouched: `/api/**` is still `NetworkOnly` (tier 1). This
cache lives at the **application** layer — deliberate and readable, not an implicit HTTP
cache.

Four things that fail **silently** if reversed:

| Decision | What breaks |
|---|---|
| `hydrateQueryCache` sets `updatedAt: cachedAt` | This *is* the revalidation mechanism. With `Date.now()` the cache looks fresh, TanStack sits on 30s of `staleTime`, and the POS shows day-old data with nothing to refetch it. You then rebuild revalidation somewhere else. |
| Hydration skips keys whose `dataUpdatedAt >= cachedAt` | Hydration races the first fetches. Without the check, a slow IndexedDB open **overwrites data just fetched** — the screen jumps back one beat, and no cashier report will ever let you find it. |
| Offline is derived from `apiFetch` outcomes, threshold 2 | `navigator.onLine === true` proves almost nothing (Wi-Fi up, workstation off). Threshold 1 makes the banner flicker on a single aborted request, and a flickering banner gets ignored before the real outage. |
| Light queue keeps on network failure, **drops** on 4xx/5xx | Same predicate as `apiFetch` (`isNetworkError`, now exported). A second copy drifts, and the drift shows up as a queue that never drains. |

**Why the light queue has exactly one member.** #1501 named "note" and "draft order" as
examples. Neither exists here: order-level note has **no UI** (`useOrderFieldSave`'s `note`
branch has no caller — the only two call sites send `table_ids` / `guest_count`), and item
notes / draft ordering are **bill-changing** mutations gated per-status server-side by
#1148 — a gate that cannot be evaluated offline. Table status is the only genuinely light
action with a live UI. Its 15-minute TTL exists because replaying a 40-minute-old table
status is more likely to overwrite a *newer* truth from another device than to be "late".

## Testing

- Vitest 4 + jsdom. **1389 tests / 134 files**, hook + service + một số màn tiền.
- **CI chạy `pnpm test:coverage`, không phải `pnpm test`** (#284). Khác biệt không
  phải hình thức: `pnpm test` KHÔNG đọc ngưỡng phủ, nên nó chạy đúng bấy nhiêu
  test rồi thoát 0 dù phủ tụt bao nhiêu. Ngưỡng sống trong `vitest.config.ts`.
- **Ngưỡng phủ theo TỪNG THƯ MỤC**, kiểu bánh cóc chỉ-được-tăng: `lib/` 88 ·
  `services/` 66 · `providers/` 75. Theo thư mục chứ không một số tổng, vì `lib/`
  đang 88% dư sức che cho `services/` tụt xuống 40% mà số tổng vẫn qua.
- **Trần dòng cho `src/app/pos/page.tsx`**: 905, chỉ được GIẢM
  (`src/__tests__/page-size-budget.arch.test.ts`, #1770). File này đi 1289 →
  1387 (lúc #283 đóng "completed") → 1760, tức việc tách component có xảy ra
  nhưng file gốc chưa bao giờ nhỏ đi; #1770 dựng rào TRƯỚC rồi mới tách
  1760 → 971 → 950 → 926 → 906 → 905. **File đang NẰM ĐÚNG TRÊN trần**, nên thêm một dòng
  vào đó là đỏ ngay — việc bổ sung nút `?` phải đi đường `PosHeader` tự suy chủ
  đề từ route chính vì lẽ đó. Tính năng mới đi ra `src/app/pos/components/` (giao diện) hoặc
  `src/app/pos/hooks/` (state + handler); tách được thì hạ trần trong cùng PR.
  Test thứ hai bắt trần phải bám sát thực tế (chênh ≤ 200 dòng), nên hạ được
  mà quên hạ trần là đỏ.
- **Lát cắt của page.tsx là HOOK, không phải component 25-prop.** Mỗi hook
  trong `src/app/pos/hooks/` sở hữu một mảng state + effect + handler của
  riêng nó và trả về một API nhỏ: `use-receipt-flow` (hai màn biên lai + đóng
  tab hoãn lại), `use-cart-item-actions` (thao tác trên dòng giỏ),
  `use-order-lifecycle` (checkout/tiếp nhận/huỷ + coupon),
  `use-table-assignment`, `use-edit-order-item`, `use-payment-actions`,
  `use-print-result`, `use-pos-tabs`, `use-tab-sync` (đồng bộ dải tab với
  nguồn đơn-đang-mở, và GHIM tab khi đang có màn tiền mở trên nó). Hook nào
  cũng tự giữ mutation của mình TRỪ khi mutation đó dùng chung (ví dụ `releaseCoupon` được truyền vào
  `use-order-lifecycle` vì luồng xung đột promo cũng cần).
- **Known coverage gap**: 29 components in `src/app/pos/components/` (payment-dialog, split-bill, void-order, table-merge) have ZERO tests. README's "Phase 1" deliberately excluded payment UI. This is the cash-handling surface — track as a Sprint C follow-up.

## Tiền mặt khi chia bill — "khách đưa bao nhiêu, thối bao nhiêu"

Mỗi dòng của **cả ba** tab chia bill (chia đều · theo số tiền · theo món) có ô
nhập tiền khách đưa, hiện ô đó **chỉ khi** phương thức đã chọn có
`requires_tendered`. Toàn bộ phép tính nằm ở `src/app/pos/lib/cash-tender.ts`
(`change = tendered − amount`, `valid ⇔ tendered ≥ amount`) và một component
dùng chung `components/cash-tender-field.tsx` — ba tab có ba row-state và ba
đường submit riêng, chúng **đã từng lệch nhau**, nên luật tiền phải ở một chỗ.

Bốn quyết định, đảo lại là hỏng mà không kêu:

| Quyết định | Đảo lại thì sao |
|---|---|
| Ô để trống = **đưa đúng** (tender = phần chia), và ô đó **hiện sẵn** số phần chia | Bắt gõ sẽ giết luồng một-chạm đang có; để trống mà không hiện số thì con số được ghi vào DB là con số thu ngân không nhìn thấy |
| Tender ngắn / vô nghĩa **chặn nút Thu** | Cả workstation (`local_pos.go`) lẫn Cloud (`OrderPaymentService`) đều 422 khi `tendered < amount + tip` — để bấm được là để thu ngân gặp lỗi giữa lúc khách đứng chờ |
| Đổi phương thức thì **xoá tender** | Số tiền mặt gõ cho dòng cash sẽ trôi sang dòng thẻ và in ra dòng お預かり cho khoản tiền chưa từng đưa |
| Chặn tender > `MAX_TENDERED_AMOUNT` (99.999.999) | Cloud validate `max:99999999`; workstation nhận rồi **dead-letter vĩnh viễn** khi sync UP — một chữ số thừa lặng lẽ tách một khoản tiền thật khỏi Cloud |

Số đi tiếp: `tendered_amount` trong body POST → `payments.tendered_amount` /
`change_amount` (server tự tính change) → `SplitBillSessionResult.guests[]` →
`SplitBillReceiptDialog` (hiện theo từng khách) → phiếu in của workstation
(`お預かり` / `お釣り`, `print_renderer_bill.go`).

**Phía workstation phải tra theo `payment_id`, không theo số tiền.** Chia đều
làm nhiều khách nợ **cùng một số tiền**, nên khớp-theo-amount trả về dòng mới
nhất — in お預かり/お釣り của khách #3 lên phiếu khách #1. Đã sửa ở
`internal/handler/print_receipt.go::loadTenderedChange` (nhận `paymentID`, ưu
tiên tuyệt đối; dòng non-cash được chỉ đích danh thì trả 0/0 chứ không mượn số
của khách khác). **Deploy workstation kèm pos-web** cho phần in.

Tiền **chưa bao giờ sai** ở tầng sổ sách: mọi số till/Z-report/reconcile cộng
`order_payments.amount`. `tendered_amount`/`change_amount` là số để in.

## Hướng dẫn trong ứng dụng — nút `?` (#2110)

Mọi trang, hộp thoại, modal và panel đều có một nút `?` mở CHUNG một drawer bên
phải. Nội dung ở `src/help/content/{ja,en,vi}.ts` — **dữ liệu thuần, không phải
JSX** — mỗi locale export một `Record<HelpTopicId, HelpTopic>`, nên thêm chủ đề
mà quên một bản dịch là **lỗi biên dịch**, không phải thứ thu ngân phát hiện ra.

Vì sao KHÔNG nằm trong `src/i18n/*.json`: các file đó là chuỗi giao diện dạng
khoá-phẳng, còn hướng dẫn thì có cấu trúc và **thứ tự** — `usage[3]` phải đứng
sau `usage[2]`, và một map phẳng không nói được điều đó. Chỉ phần khung
(`help.*`) ở lại JSON.

Năm phần mỗi chủ đề. Phần thứ hai — **`setup`, "điều kiện & thiết lập bên
ngoài"** — là lý do hệ thống này tồn tại: phần lớn "POS không làm được X" không
phải lỗi mà là X đang tắt ở admin-web / HQ / chính sách thanh toán, hoặc cần một
máy trạm chưa lắp. Màn hình chỉ mô tả nút của chính nó sẽ đẩy thu ngân đi tìm
sai chỗ.

Bốn quyết định, đảo lại là hỏng:

| Quyết định | Đảo lại thì sao |
|---|---|
| Drawer là Radix `Sheet`, KHÔNG phải overlay tự dựng | Đa số nút `?` nằm BÊN TRONG một dialog. Overlay portal tới `document.body` là anh em của portal dialog cha, nên Radix đọc mọi click trong đó là click RA NGOÀI: mở hướng dẫn từ màn thu tiền sẽ **đóng mất màn thu tiền** cùng số tiền thu ngân vừa gõ. Ghim bởi `help-button.test.tsx`. |
| `HelpButton` dùng `useOptionalTranslation()`, không phải `useTranslation()` | `GapReconcilePanel` cố ý render được ngoài `AppProvider` (nhận `t` qua prop) để giữ tính thuần theo đầu vào; một component dùng chung không được phép tước mất tính chất đó của mọi nơi nó rơi vào. |
| `PosHeader` tự suy chủ đề từ route (`helpTopicForPath`) | `page.tsx` đang nằm ĐÚNG TRÊN trần (905 dòng lúc viết; đếm tại chỗ) — truyền thêm một prop qua nó là đỏ. Sáu màn dùng `PosHeader` có hướng dẫn mà không tốn dòng nào ở đó. |
| `type="button"` trên nút `?` | Trang ghép nối và hộp thoại thu/chi là `<form>` thật; một `<button>` trần mặc định là submit, nên hỏi hướng dẫn sẽ gửi luôn form. |

`help-content.test.ts` ghim ba thứ mà TypeScript không thấy được: nội dung
không rỗng, không có mục trùng trong cùng một danh sách (React key là chính
chuỗi đó — trùng là mất một dòng hướng dẫn), và **mọi chủ đề phải được gắn ở
đâu đó**. Nội dung không ai mở được còn tệ hơn không có: nó đọc như đã phủ.

## Bàn CÓ NGƯỜI mà CHƯA CÓ ĐƠN — đừng gọi nó là "bàn ma" (#3009)

Khách quét QR bàn ⇒ `CustomerTableSessionService` lật `free → occupied` và mở
`TableSession` **TRƯỚC khi có đơn nào**. Bàn đó **đang có người thật** — không
xếp khách khác vào được — nên trạng thái này là **thiết kế**, không phải rác.

Mã cũ gọi nó là `isGhostOccupied` ("bàn ma"). Cái tên đó **gây hại đo được**: nó
làm người đọc tưởng phải DIỆT trạng thái, nên suốt thời gian dài không ai đi sửa
ba thứ thật sự sai. Nay là `isSeatedNoOrder`.

**Ca thật, 本郷店 18:04 CN 16/08/2026**, giữa ca tối: quán báo *"giờ A8 order thì
C1 lại hiện"* — họ đọc màn hình thành "đơn của A-8 hiện sang C-1". Thật ra C-1 là
bàn riêng, khách đã ngồi chưa gọi món. Màn hình gọi **cả hai** là "Đang phục vụ"
nên chúng không phân biệt được.

Ba luật, đảo lại là quán đọc sai lần nữa:

| | |
|---|---|
| **Nhãn riêng.** `isSeatedNoOrder` ⇒ "Đã ngồi, chưa gọi món", KHÔNG dùng lại nhãn "Đang phục vụ" | Đó chính là cái làm 本郷店 tưởng đơn hiện nhầm bàn. `table-picker.tsx` đã phân biệt đúng từ trước — màn Tổng quan là chỗ còn gộp |
| **Đếm riêng.** "Đang phục vụ n/N" chỉ đếm bàn CÓ đơn | Quán đọc con số đó để biết còn mấy bàn nhận khách được. Gộp vào là trả lời sai đúng câu họ hỏi |
| **Hành vi click KHÔNG đổi** (#2524) | Ô vẫn mời tạo đơn, menu `⋮` vẫn đổi được trạng thái — đó là đường quán tự gỡ ngay tại chỗ, và là thứ duy nhất dùng được trước khi reaper chạy |

Ô thống kê thứ hai **chỉ hiện khi > 0**: một ô luôn bằng 0 là nhiễu, và nhiễu
lặp lại thì người ta thôi đọc cả thanh.

**Bàn kiểu này tự gỡ RẤT chậm**: `dine-in:expire-stale-sessions` chạy mỗi giờ
nhưng chỉ dọn phiên mở **quá 4 giờ** (`backend/routes/console.php`). Khách quét
nhầm rồi bỏ đi ⇒ mất một bàn cả buổi. Cửa sổ đó là #3010, cùng với việc
`betoya.jp` trả 524 — mỗi lượt quét hỏng sau khi đã lật trạng thái để lại đúng
một bàn như vậy.

## Bill printing (plan-038)

Workstation print is owned by `src/services/workstation-print-service.ts`. Ten methods, all `/api/lan/print/*` with LAN-only Bearer auth (never falls back to Cloud — the endpoint doesn't exist on Cloud):

| Method | Workstation route | Purpose |
|---|---|---|
| `printKitchenTicket({orderId})` | `POST /api/lan/print/kitchen-ticket` | Fire kitchen tickets per `printer_group` (kitchen / bar / hold). |
| `printKitchenReprint({orderId})` | `POST /api/lan/print/kitchen-reprint` | "In lại phiếu bếp" — paper only, never fires. See the reprint section below. |
| `printOrderBill({orderId})` | `POST /api/lan/print/order-bill` | "In phiếu order" — full-order bill + QR, on demand, no reprint limit. |
| `printPaymentReceipt({orderId, paymentId?, reprintReason?})` | `POST /api/lan/print/payment-receipt` | PAID slip + optional PHẦN CÒN LẠI. `payment_id` targets one split row; without it the legacy "last confirmed" path runs. |
| `printRedInvoice({orderId, customerName?, paymentId?})` | `POST /api/lan/print/red-invoice` | HOÁ ĐƠN ĐỎ — paid slip + named-buyer line. `payment_id` targets one split payer (#1779). |
| `printDebtSlip({orderId, paymentId, reprintReason?})` | `POST /api/lan/print/debt-slip` | PHIẾU GHI NỢ — on_account payments only. |
| `printShiftReport({shopSlug, sessionId, reportKind?})` | `POST /api/lan/print/shift-report` | 精算 (Z) report on shift close; `reportKind: "handover"` prints a 引き継ぎ header (plan-046). |
| `printChainReport({shopSlug, chainId})` | `POST /api/lan/print/chain-report` | Plan-046 chain aggregate (kết ca cuối). |
| `printShiftOpenReport({shopSlug, sessionId, deviceName?})` | `POST /api/lan/print/shift-open-report` | レジ開け opening cash-count report. |
| `getPrintStatus({orderId?})` | `GET /api/lan/print/status` | Printer-role probe + sync cursor age. |

The three report methods are **best-effort by contract**: they resolve (never
throw) on workstation-unreachable / no-printer / 404-old-build, because the
caller fires them after the shift is already settled and a cold printer must not
unwind a close. Genuine 5xx still bubble up.

**`printVatInvoice` is gone (#1779, 2026-08-04)** together with the workstation
route it called. The red invoice is printed, never stored — use `printRedInvoice`.
`VatInvoiceFormDialog` was replaced by `RedInvoiceDialog` in the same change.

Error envelope is `ApiError(status, body)`. Common codes:
- 503 `{status:"no_printer", detail:"kitchen_printer"}` — toast `pos.kitchen.no_printer`
- 504 `{message:"force-pull timed out", retry_after_ms:1500}` — toast `pos.kitchen.sync_pending`
- 409 `{message:"payment not confirmed"}` — surfaces inline on receipt-targeted reprints
- 0 with `{message:"workstation unreachable"}` — synthetic for fetch failures

The print flow respects the silent-no-op contract: when `workstationPrintService.enabled === false` (env var unset) callers should hide their print UI. Existing surfaces (`KitchenFireButton`, `PaymentReceiptDialog`, `SplitBillReceiptDialog`, `DebtChargeButton`, `RedInvoiceDialog`, `ReprintButton`) all gate on this.

### In lại từ màn lịch sử — `/reports/history`

Mọi nút in khác sống trong luồng bán hàng, tức chỉ với tới được ở đúng khoảnh
khắc thu tiền và không bao giờ nữa. `ReprintButton`
(`app/pos/components/reprint-button.tsx`) là đường duy nhất quay lại được với
một đơn đã đóng, và `OrderDetail` bật nó qua prop **`allowReprint`**.

**Cả hai màn đều truyền nó từ #3040** — trang lịch sử toàn cửa hàng VÀ
`TableHistoryView` ngay trên màn bán hàng.

Trước #3040, màn theo bàn cố ý KHÔNG truyền: nó mở giữa giờ phục vụ cạnh một đơn
đang sống, nên một cú chạm nhầm là giấy khách không hề xin. Lý lẽ đó không sai —
nó thua một chi phí lớn hơn, và là chi phí **đang xảy ra**.

Đơn trả **online** không có màn biên lai nào bật ra: khách trả bằng QR/thẻ ở
Cloud, đơn sync-down về `closed`, và tờ giấy duy nhất tự in là phiếu
「ĐÃ THANH TOÁN BÀN X」 cho người dọn bàn — không phải biên lai của khách. Muốn
in, thu ngân phải rời màn bán hàng: Báo cáo → Lịch sử → tìm đơn → in. Quán báo
đúng chuyện đó: *"khách phải chờ rất lâu"*.

Chủ dự án chốt 2026-08-16: giấy in nhầm là chi phí **có thể** xảy ra và rẻ;
khách chờ là chi phí **chắc chắn** và đang diễn ra.

Rào tự nhiên vẫn nguyên và **không nới**: nút chỉ hiện khi `status === "closed"`
VÀ có thanh toán đã vào tiền — nên đơn đang sống, thứ ruling cũ lo, không bao giờ
hiện nút. Đó cũng là lý do việc đảo này rẻ hơn vẻ ngoài.

| kind | route | điều kiện hiện |
|---|---|---|
| `receipt` | `payment-receipt` | `status === "closed"` **và** có thanh toán `succeeded`/`confirmed` |
| `red_invoice` | `red-invoice` | như trên |
| `kitchen` | `kitchen-reprint` | còn ít nhất một món chưa huỷ |
| `hold` | `order-bill` | còn ít nhất một món chưa huỷ |

Ba điều kiện khác nhau vì ba tờ giấy nói ba điều khác nhau. **Hoá đơn khẳng định
việc mua bán đã KẾT THÚC** (ruling của chủ quán), nên mời in nó trên đơn khách
còn đang ngồi ăn là đưa khách chứng từ về một việc chưa xảy ra; `closed` mà
không có dòng thanh toán nào thì workstation cũng không có gì để dựng tờ giấy và
nút sẽ 404. Phiếu bếp / phiếu order thì chỉ kể lại đơn đang có gì — bếp làm rơi
phiếu giữa giờ phục vụ là lúc CẦN in lại nhất, và đơn lúc đó còn đang mở.

**`kitchen` gọi `/kitchen-reprint`, KHÔNG BAO GIỜ `/kitchen-ticket`.** Đường thứ
hai là ĐIỀU MÓN: nó đóng delta và bắn `order.kitchen_printed` cho mọi màn KDS
nạp lại. Trên đơn đã xong delta bằng 0 nên nó trả 422 — và nếu nó in thật thì
đơn đã thanh toán rơi trở lại màn hình bếp như việc mới. Cả hai đường đều trả
200 trong test nên việc gọi nhầm là im lặng; `order-reprint.test.tsx` ghim nó.

Bill chia thì **mỗi dòng thanh toán có nút riêng** (`paymentId`): nút ở trên in
"thanh toán xác nhận gần nhất", tức tờ của khách CUỐI, còn người đang đứng hỏi
có thể là khách #1.

### Cặp `In gốc` / `In lại` — vì sao KHÔNG phải một nút `In`

Chứng từ TIỀN (`receipt` · `red_invoice`) hiện **hai** nút; phiếu bếp / phiếu
order hiện **một**. Ranh giới là bộ đếm: hai loại đầu đi qua `printjob.Reserve`,
mang số bản và dấu 「BAN IN #N」 từ bản thứ hai; hai loại sau thì không, nên với
chúng "gốc" và "bản sao" không phải hai thứ khác nhau và một cặp nút sẽ hứa một
sự phân biệt tờ giấy không hề có.

| tally cho phạm vi này | `In gốc` | `In lại` |
|---|---|---|
| `printedCount === 0` | **bật** — ra bản #1, không dấu | khoá |
| `printedCount ≥ 1` | khoá + hiện "đã in ×N" | **bật** — ra bản #`nextCopyNo` |
| `unknown` | gộp về MỘT nút `In` trung tính | |

Ruling chủ dự án ở #2535 A7. Lý do nó bắt buộc: chính #2535 đẻ ra một lớp đơn
**đã thu tiền mà chưa từng ra tờ giấy nào** (thu bằng 釣銭機 xong không màn nào
mở ra để in). Với chúng tờ đầu tiên là bản GỐC, và một nút `In` chung buộc phải
ĐOÁN — nó sẽ đoán sai đúng ở lớp đơn đó.

**Nút bị khoá KHÔNG phải nút bị cấm.** Luật "warn, never block" (plan-052 §4 /
#1166) vẫn nguyên: luôn còn đúng một nút bấm được. `In lại` xám nghĩa là chứng từ
này chưa từng in cho phạm vi này — bấm `In gốc`, ra đúng tờ giấy đó, và sau khi
in thì hai nút đổi vai. Hàng `unknown` không được đoán (`print-copy-state.ts`).

**Số bản in là của SERVER, client không bao giờ gửi.** `printjob.Reserve` lấy
`MAX(reprint_no)+1` trong một `BEGIN IMMEDIATE`; hai tablet in cùng lúc mà client
tự đánh số thì cả hai cùng ra #1. Tally ở đây chỉ để bật/khoá nút và nói trước
tờ sắp ra mang số mấy.

Chỉ nhánh `In lại` hỏi `reprint_reason` (`ReprintReasonDialog`, và ô lý do trong
`RedInvoiceDialog` khi `askReprintReason`). **Hỏi, KHÔNG bắt buộc** — ô trống vẫn
in, Cloud tự suy ra `warned_without_reason`. Bản gốc không hỏi: không có gì để
giải thích, và bắt nó giải thích sẽ dạy thu ngân bấm qua hộp thoại mà không đọc.

Tally đến từ **một** lượt probe cho cả màn (`useOrderPrintCounts` →
`GET /api/lan/print/status?order_id=`, trả `receipt` · `red_invoice` ·
`debt_slip` trong một response). Một hook cho mỗi loại sẽ là N round-trip cho một
màn và N câu trả lời có thể mâu thuẫn. Không poll — một poll 30s đã từng xoá sạch
ô nhập của thu ngân trong repo này.

## 釣銭機 (Glory cash recycler) — LAN only, via the workstation (#1804, Gate 8 ruling A)

`src/services/workstation-cash-changer-service.ts` + `src/app/pos/hooks/use-cash-changer.ts`
+ `src/app/pos/components/cash-changer-overlay.tsx`, wired into `payment-dialog`.

**It can never go direct.** The machine speaks HTTP/JSON on the LAN with **no TLS** and an
IP allowlist; a cloud pos-web is HTTPS, so a direct call is mixed-content — the same wall
`#1169` built the same-origin `/pos` mount to get around. The workstation is the sole host
of the driver and every LAN client reaches the machine through it, kiosk included (the
kiosk has its own mount, `/api/v1/kiosk/cash-changer/*`, because `policyPosWeb` refuses
kiosk device tokens by design). **A shop that wants a 釣銭機 installs a workstation** —
there is no cloud-only bridge, by decision.

Two things that would cost real money if reversed:

| Decision | What breaks |
|---|---|
| **pos-web never creates a payment for this flow** | `cash_changer_recorder.go` is "the single writer of the payments table for the cash-changer flow": on `finish` the workstation has already inserted the payment (idempotent on the Glory transaction id), run the lifecycle and queued the sync UP. The POS only invalidates and lets it appear. Posting one here **double-charges the customer**. |

### "POS chỉ invalidate" đúng về TIỀN, và đó chính là chỗ nó bỏ quên tờ giấy (#2535 B1/B3)

Câu trên vẫn đúng nguyên văn — nhưng nó mô tả sổ sách, và luồng này còn một
nghĩa vụ thứ hai: **mở màn biên lai**. `onPaymentSuccess` chỉ được gọi trong
`handleConfirm`, mà đường 釣銭機 không đi qua đó; hệ quả là thu xong không màn
nào mở ra, và màn biên lai là **cửa duy nhất** dẫn tới nút "In biên lai" +
`RedInvoiceDialog`. Đơn đã đóng không mở lại được (`reopenOrder` 409 khi đã thu
tiền), nên tờ giấy đó mất vĩnh viễn. Nay `onDismiss` của overlay chuyển tiếp kết
quả đúng như mọi phương thức khác — vẫn **không** tạo payment nào.

Và điều kiện để gọi là thành công là `cashCollectedAndRecorded(session)`, **không
phải** `status === "finish"`:

| snapshot | nghĩa | màn hình |
|---|---|---|
| `finish` + `payment_id` ≠ "" + không `error` | đã thu, đã ghi sổ | "Đã thu xong" + mở màn biên lai |
| `finish` + `payment_id` = "" (hoặc có `error`) | **đã thu, ghi sổ HỎNG** | khối ĐỎ riêng, hiện `error`; KHÔNG mở màn biên lai |
| `timeout` · `abort` · `failure` | máy còn giữ tiền | cảnh báo tiền kẹt (`cashRetainedByMachine`) |
| `cancel` · `shortage` | tiền đã trả lại khách | không cảnh báo gì |

Hàng thứ hai là ca đã đi ra ngoài dưới dạng "Đã thu xong" suốt từ đầu: `error`
chỉ được render bên trong khối tiền-kẹt, mà ca này không phải tiền kẹt.
| **`timeout` ≠ `cancel`** | `cancel` returns the deposited cash; `timeout`/`abort`/`failure` mean the machine **KEPT** it (`cashRetainedByMachine`). Collapsing them into one "failed" tells a cashier the customer has their money back while the machine still holds it. The overlay says so explicitly and refuses to look dismissible. |

Mặc định lượt thu gửi **chỉ `order_id`** — máy trạm tự đọc phần còn thiếu ở phía
server. Ngoại lệ DUY NHẤT là **chia bill** (#2941/#2946): POS gửi kèm `amount`, phần
của MỘT người. Nó phải đến từ client vì `equal-split.ts` tính con số đó từ state của
màn POS (hàng đã khoá, thu ngân sửa tay, làm tròn theo đơn vị nhỏ nhất, phần dư dồn
hàng cuối) — máy trạm không nhìn thấy gì trong đó. Đổi lại, **máy trạm kẹp
`0 < amount <= outstanding` và 422 nếu vượt**; con số client gửi không bao giờ được
tin, chỉ được chấp nhận trong khoảng đã kiểm.

Nút "thu bằng máy" có ở **cả ba tab chia bill** (#2946 tab `even`; #2958
`by_items` + `by_amount`) và đi qua `useMachineCollector` — lớp bắc cầu từ poll
sang một lời gọi `await` được, để tab biết hàng NÀO vừa xong. Ba luật của nó,
đảo lại là sai tiền:

| | |
|---|---|
| Chỉ giải quyết khi `session_id` **khác** cái thấy lúc gọi | `start()` là async: giữa lúc gắn lời hứa và lúc phiên mới hạ cánh còn một lượt render mang phiên TRƯỚC đã terminal. Thiếu vế này, hàng #2 "thành công" bằng tiền của hàng #1 |
| Thành công là `cashCollectedAndRecorded`, **không** phải `finish` | Ca đã thu tiền mà ghi sổ hỏng cũng trả `finish` (#2535 B3) |
| Hàng đi đường máy **không bao giờ** chạm `onCreatePayment` | Xem hàng ngay trên: đó là thu tiền khách hai lần |

**Ba tab vẫn KHÔNG cùng một khuôn, và rào phải dựng lại cho từng cái.** Đo lúc
làm #2958:

| | `even` | `by_items` | `by_amount` |
|---|---|---|---|
| từ vựng trạng thái | `succeeded` | `succeeded` | **`paid`** |
| chốt "đã trả hết" | trong hàm submit | **effect + ref** | trong hàm submit |
| chọn phương thức | Radix `Select` | Radix `Select` | nút thường |

Nên một test viết cho tab này **không nói gì** về tab kia — mỗi tab có bản assert
"không gọi `onCreatePayment` lần nào" của riêng nó
(`split-bill-machine-collect{,-modes}.test.tsx`), và cả ba đã được kiểm bằng đột
biến. Ép ba tab về một khuôn là refactor trên màn tiền, phải đi riêng.

Hai cái bẫy khi viết test cho chúng: thẻ người của `by_items` **tự nó** mang
`role="button"`, nên `getByRole("button", {name})` toàn cục trúng cái THẺ chứ
không trúng cái nút — phải hỏi trong phạm vi thẻ; và harness của `by_items` phải
click bảng món để gán món, không thì mọi bill rỗng và mọi nút đều khoá.

Gated by `enabled` (no workstation paired → the button is hidden), same silent-no-op
contract as print.

## See also

- Umbrella `CLAUDE.md` — multi-app architecture, design system, codegen
- `docs/explanation/observability.md` — Sentry + audit-log umbrella guide
- `docs/reference/api-kiosk.md` — sibling kiosk API (mirrors many patterns)
- `workstation/CLAUDE.md` — security middleware ring that pos-web hits via LAN
