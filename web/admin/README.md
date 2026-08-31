# web/admin — Admin Dashboard (Next.js)

Web quản trị của TempoFast cho cả cấp **HQ** (theo thương hiệu: sản phẩm, danh mục,
menu, nguyên vật liệu, công thức, thiết bị) lẫn cấp **Shop** (theo cửa hàng: tồn kho,
bàn, cấu hình, thanh toán).

Nằm trong monorepo TempoFast tại `web/admin/`. Backend Laravel nằm **cùng repo** ở
`backend/`. Luật khi sửa code: `AGENTS.md` + `CLAUDE.md` cùng thư mục, và 8 skill
trong `.claude/skills/` (thiết kế bảng, quy tắc component, port màn HQ…). Kiến trúc
toàn hệ: `CLAUDE.md` ở gốc repo.

> ⚠️ Đừng nhầm: repo `godx` ở `~/Herd/godx` **không phải backend** — đó là CLI tool
> (`@dxs-platform/godx`) cho luồng auth OAuth qua browser.

---

## 1. Stack

- **Next.js 16.2** (App Router, Turbopack) — ⚠️ phiên bản này có breaking changes so với Next 14/15, đọc `node_modules/next/dist/docs/` trước khi code.
- **React 19.2**
- **TypeScript 5**
- **TanStack Query v5** — toàn bộ data fetching client-side
- **Tailwind CSS v4** + **shadcn/ui** (dựa trên `@base-ui/react`)
- **next-themes** (dark/light), **sonner** (toast), **lucide-react** (icons)
- **i18n custom** — 3 ngôn ngữ: `vi`, `en`, `ja` (mặc định đọc từ cookie ở SSR để tránh flash)
- **Vitest + Testing Library** cho unit test

Backend mặc định khi chạy qua Docker: `http://localhost:5400`. Trên máy dev local của team đang dùng Herd domain `https://dxs-product.test` (xem `.env.local`). Override local bằng biến server-only `TEMPO_BACKEND_URL`; browser luôn gọi API same-origin.

---

## 2. Mô hình domain (vì sao lại chia 3 không gian route)

Hệ thống được tổ chức theo **3 vai trò sử dụng tách biệt**, mỗi vai trò có sidebar / quyền hạn / scope dữ liệu khác nhau:

| Không gian | URL prefix | Người dùng | Scope dữ liệu |
|---|---|---|---|
| **Headquarters** | `/hq/[brandSlug]` | Quản lý brand / chuỗi (HQ staff) | Toàn bộ một **Brand**: catalog, recipe, menu master, duyệt request từ shop |
| **Shop** | `/shop/[shopSlug]` | Nhân viên / quản lý cửa hàng | Một **Shop (branch)**: tồn kho thực tế, bàn, production, menu áp dụng |
| **Customer** | `/customer/...` *(planned)* | Khách hàng cuối (quét QR tại bàn) | Public — xem menu, đặt món, không cần đăng nhập |

Một user có thể thuộc nhiều brand và nhiều shop → sau khi login phải đi qua **`/select-context`** để chọn brand hoặc shop muốn vào.

---

## 3. Cấu trúc route

```
src/app/
├── layout.tsx              # Root: QueryProvider + AppProvider (i18n) + Toaster
├── page.tsx                # "/" → redirect /select-context
│
├── login/
│   ├── page.tsx            # Form login (set cookie `token`)
│   └── callback/           # SSO callback
│
├── select-context/
│   └── page.tsx            # Hub chọn brand / shop sau khi auth
│
├── hq/                     # 🏢 HEADQUARTERS — quản lý brand
│   └── [brandSlug]/
│       ├── layout.tsx      # Resolve brand qua /api/v1/hq/{slug} + sidebar HQ
│       ├── dashboard/
│       ├── products/       # CRUD product, có /new và /[id]
│       ├── categories/     # CRUD category, có /[id]
│       └── (planned)       # product-types, materials, recipes, menus,
│                           # shops, approvals, settings
│
├── shop/                   # 🏪 SHOP — vận hành cửa hàng
│   └── [shopSlug]/
│       ├── layout.tsx      # Resolve shop qua /api/v1/shops/{slug} + sidebar shop
│       ├── dashboard/
│       ├── menu/           # Menu áp dụng tại shop, có /[menuId]
│       ├── tables/         # Sơ đồ bàn / zone
│       └── (planned)       # stock/{levels,transactions,transfers,counts,
│                           #        disposals,alerts}, production/{batches,
│                           #        orders,calculator}, warehouses
│
├── customer/               # 👤 CUSTOMER (planned — chưa có code)
│   └── ...                 # Menu công khai, order tại bàn (QR), tracking
│
└── test/                   # Sandbox dev (public, bypass auth)
```

