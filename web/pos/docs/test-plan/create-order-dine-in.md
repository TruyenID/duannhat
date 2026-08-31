# Test Plan — Tạo Order Tại Bàn (pos-web)

**Phạm vi**: Luồng tạo order dine-in trên [pos-web](../../) từ khi mở dialog đến khi order sẵn sàng add món.
**Module test chính**: [src/app/pos/](../../src/app/pos/)
**Framework**: Vitest 4 + jsdom + React Testing Library + MSW.
**Người lập**: QA / DEV pos-web · Ngày: 2026-05-25.

---

## 1. Mục tiêu

- Đảm bảo nhân viên POS tạo được order tại bàn đúng business rules (table chuyển trạng thái, customer được link, zone được nhớ).
- Bảo vệ các edge case đã ghi nhận: race chọn cùng bàn, phone lookup fail, dialog reopen với zone đã chọn lần trước.
- Cover regression cho fix idempotency `(customer_order_id, idempotency_key)` (commit aba0ba63) ở layer pos-web khi gọi payment sau create.

Không nằm trong phạm vi: backend unit (đã có Pest ở [backend/tests](../../../../backend/tests/)), workstation, app/tms.

---

## 2. Tham chiếu

| Thành phần | File |
|---|---|
| Trang POS | [src/app/pos/page.tsx](../../src/app/pos/page.tsx) |
| Dialog tạo order | [src/app/pos/components/create-order-dialog.tsx](../../src/app/pos/components/create-order-dialog.tsx) |
| Table picker | [src/app/pos/components/table-picker.tsx](../../src/app/pos/components/table-picker.tsx) |
| Hooks order | [src/hooks/api/use-orders.ts](../../src/hooks/api/use-orders.ts) |
| Service order | [src/services/order-service.ts](../../src/services/order-service.ts) |
| Service customer | [src/services/customer-service.ts](../../src/services/customer-service.ts) |
| Integration test mẫu | [src/__tests__/integration/order-payment.test.ts](../../src/__tests__/integration/order-payment.test.ts) |
| Backend endpoint | `POST /api/v1/shops/{shopSlug}/orders` |

### Hợp đồng request `POST /orders`
```json
{
  "order_type": "dine_in" | "takeaway" | omitted (spot),
  "customer_id": "uuid?",
  "table_ids": ["uuid", ...],
  "guest_count": 1+,
  "note": "string?"
}
```
Response 201 `{ data: CustomerOrder }`. Order trả về có `status = "open"`, link table đầu tiên (table.current_order_id = order.id, table.status = "occupied").

---

## 3. Môi trường & Tiền điều kiện

- `pnpm install` ở umbrella root.
- Backend chạy (`docker compose up -d`) với seed data có: 1 brand, 1 shop slug `sjk`, ≥ 2 zones, ≥ 5 tables free.
- Đăng nhập SSO; AuthGuard ([src/App.tsx](../../src/App.tsx)) cho qua.
- Env `VITE_SHOP_SLUG=sjk` hoặc URL `/shop/sjk`.
- Browser localStorage clean (xoá key `pos:last-zone:v1:sjk`) trước mỗi case có dấu ★.

---

## 4. Test Strategy

| Layer | Tool | Phạm vi |
|---|---|---|
| Unit hook | Vitest + React Query test wrapper | `useCreateOrder` payload, invalidation keys |
| Component | Vitest + RTL + MSW | `CreateOrderDialog` form rules, render order type, phone lookup |
| Integration | Vitest + MSW (như [order-payment.test.ts](../../src/__tests__/integration/order-payment.test.ts)) | Full flow PosPage → dialog → POST orders → tab mới |
| E2E Manual | Browser thật chạy `pnpm dev:pos` + backend docker | Acceptance + UX |

Mức ưu tiên: **P0** chặn release · **P1** sửa trong sprint · **P2** ghi log.

---

## 5. Test Cases

### TC-001 — Mở dialog, default state ★  ·  P0
**Tiền điều kiện**: localStorage chưa có `pos:last-zone:v1:*`.
**Bước**: Vào [/shop/sjk](../../src/App.tsx) → click nút "+ Tạo order".
**Kỳ vọng**:
- Dialog mở với `orderType = "spot"` được active (3 button Spot/Dine in/Takeaway hiển thị, Spot highlighted).
- TablePicker hiện tab "Tất cả" + danh sách zone (nếu shop có ≥ 2 zone).
- `guestCount` rỗng, `phone` rỗng, `note` rỗng, `tableIds` rỗng.
- Nút "Tạo" disable khi chưa hợp lệ (tuỳ rule client) hoặc enabled (xác nhận state hiện tại với code; hiện tại không block submit nếu thiếu table).

