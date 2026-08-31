# Plan 051 — DESIGN

## 1. Schema (Omnify — không hand migration)

### 1.1 `VoidReason` (entity mới, brand-scoped)

```yaml
# schemas/Backend/Shop/VoidReason.yaml — kind: object
organization_id: Uuid            # console org id
brand_id: Uuid                   # local brands.id (giống VnEinvoiceSetting)
label: String(100), translatable # nhãn nhân viên thấy (ja/en/vi)
stock_effect: EnumRef VoidStockEffect  # waste | restock | none
requires_note: Boolean default false   # bắt gõ thêm ghi chú tự do
is_active: Boolean default true
sort_order: Int default 0
# unique (brand_id, slug?) — KHÔNG cần slug; unique không đặt trên label
# (label translatable). Index [brand_id, is_active, sort_order].
```

Seed mặc định khi brand chưa có row nào (giao lúc GET list, không seeder):
`Bấm nhầm` (restock) · `Khách đổi món` (restock, requires_note) ·
`Nấu hỏng / đổ bỏ` (waste) · `Hết nguyên liệu` (restock) ·
`Comp cho khách` (none, requires_note). Trả kèm cờ `is_builtin_suggestion`
— chỉ là gợi ý tạo, KHÔNG phải row ảo.

### 1.2 `shop_order_settings`

- `item_voidable_statuses`: Json nullable. null = chưa cấu hình → resolve từ
  flag cũ (fallback), tránh backfill bắt buộc lúc deploy.
- `stock_deduction_timing`: EnumRef `StockDeductionTiming`
  (`on_close` default | `on_preparing` | `on_add`).

Resolve voidable set (một hàm duy nhất, cả 3 tầng cùng semantics):

```
resolveVoidableStatuses(setting):
  if setting.item_voidable_statuses != null:
      return union(setting.item_voidable_statuses, ["pending"])   # pending cứng
  return setting.allow_item_edit_any_status
      ? ["pending","preparing","ready","served"]                  # flag cũ true
      : ["pending"]
```

### 1.3 `customer_order_items` — marker per-line

- `stock_deducted_at`: Timestamp nullable.
- `stock_out_transaction_id`: Uuid nullable (transaction TRỪ của line).
- `customer_orders.stock_out_transaction_id` (per-order, hiện tại) GIỮ
  NGUYÊN cho đường on_close legacy — close() sweep chỉ trừ line chưa marker.

### 1.4 `customer_order_items.void_reason_id`: Uuid nullable — link master;
`void_reason` text hiện tại giữ (freetext note / workstation cũ / legacy).

## 2. Backend

### 2.1 Void gate (Cloud — `WritesCustomerOrders::voidItem`)

```
statuses = resolveVoidableStatuses(shopSetting)
item.status ∉ statuses           → 409 ITEM_STATUS_NOT_VOIDABLE (thay gate flag cũ)
item.status != pending:
  cần (void_reason_id hợp lệ của brand) HOẶC (reason text thật — #1148 junk 422)
  reason.requires_note && note trống → 422 VOID_NOTE_REQUIRED
```

`removeItem` (DELETE không lý do) vẫn pending-only — không đổi.

### 2.2 `StockDeductionService` (mới — tách phase 5 của OrderClosingService)

- `deductLine(item, cause)`: idempotent theo marker; gọi
  `StockTransactionService` như phase-5 hiện tại (FEFO/genealogy nguyên vẹn);
  stamp `stock_deducted_at` + `stock_out_transaction_id` trong CÙNG transaction.
- Hooks:
  - `on_add`: sau addItems persist (funnel T2.12 — sau khi line có id).
  - `on_preparing`: transition item `pending → preparing` (mọi surface đi qua
    updateItemStatus funnel; workstation sync UP dùng timestamp transition
    thật — #1091). **VÀ món SINH RA ở status ≥ preparing** (quán
    `default_order_item_status = served/preparing` — quán không-KDS): trừ
    ngay lúc tạo, vì line đó không bao giờ đi qua transition. Tổng quát:
    "đã chạm mốc" = transition QUA mốc hoặc khởi tạo TẠI/QUÁ mốc.
  - `on_close`: OrderClosingService phase 5 → sweep line chưa marker (giữ
    per-order marker cho idempotency toàn đơn như cũ).
  - Đổi qty khi pending (được phép #1148) mà line ĐÃ trừ (`on_add`) →
    delta-adjust (trừ thêm / bù bớt) trong cùng funnel Revise.
- `compensateVoid(item, voidReason)`: theo bảng chân lý README. Bù =
  `adjustment_in` reference `stock_out_transaction_id` + reason; waste =
  không bù, nhưng transaction gốc được tag `waste_reason_id` (report sau).

### 2.3 HQ VoidReason CRUD

`/hq/{brand}/void-reasons` — index/store/update/deactivate (soft). Shop đọc
qua `/shops/{slug}/void-reasons` (read-only list, active, sorted) + nhúng
vào workstation settings payload (mirror xuống LAN — pattern seller_registration).

## 3. Workstation (Go)

- Settings parse: `item_voidable_statuses` (list) + fallback
  `allow_item_edit_any_status` (giữ tương thích Cloud cũ), `stock_deduction_timing`
  chỉ passthrough hiển thị (kho trừ ở CLOUD khi sync UP — workstation không
  giữ sổ kho).
- `VoidItem` gate: dùng resolveVoidableStatuses cùng semantics
  (`ErrItemStatusNotVoidable`); nhận `void_reason_id` optional, vẫn nhận
  reason text (offline khi list chưa mirror).
- Mirror bảng void_reasons xuống SQLite (pull tick 60s) cho POS LAN.

## 4. Frontends

- **pos-web**: void dialog đổi từ input text → picker list reason (+ note khi
  requires_note); `canVoid` per-status từ settings mới.
- **admin-web**:
  - Shop Settings: checkbox matrix (pending disabled-checked; served kèm hint
    khuyến nghị refund) thay switch; radio `stock_deduction_timing` với mô tả
    trade-off; cảnh báo đỏ #1148 đổi điều kiện (matrix ≥ preparing && timing
    == on_close).
  - HQ: trang VoidReason CRUD (list + dialog, translatable label).

## 5. Edge cases chốt sẵn

- Đổi timing giữa ngày: an toàn nhờ marker per-line — line trừ rồi không trừ
  lại, line chưa trừ được sweep lúc close bất kể timing lúc thêm.
- Void trên đơn prepaid-closed: KHÔNG có (đơn closed đi đường refundItem —
  #1148 §4, không đổi).
- Xoá/deactivate reason đang được line cũ reference: soft-deactivate, không
  xoá cứng; line giữ id + text snapshot (`void_reason` text vẫn được ghi kèm
  label tại thời điểm void → lịch sử tự đủ).
- Workstation cũ + Cloud mới: gửi reason text như cũ → hợp lệ (không
  void_reason_id); stock_effect coi như `none`?? → KHÔNG: mặc định theo
  timing — line đã trừ mà không rõ reason → KHÔNG bù (an toàn hơn bù nhầm),
  log warning để ops thấy.
- `on_add` + hết hàng lot (FEFO không đủ): trừ được phần có, phần thiếu ghi
  transaction shortage như phase-5 hiện tại — không chặn thêm món (quyết
  định giữ nguyên hành vi close hiện tại, chỉ đổi THỜI ĐIỂM).