### Sidebar HQ (`src/app/hq/[brandSlug]/layout.tsx`)

- **Overview**: Dashboard
- **Catalog**: Products, Categories, Product Types
- **Production**: Materials, Recipes
- **Sales**: Menus, Shops
- **Workflow**: Approvals, Settings

### Sidebar Shop (`src/app/shop/[shopSlug]/layout.tsx`)

- **Overview**: Dashboard
- **Sales**: Menus
- **Stock**: Levels, Transactions, Transfers, Counts, Disposals, Alerts
- **Floor**: Tables
- **Production**: Batches, Orders, Calculator
- **Settings**: Warehouses

> Các mục chưa có page tương ứng vẫn xuất hiện trong sidebar — bấm vào sẽ 404. Đây là chủ ý: nav được khai báo trước để guide việc implement dần.

---

## 4. Auth & routing protection

Có **2 lớp** chạy song song, đừng nhầm lẫn:

### Lớp 1 — Middleware (`src/proxy.ts`)
- Chạy trên Edge cho mọi request (trừ `_next/*`, `favicon.ico`, `api/*`).
- Public paths: `/login`, `/auth/*`, `/test`.
- Đọc cookie `token` → nếu không có thì redirect `/login?redirect=<pathname>`.

### Lớp 2 — Client API layer (`src/lib/api.ts` + `src/lib/auth.ts`)
- `apiFetch()` đọc token từ cookie (source of truth) → fallback `localStorage`.
- Bất kỳ response **401** nào → gọi `handleUnauthorized()`:
  - Wipe `localStorage` + clear cookie.
  - Redirect `/login?redirect=<current url>` qua `window.location.replace` (không để lại history).
  - **Idempotent** — nhiều 401 song song chỉ gây 1 lần navigate (xem comment trong `auth.ts`).
- `logout()` (user click): cùng flow nhưng không kèm `redirect=`.

> Hai hàm này cố ý tách riêng — `onClick={logout}` vs HTTP layer `handleUnauthorized()`. Đừng gộp, sẽ bị footgun MouseEvent.

### Resolve scope (brand/shop)
- Layout của `/hq/[brandSlug]` gọi `GET /api/v1/hq/{slug}` → 404 hoặc 403 đều render `notFound()`.
- Layout của `/shop/[shopSlug]` gọi `GET /api/v1/shops/{slug}` y hệt.
- Cache `staleTime: 5 phút`, `retry: false` — endpoint O(1), điều hướng giữa các page cùng brand không re-fetch.

---

## 5. Cấu trúc thư mục `src/`