---

### TC-002 — Tạo order dine-in 1 bàn, có guest count, có note  ·  P0
**Bước**:
1. Click "Dine in".
2. Chọn 1 bàn free ở zone "Tầng 1".
3. Nhập `guestCount = 4`, `note = "Không cay"`.
4. Submit.
**Kỳ vọng**:
- POST `/api/v1/shops/sjk/orders` với body `{ order_type: "dine_in", table_ids: ["<uuid>"], guest_count: 4, note: "Không cay" }` (không có `customer_id`).
- Dialog đóng.
- PosPage tạo tab mới (`createTab(o.id, o.order_code)` [page.tsx:311](../../src/app/pos/page.tsx#L311)) và active tab đó.
- Tables list refetch; bàn vừa chọn chuyển sang "occupied" (disabled trong picker khi mở lại).
- localStorage `pos:last-zone:v1:sjk` = zoneId của bàn vừa chọn ([create-order-dialog.tsx:165-170](../../src/app/pos/components/create-order-dialog.tsx#L165-L170)).

---

### TC-003 — Tạo order multi-table (gộp bàn lúc khởi tạo)  ·  P1
**Bước**: Dine-in → chọn 3 bàn free khác zone → submit.
**Kỳ vọng**:
- `table_ids` array đủ 3 uuid, đúng thứ tự chọn (Set giữ insertion order khi đọc qua `Array.from`).
- Backend link bàn đầu tiên là primary; các bàn còn lại là merged.
- Order detail hiển thị 3 chip table.

---

### TC-004 — Spot order (không bàn)  ·  P1
**Bước**: Spot → không chọn bàn → submit.
**Kỳ vọng**:
- POST body KHÔNG có `order_type` (do `orderType === "spot"` thì omit field, [create-order-dialog.tsx:138](../../src/app/pos/components/create-order-dialog.tsx#L138)).
- `table_ids` không gửi (hoặc gửi mảng rỗng — verify match code).
- Order tạo thành công với type spot.
- KHÔNG ghi localStorage zone (vì không có table).

---

### TC-005 — Takeaway có phone, customer tồn tại  ·  P0
**Bước**: Takeaway → phone `0901234567` (đã tồn tại trong DB) → submit.
**Kỳ vọng**:
- Gọi `customerService.findOrCreateByPhone("sjk", "0901234567")` TRƯỚC khi POST order ([create-order-dialog.tsx:151-158](../../src/app/pos/components/create-order-dialog.tsx#L151-L158)).
- Response trả customer cũ → `body.customer_id = <existingId>`.
- POST `/orders` có `order_type: "takeaway"` + `customer_id`.

---

### TC-006 — Phone mới → tạo customer  ·  P1
**Bước**: Takeaway → phone `0999999999` (chưa có) → submit.
**Kỳ vọng**:
- `findOrCreateByPhone` tạo customer mới, trả id.
- Order tạo thành công với customer_id mới.

---

### TC-007 — `findOrCreateByPhone` fail → order KHÔNG được tạo  ·  P0
**Bước**: Mock MSW trả 500 cho endpoint customer find-or-create → submit dine-in + phone.
**Kỳ vọng**:
- KHÔNG có request POST `/orders` sau đó.
- Error banner hiển thị (đọc từ `e.body?.message ?? e.message`).
- Dialog vẫn mở để staff sửa hoặc bỏ phone.
- Tables KHÔNG bị chiếm.

---

### TC-008 — Guest count < 1 hoặc không phải integer  ·  P1
**Bước**: Nhập `guestCount = 0`, hoặc `-1`, hoặc `2.5` → submit.
**Kỳ vọng**:
- Validation client báo lỗi inline ([create-order-dialog.tsx:288-296](../../src/app/pos/components/create-order-dialog.tsx#L288-L296)).
- KHÔNG gửi request.

---

### TC-009 — TablePicker không cho chọn bàn occupied / cleaning / out-of-service  ·  P0
**Bước**: Mở dialog, scroll picker.
**Kỳ vọng**:
- Bàn `status !== "free"` render dạng disabled, click không toggle ([table-picker.tsx:138-154](../../src/app/pos/components/table-picker.tsx#L138-L154)).
- Status pill hiển thị đúng label (Đang phục vụ / Đang dọn / Ngưng).

---

### TC-010 — Last-used zone restore  ·  P1
**Tiền điều kiện**: localStorage `pos:last-zone:v1:sjk = "<zone-id-tầng-2>"`.
**Bước**: Mở dialog.
**Kỳ vọng**:
- Tab "Tầng 2" active sẵn ([create-order-dialog.tsx:101-104](../../src/app/pos/components/create-order-dialog.tsx#L101-L104)).
- Nếu zone không còn tồn tại → fallback "Tất cả" ([table-picker.tsx:89-95](../../src/app/pos/components/table-picker.tsx#L89-L95)).

---

### TC-011 — Cancel dialog không tạo order  ·  P2
**Bước**: Điền form rồi đóng dialog (ESC / nút X / click overlay).
**Kỳ vọng**: Không POST. State dialog reset khi mở lại.

---

### TC-012 — Backend trả 422 (validation fail)  ·  P1
**Bước**: Mock MSW trả 422 `{ message: "Bàn đã có order khác", errors: { table_ids: [...] } }`.
**Kỳ vọng**:
- Banner hiển thị message từ server.
- Dialog không đóng. Tables refetch để cập nhật trạng thái mới.
- KHÔNG tạo tab mới ở PosPage.

---

### TC-013 — Backend trả 401 (token hết hạn)  ·  P1
**Bước**: Mock 401.
**Kỳ vọng**:
- AuthGuard / interceptor redirect login (verify behaviour hiện có ở [src/lib/api.ts](../../src/lib/api.ts)).
- Form state preserved hoặc clear (xác nhận expectation).

---

### TC-014 — Race condition: 2 client cùng chọn 1 bàn  ·  P1
**Setup**: 2 tab POS cùng shop, cùng table free.
**Bước**: Cả 2 cùng submit gần như đồng thời.
**Kỳ vọng**:
- 1 client thành công → order created, table.occupied.
- Client còn lại nhận 422 (nếu backend lock) hoặc thấy bàn flipped occupied sau refetch (graceful UX expected; nếu hiện tại backend không lock → log P2 issue, đề xuất ticket).

---

### TC-015 — Network timeout khi POST  ·  P2
**Bước**: Mock MSW delay 30s rồi 504.
**Kỳ vọng**:
- Nút submit hiện loading state, disable.
- Sau timeout: banner lỗi, retry-able. KHÔNG double POST.

---

### TC-016 — i18n: VI / EN / JA  ·  P2
**Bước**: Chuyển locale qua AppProvider switcher → mở dialog.
**Kỳ vọng**: Mọi label dialog dịch đúng theo `i18n/{vi,en,ja}.json`. Không có key raw lộ ra.

---

### TC-017 — Sau create, menu unlock đúng order vừa tạo  ·  P0
**Bước**: Tạo order thành công (TC-002).
**Kỳ vọng**:
- MenuCatalog enable (disable condition `!activeOrder || activeOrder.status !== "open"` [page.tsx:1017](../../src/app/pos/page.tsx#L1017) thoả).
- Click món bất kỳ → variant popover mở → add item POST `/orders/{id}/items` thành công → order detail refetch (optimistic via setQueryData).

---

### TC-018 — Idempotency hook (regression aba0ba63) — payment sau create  ·  P0
Scope rộng hơn create-only nhưng phải verify do là chain ngay sau create.
**Bước**: Sau TC-002 → add 1 món → checkout → pay cash. Lặp lại request payment với cùng `idempotency_key` (test bằng MSW interceptor + manual retry).
**Kỳ vọng**:
- 2 request → 1 payment record, response giống nhau (per-order scope của unique key).
- Order chuyển closed sau khi `paid_amount >= total_amount`.

---

## 6. Acceptance Criteria

- 100% TC P0 PASS trước khi merge `fix/pos-web` lên `main`.
- TC P1 PASS ≥ 90%; bug còn lại có ticket.
- Coverage `src/app/pos/components/create-order-dialog.tsx` ≥ 80% statements/branches (vitest --coverage).
- Không regression cho [src/__tests__/integration/order-payment.test.ts](../../src/__tests__/integration/order-payment.test.ts).

---

## 7. Deliverable

| Output | Path đề xuất |
|---|---|
| Unit/component tests | `src/app/pos/components/create-order-dialog.test.tsx` |
| Integration test mở rộng | `src/__tests__/integration/create-order-flow.test.ts` |
| MSW handlers | `src/test/handlers/orders.ts`, `src/test/handlers/customers.ts` (nếu chưa có) |
| Báo cáo run | `pnpm --filter pos-web test:coverage` output đính kèm PR |

---

## 8. Rủi ro & Ghi chú

- Race condition (TC-014) phụ thuộc backend; nếu chưa có row-lock cần ticket riêng cho team backend trước khi đóng test.
- localStorage zone key đổi schema (`v1`) — nếu nâng `v2` phải migrate hoặc clear → bổ sung TC.
- AuthGuard chưa được mock dễ dàng trong test environment; cần helper `renderWithAuth()` trong `src/test/render.tsx`.
