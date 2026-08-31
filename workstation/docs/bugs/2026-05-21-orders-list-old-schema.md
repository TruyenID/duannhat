# BUG-2026-05-21-04 — Frontend Orders.tsx hiển thị `#000` + `NaN` total sau Sprint 4 schema rename

| Field | Value |
|---|---|
| **Status** | ✅ FIXED — 2026-05-21 (sau khi anh báo lại sau khi pair shop branch) |
| **Severity** | 🟡 Important — orders/payments page hiển thị sai, không crash nhưng operator không đọc được order |
| **Discovered** | 2026-05-21 (anh re-pair sang shop branch ae50, items pulled về nhưng UI vẫn `#000`, `NaN`) |
| **Class** | "Sprint 4 deferred carry-over" — schema migration phía Go + cloud đã đổi, frontend types chưa theo |
| **Files** | [frontend/src/lib/api.ts](../../frontend/src/lib/api.ts), [frontend/src/pages/Orders.tsx](../../frontend/src/pages/Orders.tsx) |

---

## Tóm tắt

Sprint 4 đã đổi schema workstation `orders` để align với cloud `customer_orders`:
`order_number → order_code`, `total → total_amount`, `customer_count → guest_count`. Backend Go (`service.Order`) và SQLite migration 007 đã đổi xong, nhưng [frontend Order interface](../../frontend/src/lib/api.ts) vẫn dùng tên cũ. Khi UI render `order.total` / `order.order_number`, JavaScript trả về `undefined` → `formatPrice(undefined)` = `NaN`, `String(undefined).padStart(3, "0")` = `"undefined".padStart(3, "0")` = `"#000"` (Vietnamese rendering vô tình giấu lỗi vì label "items"/"NaN" trông giống template chưa có data).

S4.7 trong [docs/plan/06-sprint-4-schema-alignment.md](../plan/06-sprint-4-schema-alignment.md) đã list là **deferred to Sprint 5**, nhưng vì anh đã pair shop branch và items pulled về → operator vào /orders thấy ngay, blocking demo. Promote lên P0 fix tại chỗ.

---

## Triệu chứng (anh screenshot)

- Orders list: tất cả cards hiển thị `#000` và `NaN` (total).
- Order detail (right panel): Header `Order #000`, line "Table - guests" (cả 2 field undefined), items list chỉ hiện "x1", "x2", "x3" — không có tên món, total `NaN`.
- Frontend không có error đỏ trên console — chỉ render undefined silently.

---

## Root cause

[Orders.tsx](../../frontend/src/pages/Orders.tsx) (trước fix) đọc field cũ trên object trả về từ Go:

| Frontend đọc | Backend Go trả | Hậu quả |
|--------------|----------------|---------|
| `order.order_number` | `order.order_code` ("ORD-2026-0015") | `#000` |
| `order.total` | `order.total_amount` (4980) | `NaN` |
| `order.customer_count` | `order.guest_count` | "guests" rỗng |
| `item.notes` | `item.note` | (không dùng nhưng sai field name trong TS interface) |

Migration 007 đã rename SQLite columns đồng bộ với cloud. Tests Go đã update. Riêng TypeScript types + render code không nằm trong test suite Go → không bắt được sớm.

---

## Fix (đã apply)

**Files changed:**

1. [frontend/src/lib/api.ts](../../frontend/src/lib/api.ts) — `Order`, `OrderItem`, `CreateOrderInput` aligned với `service.Order`:
   - Order: thêm `order_code`, `order_type`, `total_amount`, `guest_count`, `note`, `subtotal`, `tax_amount`, `paid_amount`, `opened_at`. Giữ `order_number?` optional (workstation-local fallback).
   - OrderItem: rename `notes` → `note` để match Go `json:"note"`.
   - CreateOrderInput: drop `table_number`/`customer_count`, thêm `table_id`, `order_type`, `guest_count`, `note` (match `service.CreateOrderInput`).

2. [frontend/src/pages/Orders.tsx](../../frontend/src/pages/Orders.tsx) — render path:
   - `order.order_number` → `order.order_code` (fallback `#000` if missing).
   - `order.total` → `order.total_amount`.
   - `order.customer_count` → `order.guest_count`.
   - Create form: `Table Number` text → `Order Type` select (dine_in/takeaway/spot) + `Table ID` input (placeholder cho table picker Sprint 5).

**Verify:**
- `pnpm exec tsc --noEmit` ✅ pass.
- Reload browser → orders hiển thị `ORD-2026-0015`, `¥4,980`, item names, `dine_in · 1 guests`.

---

## Caveat còn lại

Create-order form vẫn dùng input UUID text cho `table_id` — không có table picker. Operator phải copy UUID từ /tables (chưa có UI). **Sprint 5 carry-over**: build zone+table picker thay text input. Đây là phần thực sự của S4.7 deferred — display path đã fix xong, create path còn rough edge.

---

## Bài học

1. **Schema migrations cần test frontend rendering, không chỉ Go unit tests.** Pint/Go test suites không catch được TS field name mismatch vì 2 lớp ngôn ngữ khác nhau, không có codegen TS hôm nay.
2. **`String(undefined).padStart(3, "0")` = `"#000"` là silent fail** — JavaScript đúng kỹ thuật, nhưng ẩn bug 3 tháng nếu không ai nhìn screenshot. Cân nhắc: 
   - Strict null-check `if (!order.order_code) console.warn(...)`.
   - Hoặc generate TS types từ Go struct (eg `tygo` hoặc oapi-codegen TS output) — schemas/codegen.
3. **"Defer to Sprint 5" cần priority filter:** S4.7 deferred khi local SQLite trống → không thấy ảnh hưởng. Pair shop branch → items có data → bug nổi ngay. Lần sau khi defer "schema-coupled UI work", phải mark là **blocking** ngay khi data chảy về.