```
src/
├── app/                # Routes (App Router) — xem mục 3
├── components/
│   ├── ui/             # shadcn primitives (button, card, dialog, ...)
│   ├── layout/         # PageShell, AppSidebar, TopBar, PageHeader, LoadingShell
│   ├── shop/           # Component dùng riêng trong /shop
│   └── shared/         # Component tái sử dụng (data table, form fields, ...)
├── hooks/
│   └── api/            # React Query hooks: use-products, use-categories,
│                       # use-shop-menus, use-tables, use-zones, use-product-*
│                       # query-keys.ts là "single source of truth" cho key
├── services/           # Tầng gọi API thuần (không React) — 1 file / resource:
│                       # product-service, category-service, table-service, ...
├── lib/
│   ├── api.ts          # apiFetch + ApiError + PaginatedResponse
│   ├── auth.ts         # logout / handleUnauthorized / clearSession
│   └── utils.ts        # cn() (clsx + tailwind-merge)
├── providers/
│   ├── app-provider.tsx    # i18n + locale + theme
│   └── query-provider.tsx  # TanStack Query client
├── i18n/
│   ├── index.ts        # LOCALE_COOKIE, DEFAULT_LOCALE, isLocaleCode, t()
│   ├── vi.json / en.json / ja.json
├── types/
│   └── models/         # 1 file / entity (Brand, Shop, Product, Recipe,
│                       # MaterialBatch, StockLevel, Table, Menu, ...)
│                       # base/ + enum/ chứa primitives & enum dùng chung
├── __tests__/          # Vitest specs
└── proxy.ts            # Next middleware (auth gate) — xem mục 4
```

### Quy ước phân lớp data
1. **`services/*-service.ts`** — hàm `async` thuần, gọi `apiFetch`. Không biết React.
2. **`hooks/api/use-*.ts`** — wrap service bằng `useQuery` / `useMutation`. Dùng `queryKey` từ `query-keys.ts`.
3. **Component** — chỉ gọi hook, không gọi `apiFetch` trực tiếp.

---

## 6. i18n

- 3 ngôn ngữ: `vi`, `en`, `ja`. File JSON ở `src/i18n/`.
- Locale persist trong cookie `LOCALE_COOKIE` → root layout đọc ở SSR để first paint không bị flicker.
- Dùng qua hook `useTranslation()` từ `@/providers/app-provider`: `t("nav.dashboard")`.
- Đổi ngôn ngữ qua `useLocale().setLocale("vi")`.

---

## 7. Chạy dự án

```bash
# Cài đặt
npm install

# Dev (turbopack)
npm run dev          # http://localhost:3000

# Build / start
npm run build
npm run start

# Lint
npm run lint

# Test
npx vitest
```

### Biến môi trường

File mẫu hiện tại (`.env.local`):

```env
TEMPO_BACKEND_URL=https://dxs-product.test         # Server-only BFF destination
```

| Biến | Ví dụ | Ý nghĩa |
|---|---|---|
| `TEMPO_BACKEND_URL` | `https://dxs-product.test` *(Herd)* hoặc `http://localhost:5400` *(Docker)* | Backend Laravel mà Next proxy `/auth/*`, `/api/*`, `/broadcasting/*` tới khi chạy local/test. Production dùng Amplify custom rewrite rules. |

---

## 8. Backend & tài liệu liên quan

Từ #2306 mọi thứ dưới đây nằm **cùng một repo**, đường dẫn tính từ gốc:

| Đường dẫn | Vai trò |
|---|---|
| `backend/` | Laravel 13 / PHP 8.4 — định nghĩa `/api/v1/hq/*`, `/api/v1/shops/*`, `/api/v1/me/*`… |
| `docs/` | Tài liệu kiến trúc & nghiệp vụ, cấu trúc Diátaxis: `guide/` · `reference/` · `explanation/` · `contributing/` |
| `schemas/` | Omnify YAML — nguồn duy nhất sinh ra migration/model/type/hook |
| `plans/` | Hồ sơ quyết định của từng feature lớn (`DESIGN`/`NOTES`/`ADR`/`REVIEW`) |
| `workstation/` · `web/{customer,pos}` · `app/{tms,kiosk,kds,handy}` | Các app còn lại của hệ |

Ngoài repo: **`platform`** (`~/Herd/platform`) — DXS Platform, issuer SSO và nguồn
phân quyền duy nhất.

Herd phục vụ Laravel ở `https://dxs-product.test` qua một symlink trỏ vào
`backend/` — tên miền còn giữ tên cũ, nhưng nó là backend **trong repo này**.

### Swagger / OpenAPI

Backend dùng **`darkaonline/l5-swagger`** (`composer.json` line 23) và chia API thành **3 documentation group** riêng biệt — mỗi group có UI và spec file riêng (xem `config/l5-swagger.php`):

