<!--
Xoá phần nào không áp dụng. Đừng xoá dòng "Module sở hữu" — xem lý do ở
docs/explanation/module-boundaries.md § "Mỗi PR khai module sở hữu".
-->

## Module sở hữu

<!--
Chọn MỘT: Payments · Catalog · Inventory · Ordering · PlatformIntegration ·
Notifications · CustomerEngagement · Organization · Pricing
Hoặc: `none` (chỉ tài liệu / CI / tooling) · `cross-cutting` (giải thích bên dưới).
Sổ sở hữu: backend/config/modules.php
-->

Module:

Nếu PR chạm nhiều hơn một module, nói rõ **hướng phụ thuộc** (module nào gọi
module nào) và vì sao không tách được thành hai PR. Phụ thuộc ngược chiều hoặc
tạo chu trình là điểm chặn ở review — ADR 0001.

## Sửa gì

## Vì sao

## Test đã chạy + kết quả thật

<!-- Lệnh cụ thể và output thật, không phải "đã chạy test rồi". -->

## Cố ý KHÔNG làm

## PR submodule kèm theo + thứ tự merge

<!-- Bỏ mục này nếu không chạm submodule nào. Repo con push TRƯỚC, bump pointer SAU. -->
