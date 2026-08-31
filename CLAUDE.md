# TempoFast — Monorepo Umbrella

Restaurant/shop management platform. **MONOREPO** — mọi app nằm thẳng trong cây, KHÔNG còn submodule nào (#2306, hoàn tất 2026-08-09; 7 repo con đã archive read-only). Bố cục: `backend/` · `web/{admin,customer,pos}` · `workstation/` · `app/{tms,kiosk,kds,handy}` · `schemas/` (Omnify YAML). **Gộp KHÔNG kéo history** — tra bản cũ bằng `gh api repos/godx-jp/godx-tempo-<app>/commits/<sha>`, ref lúc gộp ở `docs/reference/deploy-web-amplify.md`. **Deploy web đã nối lại** (#2393): ba workflow `{admin,customer,pos}-web-deploy.yml` nằm ở `.github/workflows/` và hai secret `AMPLIFY_AWS_*` đã có trong repo này.

## Architecture Overview

Hai topology dùng CHUNG một Cloud backend (Laravel, `/api/v1/*`):

- **Direct-to-cloud** — Admin Web (Next.js :5430) · Customer Web (Next.js :5450) ·
  TMS app (Expo) gọi thẳng Laravel qua Internet.
- **LAN qua workstation** — mỗi quán chạy MỘT Workstation (Go+Wails, :8080) tự
  quảng bá mDNS `_ws-app._tcp`; POS-web (:5440), Kiosk (Expo) và KDS (:5460) nối
  vào workstation, nó soi/nhân bản dữ liệu với Cloud (chịu được mất mạng; sync
  UP/DOWN). Máy in ESC/POS và realtime (Reverb/Pusher) treo ở tầng này.

Sơ đồ đầy đủ + vai từng app: mục "Projects & Responsibilities" ngay dưới.

## Projects & Responsibilities

### backend/ — Laravel REST API (in-tree, archived remote: godx-jp/godx-tempo-backend)
**Role**: Single cloud API gateway for all clients.
**Tech**: Laravel 13, PHP 8.4, Sanctum, Pest 4, Omnify codegen.

**Run (canonical — Docker):**
```sh
docker compose up -d                              # backend at http://localhost:5400, mysql 3307, redis, mailpit, minio
docker compose exec app php artisan migrate:fresh --seed --force   # seed inside the container
```
The `app` container runs `php artisan migrate --force --isolated` on every start, so plain migrations need no manual step. **Seeding always runs inside the container** — running `php artisan ...` natively from the host hits Herd's local MySQL (a separate database) and silently does nothing for the docker stack.

**Run (alternative — Herd):** A symlink `~/Library/Application Support/Herd/config/valet/Sites/dxs-product → backend/` serves Laravel at `https://dxs-product.test`. Use this only if you've explicitly set `NEXT_PUBLIC_API_URL=https://dxs-product.test` in `web/admin/.env.local`. Default `.env.development` points to docker on `:5400`.

**Test**: `cd backend && php -d memory_limit=-1 vendor/bin/pest --compact` (tests run natively on purpose — faster iteration; uses the test DB defined in `phpunit.xml`, separate from the dev MySQL).

**API Endpoints** (`/api/v1/`):

| Group | Prefix | Auth | Description |
|-------|--------|------|-------------|
| Pairing | `POST /devices/pair` | Public | Device code exchange → token |
| User | `/me/*` | SSO | User context, brands, shops |
| HQ Catalog | `/hq/{brand}/product-types, categories, products, skus` | SSO | Product CRUD + approval workflow |
| HQ Menu | `/hq/{brand}/menus` | SSO | Menu management + product assignment |
| HQ Materials | `/hq/{brand}/materials, recipes` | SSO | Raw materials + recipe formulas |
| HQ Devices | `/hq/{brand}/devices` | SSO | Device CRUD + pairing code generation |
| Shop Inventory | `/shops/{shop}/warehouses, stock-levels, transactions` | SSO | Inventory tracking per shop |
| Shop Devices | `/shops/{shop}/devices` | SSO | Shop-scoped device management |
| TMS | `/tms/me, zones, tables` | Device token | Table management terminal API |
| **Workstation** | `/workstation/*` | Device token | **Workstation sync API** — xem `backend/routes/api/workstation.php` (đếm hiện tại ở mục Workstation bên dưới) |

### web/admin/ — Next.js Admin Dashboard
**Role**: Web admin for both HQ (brand-level) and Shop (per-shop) management.
**Tech**: Next.js 16, React 19, TypeScript, Tailwind v4, TanStack Query v5.
**UI**: `@godxjp/ui` shared component library (Radix + Tailwind).
**Run**: `cd web/admin && pnpm dev` → http://localhost:5430
**i18n**: ja/en/vi via AppProvider + `useTranslation()`.

**Pages**: Login → Select Context → HQ/Shop Dashboard (Products, Categories, Menus, Materials, Devices, Inventory, Tables, Settings).

### app/tms/ — Table Management Terminal (Expo/React Native)
**Role**: Mobile/tablet app for restaurant floor — display zones & tables, status updates.
**Tech**: Expo 54, React Native 0.81, React 19, Expo Router, NativeWind.
**Auth**: Device pairing via `/api/v1/devices/pair` → `expo-secure-store`.
**API** (device token) — method viết rời từng route, **đừng gộp dưới một `GET`**:
`GET /api/v1/tms/{me, zones, tables}` · `POST /api/v1/tms/tables/{table}/status` ·
`DELETE /api/v1/tms/tables/{table}/call`. Nguồn: `backend/routes/api/tms.php`.
**Features**: Read-only dashboard, table status (free/occupied/call_staff/paid), auto-refresh.

### workstation/ — Workstation Desktop App (Go + Wails v3)
**Role**: Restaurant workstation — order management, printer control, LAN server, offline-first.
**Tech**: Go 1.25, Wails v3 (webview wrapper), React 19, SQLite, ESC/POS printers.
**Run**: `cd workstation && make dev` or `wails3 build && ./bin/workstation-app` → http://localhost:8080
**Auth**: Device pairing via `POST /api/v1/devices/pair` (endpoint chung, không có bản riêng cho workstation) → SQLite settings.
**UI**: Same `@godxjp/ui` + `@fontsource-variable/m-plus-2` font.
**Docs**: Swagger UI at http://localhost:8080/docs

**Features**:
- Order CRUD + state machine (open→preparing→ready→served→paid)
- ESC/POS thermal printer support (USB + TCP, Shift_JIS encoding)
- Local HTTP/WebSocket server for LAN tablets/phones
- **Serves pos-web at `/pos`** (same-origin LAN, #1169) — multi-machine shops open `http://<ws-ip>:<port>/pos` on a tablet and every LAN/print call is same-origin http (no HTTPS mixed-content wall). Bundle is `go:embed`-ed (built by `make posweb` from `../pos-web`, or the CI `pos-web-dist` artifact). Route coverage is enforced by a generated `pos-api-manifest.json` parity test. Version at `GET /api/lan/pos-bundle/version`. See `docs/guide/workstation-serves-pos-web.md`.
- mDNS discovery (`_ws-app._tcp.local.`)
- Offline-first with SQLite + sync queue
- Audit logging + load monitoring
- i18n: ja/en/vi

**Cloud API** (`/api/v1/workstation/*`) — nguồn chân lý là
`backend/routes/api/workstation.php`; **đếm tại chỗ, đừng tin số chép lại**:

```sh
grep -cE "Route::(get|post|put|patch|delete)\(" backend/routes/api/workstation.php
```

Nhóm feed pull-DOWN/sync-UP, pairing (KHÔNG nằm trong namespace này — dùng
`POST /api/v1/devices/pair` chung), các endpoint chưa bao giờ được cài đặt, và
cross-namespace pulls: `docs/reference/workstation-cloud-api.md`.

### app/kds/ — Kitchen Display System (Vite/React PWA)
**Role**: Tablet bếp — đơn realtime, staff bump món qua các chặng chế biến.
**Run**: `cd app/kds && pnpm dev` → http://localhost:5460 · repo
trước ở repo `godx-tempo-kds` (đã archive).
**Luật đầy đủ**: `app/kds/CLAUDE.md`.

### app/pos/ — POS native shell (Expo/React Native)
**Role**: Thin tablet shell — mDNS/manual workstation URL → full-screen WebView
at `http://<ws>/pos`. **Does not** embed `web/pos`; pairing and sales stay in
pos-web. Expo SDK 57. Dev client required for mDNS (`react-native-zeroconf`).
**Run**: `cd app/pos && npx expo run:ios` (or `run:android`).
**Luật đầy đủ**: `app/pos/CLAUDE.md`.

### Shared UI libraries (external VCS, not vendored here)
- **`@godxjp/ui`** — Canonical React UI components (Radix + Tailwind). Repo: `godx-jp/godx-tempo-ui`. Consumed by `web/admin` and `workstation/frontend` via `"@godxjp/ui": "github:godx-jp/godx-tempo-ui#main"` in their `package.json` — each `npm install` pulls the latest commit on main.
### app/packages/ — gói TỰ LÀM cho hai app Expo (#3002)

- **`@godxjp/ui-native`** → `app/packages/godx-tempo-ui-native` — RN UI primitives (NativeWind + Expo).
- **`@godxjp/mobile-shared`** → `app/packages/godx-tempo-mobile-shared` — apiFetch + lưu locale/device token dùng chung.

Cả hai trỏ bằng `file:` từ `app/{tms,kiosk}`. Hai repo nguồn
(`godx-jp/godx-tempo-{ui-native,mobile-shared}`) **đã archive 2026-08-17** —
đừng đi tìm chúng để sửa, và đừng dựng lại `github:` specifier.

Vì sao chúng về đây: `github:` specifier bắt CI clone repo riêng tư, mà runner
không có credential (exit **128** — mã của `git`, không phải của npm), nên
`app/tms` + `app/kiosk` đứng ngoài mọi cổng suốt từ PR #3039. Gói tự làm thì
đường đúng là đưa vào cây, không phải đi xin quyền.

⚠️ **`file:` làm npm SYMLINK gói**, nên TypeScript đi vào source của gói rồi
phân giải dep từ thư mục THẬT — nơi không có `react-native`/`clsx`. App nào
typecheck qua gói này phải bật `preserveSymlinks` trong tsconfig
(`app/kiosk/tsconfig.json` là ví dụ). `web/packages/godx-tempo-ui` không gặp
chuyện đó vì nó ship `dist/` đã build.

Trước đây `packages/ui` / `packages/ui-native` là submodule rồi bị gỡ với lý do
"VCS URL là nguồn chân lý duy nhất". Câu đó **không còn áp cho hai gói này**.

### schemas/ — Omnify YAML (Single Source of Truth)
API contracts → auto-generate: Laravel migrations/models/controllers, TypeScript types/services/hooks.
**Domains**: Device, Inventory, Menu, Product, Shop, Sso.
**Run**: `npm run omnify:gen` from umbrella root.

## Data Flow

### Order Lifecycle (workstation → cloud)
```
Customer (tablet) → workstation HTTP → SQLite → Printer → sync_queue → Cloud API
```

### Device Pairing
```
Admin creates device (web) → pairing_code (6 digit, 15 min)
Device enters code → POST /api/v1/devices/pair → device_token
Device uses token for all API calls
```
**Một endpoint duy nhất cho MỌI loại thiết bị** (tms · workstation · kiosk · kds ·
pos-web) — `backend/routes/api.php`. Biến thể theo namespace kiểu
`POST /v1/{tms|workstation}/pair` **chưa bao giờ tồn tại**; đừng đi tìm.
(Ngoại lệ duy nhất: máy in Star CloudPRNT không pair được — token CHÍNH LÀ auth,
xem `backend/routes/api/cloudprnt.php`.)

### Menu Sync (cloud → devices)
```
Admin updates menu (web) → Cloud DB
  → Workstation PULL trực tiếp: GET /api/v1/workstation/{menu, menu/handy, branch, lots}
    (điều kiện hoá bằng ETag qua /workstation/sync-manifest → 304 khi chưa đổi)
  → SQLite của workstation → LAN cho pos-web/kiosk/kds
```
**Không có change-feed.** `/sync/pull` và `/menu/changes` **chưa bao giờ được cài
đặt** ở cả hai đầu — không phải bị gỡ. Ma trận pull-DOWN thật nằm ở
`workstation/internal/service/sync_pull.go`.

## JavaScript Workspace (pnpm)

The umbrella uses **pnpm workspaces** for all web apps. Run `pnpm install` once from the repo root to install everything.

```
packages/
├── eslint-config/   @tempo/eslint-config  — shared ESLint rules (rules-only; no plugin registration)
├── prettier-config/ @tempo/prettier-config — shared Prettier base (no tailwind plugin)
└── tsconfig/        @tempo/tsconfig        — base.json + nextjs.json compiler options
```

**Root scripts (run from umbrella root):**
```sh
pnpm install         # root devDeps + packages/* ONLY — NOT the apps (see below)
pnpm install:all     # the above, then pnpm install inside each web app
pnpm lint            # lint web/admin + web/customer + web/pos
pnpm typecheck       # TypeScript check across all web apps
pnpm build           # build all web apps
```

Các web app **không phải workspace member** (di sản từ thời tách submodule) —
`pnpm-workspace.yaml` lists only `packages/*`, and each app carries its own
lockfile. So plain `pnpm install` at the root does **not** install them, and the
cross-app scripts above will fail on a fresh clone until `pnpm install:all` (or
a `pnpm install` inside each app) has run. The `lint` / `typecheck` / `build`
scripts deliberately do NOT install on every invocation — they are run often and
that would be wasteful — whereas `dev:*` does, because it is usually the first
command anyone types.

`typecheck` runs admin-web's own script and `tsc --noEmit` for customer-web and
pos-web, which have no typecheck script of their own.

**Run dev servers from umbrella root:**
```sh
pnpm dev             # all 3 web apps in parallel (concurrently, prefixed logs, Ctrl+C kills all)
pnpm dev:admin       # admin-web only       → http://localhost:5430
pnpm dev:customer    # customer-web only    → http://localhost:5450
pnpm dev:pos         # pos-web only         → http://localhost:5440
```
Backend must be running separately (Herd at `https://godx-tempo-backend.test`, or `docker compose up -d` for the containerized stack on `:5400`).

**Thêm một web app mới** (6 bước: package.json workspace:*, pnpm-workspace.yaml,
đăng ký plugin eslint TOÀN CỤC trước khi spread config chung, prettier, tsconfig)
— `docs/guide/dev-environment.md`. Bẫy cốt lõi cần nhớ: `@tempo/eslint-config` là
**rules-only**, không đăng ký plugin; app nào dùng plugin nào phải tự đăng ký
toàn cục, nếu không rule chung không tham chiếu được.

## Codegen Workflow

**`omnify:reset` — CẤM TUYỆT ĐỐI (ruling chủ dự án 2026-08-15).** Sản phẩm **đã
release**, đang chạy tiền thật ở nhiều quán. `omnify:reset` là
`omnify reset -y` — cờ `-y` tự xác nhận, không hỏi lại — nên nó dựng lại toàn
bộ chứ không sinh bổ sung. Đường DUY NHẤT là `npm run omnify:gen`
(= `omnify generate`, sinh bổ sung), xem trước bằng `omnify:diff`. Đừng gọi
việc này là "regen toàn bộ": thao tác đúng là **generate**.

Đụng `schemas/*.yaml`, tạo/sửa bảng DB, thêm cột, hay chạy `omnify:gen|diff`
⇒ **gọi skill `omnify-regen` TRƯỚC khi chạy** — toàn bộ quy trình + các lỗi
generator đã biết (trả giá bằng máu) nằm trọn ở đó; đếm tại chỗ, đừng ghim số.
Tối thiểu: `npm install`
trước (generator cũ ghi đè code đúng thành sai, im lặng); `vendor/bin/pint
--dirty` TRƯỚC khi đọc diff regen (#1314). Rào máy đã có: `omnify:check` chặn
lệch lock — cả cũ hơn lẫn mới hơn (#1267/#1495), và
`tests/Feature/Architecture/OmnifyRegenLandminesTest.php` ghim các bẫy nặng.

**Tên BẢNG và tên CLASS là hai chuyện** (#2609). `LegacyIdentifierBanTest` cấm
định danh `/legacy/i` trong `backend/app/`, mà generator lại đặt tên class theo
tên schema — nên một bảng buộc phải mang tên `legacy_*` (đo đúng thứ đang tồn
tại, không phải đặt tên theo sở thích) sẽ đẻ ra class bị arch test chặn. Cách
đi: đặt schema tên sạch rồi ghi đè `options.tableName`. Tên cột và tên bảng nằm
trong string literal nên test không quét chúng.

**Migration viết tay bị `.githooks/pre-commit` chặn** — mọi bảng mới đi qua
YAML, kể cả bảng quan sát nội bộ. Danh sách BLESSED chỉ dành cho bảng driver
của Laravel; thêm vào đó là review-block, không phải đường tắt.

**Khoá một cột sang `NOT NULL` thì phần đắt KHÔNG nằm ở schema** (#2411 revert,
#2617 làm lại). Regen sinh migration `->change()` gọn gàng và test hẹp vẫn xanh;
cái đổ là **factory, seeder, fixture và raw-insert trong test** vẫn chèn NULL.
Đo được ở #2617: test hẹp 347 xanh, full suite **771 đỏ** — và **0 lỗi nào có
frame trong `app/`**. Thứ tự bắt buộc: bắt mọi đường ghi stamp → **chạy full
suite** → mới khoá NOT NULL → full suite lần nữa. Sửa default của factory
thường dập phần lớn (ở #2617: một dòng dập 509/771), nên làm nó TRƯỚC rồi đo
lại, đừng sửa 30 file theo log cũ. Và nhớ: "production 0 NULL" không chứng minh
gì — **đo dữ liệu đang có ≠ đo mọi đường ghi**.

**Ngược lại: cột snapshot chỉ NOT NULL khi MỌI đường ghi thật sự BIẾT giá trị**
(#2618). `customer_order_items.price_source` để nullable là cố ý — hai đường ghi
(sync-UP transport, ghost line KDS) lấy giá từ payload thiết bị, Cloud không
resolve, nên đóng một giá trị enum lên đó là **bịa**, và một snapshot bịa tệ hơn
không có snapshot vì nó sẽ được tin. NULL kiểu này là **ngữ nghĩa vĩnh viễn**,
KHÔNG phải nhánh tương thích dữ liệu cũ mà #2188 cấm — đừng "sửa" nó thành NOT
NULL + default, vì default sẽ thành lời nói dối im lặng.

**Thêm cột vào schema KHÔNG làm nó tới được qua HTTP** (#2622). `$request->validate()`
**strip mọi key không có rule**, nên một cột mới đi hết đường service mà vẫn
không bao giờ nhận được giá trị từ thiết bị. Phần độc: **mọi test service-level
vẫn xanh** — chúng gọi thẳng service, không qua controller — trong khi tính năng
chết im lặng trên đường thật. Thêm cột mà thiết bị/client phải gửi lên thì phải
(1) thêm rule vào `validate()` của endpoint tương ứng, và (2) viết ít nhất một
test **đi qua endpoint**, không chỉ qua service. Gỡ rule ra mà test vẫn xanh
nghĩa là bạn chưa test đường đó.

## Docker Stack

```sh
docker compose up -d                              # backend + mysql + minio + mailpit
docker compose --profile admin-web up -d          # + Next.js
docker compose --profile tools up -d              # + phpMyAdmin
```

## Cashier shift workflow (plan-030/031/032/044R2/046)

Máy trạng thái ca thu ngân `open → closing → settled/abandoned/expired` trên
`till_sessions` (backend + pos-web + admin-web); doanh thu gắn ca qua
`order_payments.till_session_id`. Bất biến phải giữ: tối đa MỘT ca mở mỗi till;
đổi currency bị chặn khi còn ca/chain mở (409 `CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT`,
`branchHasOpenChain`); gap payments đối soát TAY lúc mở ca kế — **không có queue
nền**; handover giữ chain mở, final close chốt chain = Σ snapshot bất biến từng
ca. **Deploy backend trước workstation.** Toàn bộ:
`docs/guide/cashier-shift-recovery.md` + `plans/plan-046/`.

## Chia bill — chia TIỀN, không chia ĐƠN (#2856, #2860)

Ruling chủ dự án 2026-08-15: **chia bill chỉ là chia HÌNH THỨC THANH TOÁN, cấm
tuyệt đối đổi order.** Đơn được chia vẫn là MỘT `customer_order`, cùng dòng món,
cùng `total_amount`, cùng thuế; chỉ `order_payments` nhân lên.

**Một từ vựng, ba chế độ, mọi app**: `even` · `by_items` · `by_amount`
(`OrderSplitMode`). Trước #2860 có **bảy** cách viết cho ba khái niệm — hai
`validate()` gõ tay hai tập giao nhau đúng MỘT giá trị, sống nhiều tháng, không
gì đỏ. Luật `in:` phải sinh từ enum; rào `SplitModeVocabularyIsSingleTest` bắt
được validator thứ ba ngay lượt chạy đầu.

**Hai cột `customer_orders.split_mode`/`split_people_count` là TRÌNH BÀY.**
`POST /customer/orders/{id}/split-mode` công khai có chủ đích (không
`auth:customer` — thêm vào là hỏng luồng guest counter-pay), nên khách đặt được
chúng. Một vế `$order->split_mode === null` từng đứng trong rào tiền "walk-in
phải trả đủ" ⇒ khách gọi một lần là thoát rào (#2856). **Giá trị khách đặt được
không gác luật tiền** — tín hiệu đúng là `order_payments.metadata.split_mode`,
theo từng giao dịch, do POS gửi.

**`split_mode` nằm TRONG chữ ký đơn offline.** Digest phải dựng từ chuỗi NGUYÊN
VĂN thiết bị gửi (`OrderSelectionPayload::$splitModeWire`), không phải bản đã
chuẩn hoá — nếu không, mọi đơn bán offline của máy chưa cập nhật bị từ chối.

Tên cũ (`equal`·`by_people`·`split_even`·`custom`) chỉ sống ở `WIRE_ALIASES`,
chiều VÀO. Đây KHÔNG phải nhánh #2188 cấm — đó là lệch phiên bản fleet trên
thiết bị đã phát hành (workstation 2 máy Windows không tự cập nhật; kiosk
native). Gỡ khi `devices:fleet-versions --require-min` xanh cho **cả** `--type=workstation`
**và** `--type=kiosk` (#2865 — kiosk cũng phát tên cũ), không phải theo hẹn.

Toàn bộ: `docs/guide/split-bill.md`.

## Máy thu tiền 釣銭機 — sổ quan sát + đối soát BA CHÂN (cụm #2876)

Cloud có **ba sổ** cho máy 釣銭機: lượt thu (`cash_device_transactions` — **kể
cả lượt HỎNG**, vì `order_payments` chỉ có hàng khi thu ĐƯỢC tiền, nên `timeout`
= máy đang giữ tiền của khách trước đây không để lại dấu vết nào), 在高 tại ranh
ca, và sự cố có dấu thời gian. Cả ba **chỉ nhận ghi từ sync-UP máy trạm** —
aggregate `cash_device` cưỡng chế đúng hai biên giới ghi.

Chốt ca nay đủ **ba chân**: sổ ↔ **MÁY** ↔ người đếm. Giá trị KHÔNG phải "phát
hiện lệch" mà là **PHÂN LOẠI lệch** — hai chân cho một con số, ba chân cho hai
con số và đọc chéo ra bốn ô (người đếm sai · **tiền ra khỏi máy ngoài sổ** ·
thiếu thật · không kết luận).

Bốn luật đắt nhất, đừng "sửa" chúng:

- **Máy là NHÂN CHỨNG, không phải QUAN TOÀ.** `CashDrawerReconciliationService`
  chỉ ĐỌC; nó cố ý KHÔNG nằm trong `boundaries` của aggregate. Máy vẫn đếm sai
  được (tiền kẹt khe, tiền giả bị giữ) — cho nó ghi đè người là biến sai số phần
  cứng thành sự thật kế toán.
- **Máy tự khai được là nó KHÔNG CHẮC** (`CashErrorStatus.Cash` = 在高不確定).
  Tính lệch trên mệnh giá đó là bịa số rồi bắt quán đi tìm tiền không mất. Ca đó
  ⇒ `undetermined`, và báo cáo phải NÓI RÕ đã loại mệnh giá nào.
- **Máy im lúc chốt ca thì quán VẪN chốt được** — mất đối soát tốt hơn mất khả
  năng đóng cửa.
- **Nhiều máy ⇒ KÊU, không đoán.** `cashChangerDeviceID()` tra "máy CỦA QUÁN",
  không phải "máy vừa chạy lượt này", nên khoá phiên theo nó vẫn là đoán. ≥2 máy
  ⇒ alert `cash_device_ambiguous`, không quy máy, bán hàng vẫn chạy.

Từ vựng: type registry là **`coin_changer`** (không phải `cash_changer`);
`machine_outcome` (từ vựng MÁY) ≠ `outcome` (từ vựng PHỤC HỒI của máy trạm) —
ánh xạ lossy hai chiều, gộp là bẫy #2860.

Ngưỡng lệch `brand_order_policies.cash_variance_tolerance_minor` theo BRAND, mặc
định 100, **chưa có màn hình cấu hình**. Ngưỡng 0 được TÔN TRỌNG (= báo mọi
lệch); cổng trả `null` cho "chưa cấu hình" chứ không trả 0.

Tra cứu giao dịch toàn kênh: `GET /hq/{brand}/transactions` — **nghĩa vụ pháp
lý** (電子帳簿保存法 検索要件), một ô `reference` tra sáu loại mã. Phạm vi là
org + brand, **không** có phân quyền theo chi nhánh (#2911 — khẳng định, không
phải thiếu sót).

Toàn bộ: `docs/guide/cash-device-observation.md` · tầng adapter:
`docs/guide/cash-changer-glory-adapter.md`.

## Business time vs display time (#1091)

Shop chạy ở VN (UTC+7) và JP (UTC+9) trên MỘT backend UTC — "hôm nay" không toàn
cục. **Business time** (business date, ranh ca, cửa sổ menu/khuyến mãi, hạn dùng,
báo cáo theo ngày) LUÔN là `branches.timezone`, qua
`BusinessClock::forBranch($branchId)`; display time chỉ sống ở tầng trình bày.
**Cấm:** `APP_TIMEZONE=Asia/Tokyo`; lưu wall-clock string vào cột datetime; đồng
hồ DB cho business day (`CURDATE`/`CURRENT_DATE`/`whereDate(..., now())`); đọc
`SetTimezone::ATTRIBUTE` trong business logic. Go: dùng `businessDayRangeUTC()`,
đừng so date-string local với timestamp UTC.
`tests/Feature/Timezone/BusinessTimeArchitectureTest.php` cưỡng chế; test thời
gian phải freeze clock + assert ≥3 timezone (`composer test:timezones`).
Toàn bộ: `docs/guide/business-time.md`.

## Offline-order evidence (#1092 epic — #1093…#1097 + #1114)

Workstation bán offline ký từng đơn (Ed25519, `device_signing_keys`); Cloud
verify trước khi tin tiền — **thiết bị không bao giờ tự khai giá**: Cloud
re-price từ snapshot bất biến `catalog_revisions`. Signed bytes là danh sách
field có delimiter, KHÔNG phải canonical JSON; hai repo gate trên cùng golden
fixture (`backend/tests/Fixtures/offline_signing_golden.json` ↔ bản Go). Chỉ
`OfflineOrderEvidenceVerifier` được seal `TrustedOrderSnapshot` (fail-closed qua
`config/domain_mutation.php`). **Deploy backend trước workstation.** Toàn bộ:
`docs/guide/offline-order-evidence.md`.

## Consumption tax — tax types (plan-043, redesigned by #1099)

Tax type theo brand, **một type = MỘT rate** (標準 10 · 軽減 8 · 非課税 0); ngữ
cảnh 店内/持ち帰り là chuyện của MENU (menu takeaway mang override REDUCED) nên
`TaxResolver` không có tham số order-type — **đổi loại đơn không bao giờ
re-price**. Resolution: `MenuProduct → MenuMenuSection (pivot) → Menu → Product →
branch → brand`; workstation KHÔNG tự walk tier — feed đã collapse vào
`menu_items.tax_type_id`. Rate + amount snapshot bất biến lên từng order line;
làm tròn MỘT lần mỗi rate group (half-up), phân bổ largest remainder.
`customer_order_items.tax_rate` là **NOT NULL** (#2411) và mọi đường ghi đóng dấu
**tại lượt INSERT**, không nhờ một bước re-resolve sau đó — thêm đường ghi dòng
đơn thì gọi `WritesCustomerOrders::bornLineTaxSnapshot()`, đừng để dòng ra đời
trống. Toàn bộ: `docs/guide/tax-types.md` · ops VN:
`docs/guide/thue-tieu-thu-van-hanh.md` · `plans/plan-043/`.

## Invoices, item mutations & async money (2026-07-27/28 wave)

- **赤伝/hoá đơn đỏ — CHỈ IN, KHÔNG lưu DB** (#1123 dựng, #1779 gỡ toàn bộ đường
  ghi — **đừng dựng lại**): pos-web `red-invoice-dialog.tsx` →
  `POST /api/lan/print/red-invoice`. Bốn bảng hoá đơn CỐ Ý giữ lại (chứng từ
  pháp định; `InvoiceTablesAreNotDroppedTest` chặn drop). Xem
  `docs/guide/printing.md` + `docs/guide/tax-types.md`.
- **登録番号 T+13 (#1152)**: `SellerRegistrationResolver` (branch ?? brand) →
  feed `/workstation/branch` → in trên slip; KHÔNG cảnh báo khi thiếu số
  (免税事業者 hợp pháp). Xem `docs/guide/print-templates.md` +
  `docs/guide/tax-types.md`.
- **Item mutation (#1148)**: sửa qty/note/topping = pending-only TUYỆT ĐỐI;
  **SKU immutable** (đổi variant = void-có-lý-do + thêm mới, mọi tầng 409). Xem
  `docs/guide/item-edit-and-void-policy.md`.
- **Async payments (#1125)**: Konbini/銀行振込 flag-gated OFF, lifecycle LUÔN
  armed. Xem `docs/guide/async-payment-methods.md`.
- **Stripe Terminal (#1088)**: fail-closed chờ certification. Xem
  `docs/guide/stripe-terminal-card-present.md`.
- **Ledger ADR (#1151)**: sub-ledger theo domain vĩnh viễn, GL chỉ khi có nhu
  cầu kế toán thật. Xem `docs/explanation/money-ledger-architecture.md`.

## Backend Tests — native, NOT docker

```sh
cd backend
[ -f .env ] || (cp .env.example .env && php artisan key:generate)
php -d memory_limit=-1 vendor/bin/pest --compact
```

**Chạy song song: `pest --parallel` — ~105 GIÂY, không phải 13 phút** (#2778).
`paratest` nằm sẵn trong `vendor/`; CI đã bật cờ này ở job `pest` và có rào
`npm run test:parallel-gate` giữ nó. Đo trên máy 10 nhân: tuần tự 752–860s →
song song **105s**, 9697 test cùng xanh.

Hai thứ từng chặn nó, ghi lại để đừng dựng lại: một `use DateTimeImmutable;`
thừa trong file **không khai namespace** (cảnh báo PHP thành fatal khi song
song), và một helper `statusValue()` khai trong file test này mà file test khác
gọi — chạy tuần tự thì cùng process nên vô hình, song song thì chết. Helper dùng
chung sống ở `tests/Pest.php`, không sống trong một file test.

⚠️ `flake-hunt` (nightly) **cố ý KHÔNG** song song: nó chạy `--order-by=random`
để tìm test phụ thuộc thứ tự (#2753 bắt được một cái), mà song song chia thứ tự
cho nhiều process nên phép đo mất nghĩa.

**Chạy TUẦN TỰ thì mất ~12–14 PHÚT, không phải vài chục giây — đừng giết nó vì
tưởng treo.** Đo 2026-08-07 trên `dev` (M-series, không song song): `Duration: 751.82s`,
wall-clock 12:32, **9271 test / 42980 assertion**; #2029 đo 806.79s cùng cây.
Nó chạy im lặng hàng phút giữa các dấu chấm — bình thường. Vòng lặp thường ngày
thì trỏ pest vào một thư mục hẹp; chỉ chạy toàn bộ khi cần cổng merge
(`fullSuite`). ⚠️ Con số `17.29s → 17.05s` ở mục coverage ngay dưới đo **overhead
của pcov trên một lát cắt hẹp**, KHÔNG phải thời gian chạy cả suite — đọc nhầm nó
thành "suite chạy 17s" chính là cái làm người ta giết tiến trình giữa chừng.

**Coverage: `composer test:coverage`** (nhận đường dẫn, ví dụ
`composer run test:coverage -- tests/Feature/Print`). Driver là **pcov**, mặc
định `pcov.enabled=0` nên không tốn gì cho các lượt chạy thường. Ba quyết định
cấu hình không hiển nhiên (ini của Herd, `.so` copy khỏi Cellar, lỗi `pcre2.h`):
`docs/guide/dev-environment.md`.

## LEGACY KHÔNG TỒN TẠI — phạm vi sau khi ĐÃ RELEASE (#2188 · #2872)

**Chủ dự án xác nhận 2026-08-15: sản phẩm ĐÃ RELEASE.** Câu mở đầu của #2188
("chưa release ⇒ không có tương thích ngược") vì thế **hết đúng**, và nó không
phải câu trang trí — nó là lý lẽ nền cho những gì ruling cho phép.

**Phạm vi chốt lại (#2872 phương án 2): giữ VẾ CẤM, bỏ VẾ CHO PHÉP.**

| | trạng thái |
|---|---|
| **Cấm viết MỚI** nhánh `?? fallback` cho dữ liệu cũ, lazy re-stamp, alias tên cũ, thiết kế quanh NULL-snapshot | **GIỮ NGUYÊN, không đổi một chữ** |
| Xoá dữ liệu · khoá cột sang **NOT NULL** · gỡ một đường đọc dữ liệu cũ · xoá lệnh backfill sau một lần chạy | **PHẢI XIN PHÉP TỪNG LẦN** — không còn suy ra được từ ruling |
| Xoá NGAY docs outdated | chỉ khi docs mô tả thứ **không còn chạy ở quán nào**; bản đang chạy ngoài hiện trường vẫn cần mô tả của nó |

Vì sao (2) chứ không phải "giữ nguyên" hay "thu hẹp còn vùng chưa chạm
production": thứ #2188 sinh ra để chống là **code mục ruỗng vì nhánh tương
thích**, và vế cấm làm đúng việc đó — nó không phụ thuộc chút nào vào chuyện đã
release hay chưa. Còn vế cho phép thì phụ thuộc hoàn toàn: "dữ liệu cũ" khi chưa
release là seed tái tạo được, sau release là **tiền thật của quán**. Giữ vế cấm
mà bỏ vế cho phép là cách duy nhất giữ được lợi ích mà không giữ luôn cái quyền
phá huỷ dựa trên một tiền đề đã sai.

Không chọn "thu hẹp còn vùng chưa chạm production" vì nó đòi mỗi người tự phán
đoán ranh giới ấy trước mỗi lần sửa, mà ranh giới đó chính là thứ khó thấy nhất
— #2777 đã cho thấy một seeder tưởng là danh mục hệ thống hoá ra ghi đè lựa chọn
của quán.

**Ba loại KHÔNG phải legacy, đừng xoá nhầm**: snapshot bất biến (tax trên order
line, `settlement_snapshot`…), removal records ("ĐÃ GỠ #1779"), và **lớp chuẩn
hoá ở BIÊN VÀO cho thiết bị chưa cập nhật** — thứ cuối là #2860: workstation
chạy trên máy Windows không tự cập nhật và kiosk là app native, nên "tên cũ đến
từ thiết bị" là **lệch phiên bản fleet**, không phải dữ liệu cũ. Nó được phép
tồn tại, có điều kiện gỡ là một PHÉP ĐO (`devices:fleet-versions --require-min`),
không phải một cái hẹn.

Nội dung gốc của #2188 giữ nguyên bên dưới, đọc kèm bảng trên.

**Cấm viết mới**: nhánh `?? fallback`
cho dữ liệu cũ, lazy re-stamp, lệnh backfill, alias tên cũ, thiết kế quanh
NULL-snapshot — dữ liệu cũ reseed/backfill LẦN CUỐI rồi xoá lệnh, cột snapshot
chuyển NOT NULL. Docs outdated cũng XOÁ ngay (git history giữ đủ). Đừng xoá nhầm:
**snapshot bất biến** (tax trên order line, `settlement_snapshot`…) và **removal
records** ("ĐÃ GỠ #1779") là thiết kế, không phải legacy. Kho ứng viên + rào arch
test: #2188.

**Xoá một lệnh thì phải dọn chỗ TRỎ tới nó** (#2215). Sau khi 17 lệnh `Backfill*`
biến mất, 25 dòng ở 16 file vẫn bảo ops chạy chúng — gồm cả thông điệp khắc phục
của báo động thuế 0% trong `TaxResolver`. Rào:
`tests/Feature/Architecture/ArtisanCommandReferencesExistTest.php` đối chiếu mọi
neo `artisan <lệnh>` / `run <lệnh>` (backend/app · tests · docs/ · scripts/ ·
schemas/) với `Artisan::all()`. `plans/` cố ý không quét — hồ sơ thiết kế ở thì
quá khứ. Nhắc tên trong backtick không có neo = removal record, KHÔNG bị chặn.

## Issue status — claim before you touch it

Several agent sessions work this repo at once. Twice already two sessions built
the same fix in parallel (#1201 console.log cleanup, #1196 migration renumber),
and once a session's uncommitted work was swept into another's commit. The issue
tracker is the only shared surface, so **state lives there, not in your head**.

**Có cơ chế tự động cho việc này — dùng nó, đừng claim bằng tay.**
`.claude/tools/agent-loop/tal` giành issue bằng lease NGUYÊN TỬ (compare-and-swap
trên `refs/tempo/leases/*`, không phải label), dựng worktree + branch `issue-<số>`,
mở PR vào `dev`, rồi bàn giao cho một session khác review. Label một mình không
loại trừ được hai session — và nó rò: repo này từng có 4 issue dính
`status:executing` từ các session đã chết. Vào việc qua skill `issue-work` (vai
code) và `issue-review` (vai review); vận
hành và lý do thiết kế ở `docs/guide/agent-issue-loop.md` (**cơ chế**: lease, máy
trạng thái, cổng merge) và `docs/guide/agent-loop-skills.md` (**vận hành**: hai vai,
luật nào được máy cưỡng chế và luật nào chỉ là chữ, cạm bẫy đã trả giá, runbook).

**Branch/worktree chỉ được đặt `issue-<số>`**, ở umbrella và mọi submodule, cùng
một con số. Cần thêm nhánh thì mở sub-issue rồi dùng số của nó. Hook `PreToolUse`
chặn mọi tên khác.

Before starting an issue:

```sh
gh issue edit <n> --add-label "status:executing"
gh issue comment <n> --body "**ĐANG SỬA** — <what you are about to change>."
git log --oneline -5          # in EVERY repo you are about to touch
git status --short            # someone else's WIP is not yours to commit
```

When you stop, whatever the outcome:

| Outcome | Label | Comment must say |
|---|---|---|
| Shipped | `status:shipped` (or close) | **ĐÃ SỬA** + the commit SHAs, per repo |
| Waiting on a human | `status:blocked` | **CHỜ** + exactly whose decision, and what unblocks it |
| Waiting on other code | `status:blocked` | **CHẶN BỞI** + the issue/API that must land first |
| Investigated, nothing to do | remove `status:executing` | **ĐÃ KIỂM, KHÔNG CÓ LỖI** + how you verified |

Rules that cost us real time when broken:

- **Stage explicit paths, never `git add <dir>`.** A parallel session's edits sit
  in the same worktree.
- **Read the issue's comments first.** Much of this backlog was written before
  the work landed; several "open" items were already done and would have been
  rebuilt from scratch.
- A negative result is a result — say "checked, clean, here is how" rather than
  leaving the thread silent for the next session to re-investigate.

## Monorepo — không còn nghi thức submodule

Mọi app sửa + commit THẲNG vào repo này; không còn pointer, không PR con, không
`git submodule update`. Thay đổi cắt ngang (schema → regen backend → regen client)
gói trong MỘT commit:

```sh
# sửa schemas/Backend/Foo/Bar.yaml
npm run omnify:gen
git add schemas backend web/admin app/tms app/kiosk workstation
git commit -m "feat: add Bar schema + regen"
```

Skill `issue-submodule` **đã gỡ** (#2348). Mục `sub_prs` của `tal` còn trong mã
nhưng không bao giờ kích hoạt — đừng đi tìm submodule để áp nó.

## Design System

All apps share the **Japanese enterprise design system** (SmartHR convergent):
- **Font**: M PLUS 2 — **tự host** qua `@fontsource/*` (#2699). KHÔNG dùng
  `next/font/google`: nó tải font lúc BUILD và đã làm chết một lần deploy
  production khi runner không với tới Google. Rào: `npm run test:fonts`.
  (customer-web dùng Be Vietnam Pro + Alumni Sans theo spec #2050 — lệch với
  dòng này, chưa ai chốt là cố ý hay trôi.)
- **Colors**: OKLCH, 渋み (shibumi) — chroma ≤ 0.18 for primaries
- **Density**: 32px default control height, 16px card padding
- **i18n**: ja (default) / en / vi
- **Theme**: Light mode default, dark mode supported
- **Components**: `@godxjp/ui` package (Radix + Tailwind v4)

## Deploy production GHI VÀO DB — nhiều chỗ hơn `grep db:seed` thấy (#2463)

**Mỗi lần push vào `main` là một lần ghi vào database thật**, không người trông,
vào bất cứ giờ nào ai đó merge — kể cả giữa giờ phục vụ.
`.github/workflows/deploy-xserver.yml` chạy `migrate --force` cùng nhiều lệnh
`db:seed`, cộng các khối ghi khác — tinker, và INSERT thẳng vào bảng
`migrations`. **Đừng ghim số vào câu này hay vào heading**: bản trước ghim
"bảy chỗ" rồi #2777 thêm một hàng, và con số thành sai ngay trong chính mục
có án lệ "bảng đọc lên thì sai còn tệ hơn không có bảng". Đếm tại chỗ.

| Ghi gì | DB |
|---|---|
| `db:seed BetoyaProductionSeeder` | Platform (console) |
| provisioner SSO: `Service::update`, `ServiceInstance`, `ServiceProvisioning`, `ServiceUserAccess` — **tinker** | Platform |
| `deploy:reconcile-omnify-migrations` → **INSERT thẳng vào bảng `migrations`** (đánh dấu migration omnify là đã chạy khi bảng đã tồn tại) | tempo |
| `migrate --force` | tempo |
| `db:seed BetoyaSeeder` | tempo |
| `db:seed` danh mục hệ thống — template · audience · rule thông báo, denomination, tender **category** (#2777; tender **type** CỐ Ý đứng ngoài — org sửa được) | tempo |
| `service:sync-authz-manifest` | Platform |
| `ServiceUserAccess::updateOrCreate` cho MỌI user của org — **tinker** | Platform |

**Đừng ghim số dòng vào bảng này.** Bản trước có ghim, và nó lệch ngay lượt sửa
kế tiếp — một bảng đọc lên thì sai còn tệ hơn không có bảng.

### PHP inline trong YAML: phía Tempo đã hết, phía Platform thì chưa

Năm khối `artisan tinker --execute` phía **Tempo** nay là artisan command thật —
lint được, review được như mã thường, **có test** (`tests/Feature/Deploy/`):

| Lệnh | Thay cho |
|---|---|
| `deploy:reconcile-omnify-migrations` | INSERT vào `migrations` (`--dry-run` để xem trước) |
| `deploy:verify-uploads-disk` | rào `UPLOADS_DISK` rỗng (#2184) |
| `deploy:verify-production-seed` | rào bất biến ảnh chụp Betoya |
| `deploy:export-authz-manifest` | xuất `config/authz.php` + **từ chối catalog rỗng** |

Cái cuối đổi cả THỨ TỰ, không chỉ chỗ ở: kiểm "catalog rỗng" trước đây chạy
**sau** bước sync, tức chỉ báo động khi manifest rỗng đã vào Platform và mọi
user sắp bị tước quyền ở lần đăng nhập kế. Giờ manifest rỗng không rời khỏi
Tempo.

**Ba khối tinker còn lại chạy trong app Platform (`~/apps/id`) — repo KHÁC.**
Lệnh cho chúng phải sinh ra ở repo đó; không gỡ được từ repo này. Chúng còn
nguyên vì lẽ đó, không phải vì bị bỏ sót.

**Đừng thêm PHP inline mới vào workflow deploy** — viết một lệnh rồi gọi nó.

Các bước `verify-*` chỉ ĐỌC. Đó là rào dữ liệu, đừng gỡ.

### Luật: deploy được seed DANH MỤC HỆ THỐNG, KHÔNG được quyết hộ quán

Danh mục hệ thống — payment methods, gateway catalog, authz manifest — quán
không sửa được và thiếu thì POS không chạy. Seed chúng mỗi lần deploy là đúng.

Thứ **quán sở hữu** thì không: menu nào bật, `shop_order_settings`, bàn/khu nào
tồn tại, giá đã bao gồm thuế hay chưa. Từ lúc quán chạm vào được, deploy tái áp
một giá trị là **âm thầm cướp mất lựa chọn của họ**.

**Ngày 2026-08-11 việc này tốn hai lần dịch vụ thật.** `HongoShopConfigSeeder`
(gọi qua `BetoyaSeeder`) tắt bốn menu khung giờ của 本郷店 lúc `06:08:01` và lại
lúc `07:35:06`; quán phục vụ ~50 phút với menu sai rồi sửa tay, deploy sau lại
đè lần nữa. Cú suýt còn tệ hơn: `CatalogSnapshotSeeder` upsert `tables` với
`status='free'`, `current_order_id=null` — deploy đó rơi vào 15:08 JST, giữa hai
ca, nên không ai thấy; **giữa giờ phục vụ nó trả mọi bàn có khách về trống và
cắt đứt khỏi đơn khách đang ngồi**.

Rào: `BetoyaSeederLeavesShopOwnedStateAloneTest` khoá CÂY GỌI của `BetoyaSeeder`
(không phải hành vi — một seeder có thể hoàn toàn đúng mà vẫn sai chỗ khi chạy ở
đây), nằm ở `tests/Feature/Architecture/` vì đó là thư mục duy nhất `arch-gate`
chạy trên MỌI PR vào `dev`.

### Danh sách `isProduction()` của `DatabaseSeeder` KHÔNG phải đường deploy (#2777)

Đường deploy **không bao giờ gọi `DatabaseSeeder`** — nó gọi đích danh từng
`--class=`. Nên khối `if (app()->isProduction())` đọc như "đây là thứ production
chạy" trong khi nó chỉ chạy khi có người gõ `db:seed` bằng tay. Không ai gõ.

Lỗ đó đã **nuốt trọn một bản vá**: #2451 thêm `SystemNotificationRuleSeeder` vào
danh sách để chữa "0 luật thông báo trên production", kèm cả comment ghi phép đo.
Nhiều lần deploy sau, production vẫn **0 rule**. Bản vá trông như đã ship — và
tin rằng nó đã ship mới là phần nguy hiểm.

Thêm một seeder danh mục hệ thống thì phải nối **cả hai chỗ**: danh sách
`isProduction()` *và* một dòng `db:seed --class=` trong
`.github/workflows/deploy-xserver.yml`. Rào:
`SystemCatalogSeedersReachDeployTest` — nó tính **đóng bao truyền ngôi** từ các
entry point của workflow (nên `PaymentMethodSeeder` qua `BetoyaSeeder` vẫn tính
là tới được), và đứng ngoài có chủ đích thì khai vào `DELIBERATELY_OFF_DEPLOY`
kèm lý do đo được.

Hai mục miễn trừ **vĩnh viễn**, cùng một lý do — chúng ghi đè thứ NGƯỜI DÙNG
đặt:

- `BaselineProvisioningSeeder` → `BranchBaselineProvisioner` ghi `ShopOrderSetting`.
- `TillTenderTypeSeeder` → `$service->update()` với `'is_active' => true`, mà
  `till_tender_types` là từ vựng **tổ chức sửa được** (HQ
  `settings/payments/tenders` PATCH `is_active`, và đường xoá của controller còn
  khuyên dùng đúng nút đó). Org tắt một tender ⇒ deploy bật lại.

Chạy tay khi cần, có người nhìn.

Rào đi **cả hai chiều**: `isProduction ⊆ deploy` (đừng để bản vá nằm trong danh
sách không ai đọc) **và** `deploy ⊆ được-phép` (đừng để một dòng seed mới chạm
state quán sở hữu). Chiều thứ hai thiếu ở bản đầu của #2777, và thiếu im lặng —
thêm `HongoShopConfigSeeder` vào workflow mà 324 arch test vẫn xanh.

### Trước khi thêm bất cứ gì vào đường deploy

1. Nó ghi vào bảng nào? Nếu bảng đó có màn hình trong admin-web/ws-app cho quán
   sửa ⇒ **không được** nằm ở đây.
2. Seeder là bản sửa MỘT LẦN hay bất biến cần tái áp mãi? Bản sửa một lần thì
   chạy tay: `php artisan db:seed --class=<Seeder> --force`.
3. Nó có an toàn khi chạy 14:30 một ngày thứ Bảy đông khách không? Nếu câu trả
   lời phụ thuộc vào giờ deploy thì nó chưa an toàn.
4. `CatalogSnapshotSeeder` là công cụ **restore**, không phải bước deploy. Nó chỉ
   chạy khi catalog RỖNG (`seedCatalogOnFreshInstallOnly`). Đừng gỡ guard đó.

### Điều tra một nghi ngờ "deploy đổi dữ liệu"

`menus.updated_at` **không** đổi khi seeder dùng query builder thô
(`DB::table(...)->update([...])` không tự set `updated_at`). Dấu vết thật của
`HongoShopConfigSeeder` nằm ở `menu_schedules.updated_at`. Nhìn nhầm bảng là kết
luận nhầm "deploy vô can" — đã xảy ra.

Phân biệt seeder với người sửa tay bằng **số hàng mỗi giây**: seeder ghi hàng
trăm hàng trong một hoặc hai dấu thời gian; người bấm admin-web để lại 1–9 hàng
rải rác từng giây.

## Vai người dùng — Platform cấp, và slug sai KHÔNG báo lỗi

**Vai là bản sao dẫn xuất từ Platform, không phải dữ liệu của Tempo.**
`UserProvisioner::syncRoleScopes()` **xoá sạch** `role_user_pivots` của user rồi
dựng lại từ payload SSO mỗi lần đăng nhập. Gán vai thẳng vào DB Tempo chỉ sống
tới lần đăng nhập kế — đã thử trên production, 17 dòng bốc hơi (#2460). Cần ai
đó có vai X thì làm ở **Platform**.

Platform cấp **một** `service_role` mỗi user mỗi tổ chức (`admin` · `owner` ·
`manager` · `member`) kèm phạm vi chi nhánh: hoặc `all_branches_access`, hoặc một
danh sách. Không có cách nói "shop-manager ở quán A, staff ở quán B" — và
**không có API/MCP nào gán vai theo chi nhánh** (REST `PUT …/members/{user}/roles`
chỉ org scope; hàng `console_branch_id` chỉ seeder ghi).

Ba luật phải nhớ khi viết bất cứ thứ gì hỏi theo vai:

1. **Từ vựng thật là `RoleTemplateMatrix::ROLES`, toàn gạch ngang** — `org-admin`
   · `org-manager` · `shop-manager` · `staff` · `shop-staff`. `withRole()` so
   `roles.slug` **chính xác**; slug sai **không ném lỗi**, nó phân giải ra 0
   người nhận và im lặng mãi mãi. Đã xảy ra bốn lần: `shop_manager` (#2451),
   `brand_admin` · `branch_admin` · `org_owner` (#2456).
2. **User SSO mang slug `tempo-*`**, không phải slug template — khai triển bằng
   `RoleTemplateMatrix::equivalentSlugs()`. Trên production **không ai** giữ slug
   ma trận; tất cả giữ `tempo-admin`.
3. **`branch_id IS NULL` nghĩa là MỌI chi nhánh** (`all_branches_access`), không
   phải "không chi nhánh nào" — ruling đã ghi ở
   `docs/explanation/branch-isolation.md` từ trước, và tầng thông báo từng vi
   phạm nó (#2460).

Thứ bậc vai **không** tự bắc sang chuyện gửi thông báo: policy coi org-admin là
shop-manager, nhưng áp máy móc thì `byRole('shop-staff')` sẽ dội cho cả admin.
Ai cần được báo là quyết định của **từng sự kiện** (#2450: đơn hàng =
`shop-manager` + `org-admin`).

Rào: `AudienceRoleSlugsExistTest` · `AllBranchesAccessIsVisibleToRoleQueriesTest`.
Chi tiết: `docs/contributing/emitting-notifications.md`.

## IP người gọi — `trustProxies('*')` KHÔNG tin cả chuỗi

`trustProxies(at: '*')` nghe như "tin mọi proxy" nhưng Laravel rẽ sang
`setTrustedProxies([REMOTE_ADDR])` — **chỉ peer trực tiếp**. Symfony duyệt
`X-Forwarded-For` từ phải sang, bỏ cái đã tin, trả về cái kế bên; sau một tầng
CDN đó là **IP edge**, đổi mỗi request. Đo trên production (#2453): XFF tới nơi
ĐẦY ĐỦ với IP người gọi ở đầu chuỗi, và `$request->ip()` vẫn trả phần tử thứ ba.

Danh sách proxy tin cậy nằm ở `backend/config/trustedproxy.php` (dải Cloudflare +
loopback/dải riêng). `bootstrap/app.php` **cố ý không gọi**
`$middleware->trustProxies(at: …)`: closure đó chạy trước khi config nạp nên
`config()` ở đó ném `BindingResolutionException`.

**Cấm `['0.0.0.0/0','::/0']`** — tin tất cả thì Symfony lấy phần tử TRÁI NHẤT, mà
phần tử đó do client tự khai, nên IP allowlist thành thứ ai cũng giả mạo được.
Với PayPay OPA (không ký payload) IP **là** phép xác thực duy nhất.

`tempo.godx.jp` chạy **hai CDN**: `/` qua CloudFront (Next.js), `/api/*` qua
Cloudflare (Laravel). Webhook đăng ký ở `tempo-prod.godx.jp` (một tầng
Cloudflare). Đổi sang domain công khai thì phải thêm dải CloudFront **trước**.

Rào: `TrustedProxiesTest`.

## Local config vs production — hard boundary

Local-only settings live in files that are **never committed** (`.env`,
`.env.local`, `.env.local-server`, `*.override.yml`). Anything committed must be
safe to run in production: a dangerous flag ships as `${FLAG:-false}`, and a
dev-only code path is fenced by `NODE_ENV`/`APP_ENV` so the build **removes** it
rather than trusting a runtime flag.

**Forbidden:** literal `"true"` for a bypass/debug flag in a committed file;
softening a `NODE_ENV`/`APP_ENV` guard into a flag production could set (e.g. a
`NEXT_PUBLIC_*_BYPASS` env — this exact downgrade shipped three times in one PR
and was reverted); committing a file that pins one machine's host/URL/credential
(a `public/__config.json` carrying a trycloudflare URL was served publicly);
mixing WIP into an unrelated commit (an orphan `dev/test-login` route once killed
every HTTP request **and** artisan command on `dev`); **commit ảnh chụp
production nguyên bản làm fixture seeder** (#2220 — 11 cặp token `id.godx.jp`
còn sống + 287 `qr_token` + PII khách vào git; revert KHÔNG thu hồi được, xoay
khoá là việc TAY ở IdP). Chụp xong phải chạy
`php database/seeders/fixtures/orders/_scrub_orders.php`;
`SeederFixturesCarryNoProductionSecretsTest` cưỡng chế.

The SSO dev bypass needs three things at once — `SSO_DEV_BYPASS=true`,
`APP_ENV ∈ [local, testing]`, and a non-empty `SSO_DEV_BYPASS_SUBJECTS`; the
middleware then **provisions the user if absent and skips token verification**.
Production is fenced by the deploy workflow forcing `SSO_DEV_BYPASS=false`.

Full reference — every flag, port, env file and the pre-commit checklist:
`docs/guide/local-config.md`.

## CLAUDE.md Hierarchy

```
CLAUDE.md (this file) — umbrella context, always loaded
├── backend/CLAUDE.md + backend/AGENTS.md — Laravel rules (in-tree)
├── web/admin/AGENTS.md — Next.js rules
├── web/customer/AGENTS.md — Next.js rules
├── web/pos/CLAUDE.md — React/Vite POS rules
├── app/tms/CLAUDE.md — Expo/RN rules
├── workstation/CLAUDE.md — Go + Wails rules
├── app/kiosk/CLAUDE.md — Expo/RN kiosk rules
├── app/pos/CLAUDE.md — Expo/RN POS WebView shell
├── app/kds/CLAUDE.md — Vite/React PWA rules (plan-027)
└── app/handy/ — Expo/RN PDA app
```

Files **concatenate** (not override). Gốc repo là chỗ làm mặc định cho việc cắt
ngang (schema → regen backend → regen client). Làm nặng ở một app thì
`cd <app> && claude` để context gọn hơn — file luật của app đó nạp kèm.