| Group | Mục đích | Swagger UI | OpenAPI JSON |
|---|---|---|---|
| **auth** *(default)* | Đăng nhập, SSO, `/me/*`, chọn brand/shop | `/docs/auth` | `/api/auth/documentation` |
| **hq** | Brand-scoped: `/api/v1/hq/{brandSlug}/...` (products, categories, recipes, menus, …) | `/docs/hq` | `/api/hq/documentation` |
| **shop** | Shop-scoped: `/api/v1/shops/{shopSlug}/...` (stock, tables, production, …) | `/docs/shop` | `/api/shop/documentation` |

**URL đầy đủ** (Herd local — đúng với `.env.local` của repo này):

- Auth: <https://dxs-product.test/docs/auth> · spec: <https://dxs-product.test/api/auth/documentation>
- HQ:   <https://dxs-product.test/docs/hq>   · spec: <https://dxs-product.test/api/hq/documentation>
- Shop: <https://dxs-product.test/docs/shop> · spec: <https://dxs-product.test/api/shop/documentation>

**Nếu chạy backend bằng Docker** (`docker compose up -d` ở gốc repo), thay base bằng `http://localhost:5400`:

- <http://localhost:5400/docs/auth>
- <http://localhost:5400/docs/hq>
- <http://localhost:5400/docs/shop>

> Spec file YAML cũng có sẵn (`auth-api-docs.yaml`, `hq-api-docs.yaml`, `shop-api-docs.yaml`) — generate ra `storage/api-docs/` qua `php artisan l5-swagger:generate {auth|hq|shop}` ở phía backend.

### Khi cần tra cứu

- **Một endpoint trả gì / nhận gì?** → mở Swagger UI tương ứng (auth/hq/shop) ở trên, hoặc đọc controller trong `backend/app/Http/Controllers/...`, hoặc `docs/reference/` ở gốc repo.
- **Hiểu một module nghiệp vụ (recipe, stock, production…)?** → `docs/explanation/` ở gốc repo.
- **Cách dựng backend local?** → `CLAUDE.md` ở gốc repo (Docker Compose, port 5400) hoặc dùng Herd domain `dxs-product.test`.
- **Luồng SSO / token?** → `godx/README.md` + `platform`.

---

## 9. Quy ước khi thêm route mới

**Thêm trang HQ (vd: `/hq/[brandSlug]/recipes`):**
1. Tạo `src/app/hq/[brandSlug]/recipes/page.tsx` — `"use client"`, lấy `brandSlug` từ `useParams`.
2. Tạo service ở `src/services/recipe-service.ts` (nếu chưa có).
3. Tạo hook ở `src/hooks/api/use-recipes.ts` + thêm key vào `query-keys.ts`.
4. Sidebar đã khai báo sẵn ở `layout.tsx` — chỉ cần đảm bảo `href` khớp.
5. Thêm key i18n vào `vi.json / en.json / ja.json`.

**Thêm trang Shop:** y hệt, đổi `hq` → `shop`, `brandSlug` → `shopSlug`.

**Thêm trang Customer (chưa có):** sẽ là khu vực public — cần loại khỏi auth gate ở `src/proxy.ts` (`PUBLIC_PATHS`) khi bắt tay vào làm.

---

## 10. Lưu ý quan trọng

- ⚠️ **Next 16 có breaking changes** — không copy paste pattern Next 14/15 từ training data. Đọc docs trong `node_modules/next/dist/docs/` trước.
- ⚠️ **Đừng gọi `apiFetch` trực tiếp trong component** — qua hook hoặc service.
- ⚠️ **Đừng tự handle 401** — `apiFetch` đã làm rồi (`handleUnauthorized`).
- ⚠️ **Brand/Shop slug là source of truth trong URL**, không lưu `brand_id` trong state — luôn lấy từ `useParams` rồi resolve qua layout query.
- Sidebar HQ và Shop khai báo nhiều mục **chưa có page** — đó là roadmap nhìn thấy được, không phải bug.
