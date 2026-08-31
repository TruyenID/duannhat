# Dine-in Print — Phiếu Hold (repo workstation-app)

> **POS ≠ Workstation.** Hệ in thật là **workstation-app** (`../godx-tempo-workstation-app`, Go 1.25 + Wails v3), **không phải** pos-web và **không phải** cái queue `print_jobs` ở backend.
> Nội dung dưới đây dựa trên đọc code thật, không phải giả định.

## Hệ in hiện tại hoạt động thế nào (đã verify)
- workstation-app **là LAN print authority**. In được kích hoạt bởi: (a) pos-web gọi thẳng `POST /api/lan/print/*` (`internal/handler/lan_print.go`, `routes.go:452-478`), hoặc (b) **auto-print** khi sync engine kéo order từ Cloud và thấy chuyển `paid` (`internal/service/sync_pull.go` → `onOrderPaid` → `internal/handler/auto_print.go`).
- **KHÔNG** poll/claim `print_jobs` của backend. Idempotency là **local**: in theo delta `order_items.printed_quantity` (`internal/handler/fire_kitchen.go:53-69`) + idempotency store cho receipt (`auto_print.go:52-68`).
- **`hold` đã có sẵn end-to-end**: role `hold_printer` (`internal/printer/manager.go:17-34`), routing group→role `roleForGroup("hold")→TypeHoldPrinter` (`internal/printer/dispatcher.go:81-94`), ô chọn trong Devices UI (`frontend/src/pages/Devices.tsx:16-21`, i18n `printer.role.hold_printer` đã có), status endpoint (`lan_print.go:804`). Và `printKitchenAndRunnerOn` (`fire_kitchen.go:217-270`) **đã in 1 slip thứ 2 ra máy in hold** ngay sau vé bếp (fallback về máy bếp nếu chưa cấu hình hold).

## Delta thật để có "phiếu hold = bản kiểm món" — ~1 file
Slip thứ 2 gửi ra máy hold hiện đang in **QR-delta bill** (`FormatDeltaQRTicket`, `fire_kitchen.go:257`). Đổi/ thêm payload thành **bản copy vé bếp**:

**Option A (khuyến nghị, 1 file):** trong `internal/handler/fire_kitchen.go` (`printKitchenAndRunnerOn`, ~:257), đổi
`service.FormatDeltaQRTicket(o, items, config)` → `service.FormatKitchenTicket(o, items, ticketNo, config)`
(`FormatKitchenTicket` là pure fn, `ticketNo`+`config` đã trong scope; đã được tái dùng ở `routes.go:714`). Routing ra máy hold + fallback máy bếp đã có sẵn (`:248-249, 259-268`). Không đụng dispatcher/config/UI.
- Lưu ý: chỗ này in theo **delta** (chỉ món vừa fire) → khớp yêu cầu "chỉ in món thêm". Nếu muốn hold copy **cả đơn** thì dùng `o.Items`/`FormatRunnerTicket` thay vì delta.
- Optional: thêm `"hold"` vào `printerGroups` ở `frontend/src/pages/Menu.tsx:16` nếu muốn tag món theo group hold (mặc định product = `"kitchen"`).

**Không cần thêm:** role `hold_printer`, config, ô Devices UI, status, routing group→role — **đã có hết**.

## Backend: cái `print_jobs` queue là THỪA → nên revert
Trong `../godx-tempo/backend` có 1 bộ print-queue mới được implement (untracked): models `PrintJob`/`OrderPrintBatch`/`Workstation`/`WorkstationPrinter`, `app/Events/PrintJob*`, `app/Services/Print/`, `config/print.php`, 6 migration `2026_07_20_2100*`, sửa `CustomerOrderController` + `routes/channels.php`.
- Nó **chưa nối** (`enqueueBatchJobs`/`enqueueReceiptJob` 0 call site) và **workstation-app không tiêu thụ** nó → là hệ song song thừa. Nối vào còn phải viết thêm consumer phía workstation, trùng với path LAN/sync đang chạy.
- **Đề xuất revert** (tất cả untracked, an toàn):
  ```bash
  cd ../godx-tempo/backend
  git clean -f app/Models/{PrintJob,OrderPrintBatch,Workstation,WorkstationPrinter}.php \
    app/Events/{PrintJobCreated,PrintJobFailed}.php config/print.php \
    database/migrations/2026_07_20_2100*.php
  rm -rf app/Services/Print/
  git checkout -- app/Http/Controllers/Api/V1/Customer/CustomerOrderController.php routes/channels.php
  # nếu đã chạy migrate: php artisan migrate:rollback (cho 6 migration đó)
  ```
- Ngoại lệ: migration `add_invoice_registration_number_to_branches` **có thể giữ lại** nếu VAT invoice của workstation cần field 登録番号; còn lại bỏ.
- Chỉ KHÔNG revert nếu team **cố ý** chuyển kiến trúc in sang cloud-queue (khi đó phải làm thêm consumer phía workstation — lớn, ngoài nhu cầu "thêm phiếu hold").

## Customer-web
Không cần gì thêm cho việc in. A1 (Idempotency-Key, backend đã honor cache-based) chống append trùng → tránh workstation in trùng qua sync. Xem [PLAN-DINE-IN-PRINT-CUSTOMER-WEB.md](PLAN-DINE-IN-PRINT-CUSTOMER-WEB.md).
