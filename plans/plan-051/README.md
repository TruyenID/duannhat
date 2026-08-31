---
plan: 051
title: Void policy per-status + VoidReason master + stock deduction timing
slug: void-policy-stock-timing
issue: 1149, 1150
status: shipped
branch: dev
created: 2026-07-28
updated: 2026-08-05
parent: "#1148 (item mutation law)"
landed_via: >-
  merged to dev (feature branch deleted); tracker closed. TASKS.md
  checkboxes are NOT the completion signal — plan-028 sits at 0/123 and plan-051
  at 0/15 while both shipped (#1842). Verified by: no feature branch remains,
  plus a closed tracker or the plan's subject being present in the tree.
---

# Plan 051 — Void per-status × VoidReason master × thời điểm trừ kho

MỘT plan chung cho #1149 + #1150 theo quyết định sản phẩm 2026-07-27 — vì
điểm giao của chúng chính là chỗ #1148 để mở: **void một món ĐÃ trừ kho thì
kho xử lý thế nào**. Câu trả lời hợp nhất:

> `VoidReason.stock_effect` quyết định phần bù kho, `stock_deduction_timing`
> quyết định món ĐÃ trừ hay CHƯA tại thời điểm void. Hai trục độc lập, một
> điểm nối duy nhất (`StockDeductionService::compensateVoid`).

## Ba mảnh

1. **Ma trận void per-status** (thay `allow_item_edit_any_status`):
   `shop_order_settings.item_voidable_statuses` (Json, default `["pending"]`).
   `pending` luôn ✓ cứng; `served` tick được nhưng default OFF (khuyến nghị
   đi đường refund plan-045 — ghi rõ trong hint). EDIT không bao giờ quay
   lại ma trận — #1148 pending-only là luật.
2. **VoidReason master** (brand-scoped): nhân viên CHỌN từ list thay vì gõ.
   `stock_effect ∈ waste | restock | none` + `requires_note`. Void non-pending
   vẫn bắt buộc lý do thật — giờ "thật" nghĩa là một VoidReason row (hoặc
   note khi `requires_note`).
3. **`stock_deduction_timing`** per-shop: `on_close` (default, hành vi hiện
   tại) / `on_preparing` (bếp nhận — sự thật vật lý, chuẩn Toast/MarketMan) /
   `on_add`. Điều kiện tiên quyết: trừ-theo-MÓN với marker per-line
   (idempotent, mixed-timing an toàn khi đổi setting giữa ngày).

## Bảng chân lý bù kho (điểm nối)

| Tình huống void | stock_effect | Hành động kho |
|---|---|---|
| Line CHƯA trừ (mọi timing) | bất kỳ | không gì — line voided bị skip khỏi trừ (hành vi #1148 hiện tại) |
| Line ĐÃ trừ | `restock` (bấm nhầm, khách đổi trước khi nấu) | transaction bù `adjustment_in` reference line + reason |
| Line ĐÃ trừ | `waste` (nấu rồi/đổ bỏ) | KHÔNG bù — nguyên liệu tiêu thật; ghi nhận waste theo reason để báo cáo |
| Line ĐÃ trừ | `none` (comp cho khách…) | KHÔNG bù — món vẫn được phục vụ |

→ Cảnh báo đỏ #1148 trong admin Settings đổi điều kiện: hiện khi tick bất kỳ
status ≥ preparing **VÀ** shop đang `on_close` (vì lúc đó void món đã nấu mới
gây lệch); shop `on_preparing` thì vụ lệch tự biến mất — đúng lời hứa #1150.

## Trình tự deploy

Backend trước (cột mới + fallback đọc flag cũ) → workstation (parse list,
fallback flag) → pos-web/admin-web. Giữ `allow_item_edit_any_status` MỘT
release làm nguồn backfill + fallback, xoá ở plan sau.

## Files

- [DESIGN](DESIGN.md) · `TASKS` · `TESTS`
- Issues: #1149 (ma trận + master) · #1150 (timing) · nền: #1148, plan-024
  (`inventory_mode` per-SKU), plan-045 (refundItem).
