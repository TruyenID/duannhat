# Plans

Working documents for feature planning. Plans are **not** part of `docs/` — they
are ephemeral and live outside the Diataxis structure. Once a feature ships,
distil the stable knowledge into `docs/explanation/`, `docs/reference/` or
`docs/guide/`; the plan itself stops being the reference.

**102 markdown files live under this tree.** Nobody reads it end to
end, so this page is the only supported entrance: find the plan here, then open
it. Two things make that possible — every plan is listed (19 directories on
disk + the historical tables below), and shipped plans are removed from the
tree once distilled into docs (#2188 — git history keeps every file) so a
default search hits the working set only.

## Cái gì được giữ trong một thư mục plan

**`TASKS.md` và `TESTS.md` của plan đã ship KHÔNG còn trong cây** (#2336 — git
history giữ nguyên). Cả hai là hồ sơ *trước khi làm*, và cả hai đều bị chính repo
bắt quả tang nói dối: các `- [x]` không được cập nhật sau khi việc xong (xem mục
kế), còn `plan-020/REVIEW.md` ghi rằng `TESTS.md` của nó dùng enum không tồn tại.
Nguồn đúng về việc đã test gì là **suite thật** (9.400+ test), không phải một
danh sách kịch bản viết từ trước. Hai plan còn mở — `plan-050` và `plan-055` —
giữ nguyên `TASKS.md`/`TESTS.md` vì việc chưa xong.

Còn giữ: `DESIGN.md`, `NOTES.md`, `ADR.md`, `REVIEW.md`, `RISKS.md`,
`EDGE-CASES.md` — hồ sơ **quyết định và hệ quả**, thứ `docs/` trỏ ngược vào
(ví dụ `docs/reference/api-payment-gateways.md` lấy ma trận endpoint từ
`plan-047/DESIGN.md`). Đó là lý do cây này chưa xoá hẳn được.

## Trust the status here, not the one inside a plan

A plan's own `status:` field and its `- [x]` task ticks are **not maintained**
after the work lands, and the drift is not small:

- `plan-005` and `plan-018` shipped with **zero** ticked tasks;
- `plan-043` (tax types), `plan-047` (payment gateway) and `plan-048` (cutover)
  still say `implementing` while `CLAUDE.md` and `docs/guide/` describe them as
  live production behaviour;
- `plan-012` shipped with 10 tasks still unticked.

So the table below carries an **Bằng chứng** (evidence) column: what *outside* the
plan says the work is real — a doc that describes live behaviour, a table that
exists in `backend/database/migrations/omnify/`, a route comment, a merged commit.
A row whose evidence reads *plan tự khai* was checked against nothing but the
plan itself; treat it as unverified.

### Lifecycle

| Status | Meaning | Set by |
|--------|---------|--------|
| `draft` | Being scoped, not yet approved | `/mcp__omnify__plan` (initial) |
| `approved` | Reviewed by user, ready for implementation | User confirms during `/mcp__omnify__execute` |
| `implementing` | Tasks being executed on a feature branch | `/mcp__omnify__execute` |
| `shipped` | PR merged; thư mục plan bị XOÁ khỏi cây sau khi chưng cất vào docs (#2188 — git history giữ) | `/mcp__omnify__complete` + đợt dọn #2188 |
| `partial` | Phần lớn đã ship và đang chạy, nhưng còn mốc **hở vì lý do ngoài repo** (dữ liệu bên thứ ba, phần cứng, hợp đồng) | Manual edit, kèm phép đo |
| `abandoned` | Dropped; kept for history | Manual edit |

`partial` thêm vào 2026-08-06 (#1977) vì plan-050 làm lộ một khoảng trống trong
từ vựng: 13/21 task xong, backend đang chạy thật, nhưng M3 hở vì chưa có file
精算レポート của PayPay. `draft` nói sai (nó đang chạy), `shipped` cũng nói sai
(còn mốc hở) — và khi cả hai lựa chọn đều sai thì người ta chọn bừa rồi bỏ mặc,
đúng cách `status:` trôi khỏi thực tế ở nhiều plan khác.

Dùng `partial` **chỉ khi** phần hở nằm ngoài tầm repo. Còn việc code làm được thì
đó là `implementing`, không phải `partial`.

---

## Active plans (19)

Every plan directory on disk — the table and `ls plans/` must agree. Shipped
plans are deleted from the tree (see the historical tables below); a row here
means the work is still open, partially open, or the plan has not yet been
claims-checked against the code.

| # | Plan | Trạng thái | Bằng chứng | Mục tiêu |
|---|------|-----------|-----------|----------|
| 016 | [Edit cart toppings](plan-016/README.md) | `shipped` (frontmatter) | plan tự khai | Sửa topping trên một dòng giỏ khi món còn `pending`. |
| 019 | [Coupon & menu promotion](plan-019/README.md) | `shipped` (frontmatter) | plan tự khai | Hai tầng giảm giá: coupon theo mã (brand) + promotion tự áp theo giờ/thứ (shop). |
| 020 | [Split bill payment for dine-in](plan-020/README.md) | `shipped` (frontmatter) | plan tự khai | Nhiều khách trả một đơn dine-in: trả từng phần, số còn lại tính từ ledger. |
| 022 | [Material system — correctness fixes](plan-022/README.md) | `shipped` (frontmatter) | plan tự khai | 9 lỗi logic + 6 lỗ UX của hệ nguyên vật liệu (đơn vị, FEFO, hạn dùng, hoàn tác). |
| 023 | [Notification platform — completeness pass](plan-023/README.md) | `shipped` (frontmatter) | plan tự khai | Trả nợ plan-008/012: audience, broadcast định kỳ, bounce/complaint, digest. |
| 024 | [Stock management — auto-deduct & alerts](plan-024/README.md) | `shipped` (frontmatter) | plan tự khai | `inventory_mode` per SKU, trừ kho theo recipe khi đơn paid, cảnh báo tồn. |
| 025 | [Product review](plan-025/README.md) | `shipped` (frontmatter) | plan tự khai | Thay rating mock bằng review thật (thumbs up/down) sau khi khách trả tiền. |
| 043 | [Tax types — 軽減税率 / インボイス](plan-043/README.md) | **shipped (chưa archive)** | `docs/guide/tax-types.md` + `tax-types:backfill` + `CLAUDE.md` | Thuế theo tax type phạm vi brand, snapshot bất biến lên từng dòng đơn. |
| 044 | [Order ↔ till session attribution](plan-044/README.md) | **shipped (chưa archive)** | `CLAUDE.md` (plan-044 R2) + `docs/guide/cashier-shift-recovery.md` | `till_session_id` trên mọi đường tạo đơn + đối soát khoảng trống khi mở ca. |
| 045 | [Tax rounding + order_condition ledger + refund lines](plan-045/README.md) | shipped **một phần** | route `POST /items/{item}/refund` (comment plan-045) trong `backend/routes/api/pos.php` | Làm tròn thuế cấu hình được, ledger điều kiện đơn, refund N đơn vị của một dòng. |
| 046 | [Shift handover + chain-of-shifts close](plan-046/README.md) | **shipped (chưa archive)** | `CLAUDE.md` (plan-046) + `docs/guide/cashier-shift-recovery.md` | Bàn giao ca giữ chuỗi mở; kết ca cuối chuỗi tổng hợp Σ snapshot bất biến. |
| 047 | [Unified payment gateway + orchestration](plan-047/README.md) | **shipped (chưa archive)** | `docs/reference/api-payment-gateways.md` + `docs/guide/payment-go-live.md` | Một orchestrator trung lập nhà cung cấp thay hai engine thanh toán song song. |
| 048 | [Payment gateway production cutover](plan-048/README.md) | **shipped (chưa archive)** | `docs/guide/payment-topology-and-tender-model.md` (bản đồ cutover) + `CLAUDE.md` | Bật dần transport (POS cloud → customer-web → webhook → WS → PayPay), khai tử connection cũ. |
| 050 | [Gateway settlement & payout reconciliation](plan-050/README.md) | shipped **M1–M4 core** | `docs/guide/gateway-settlement.md` | Sub-ledger quán ↔ gateway: phí thật mỗi giao dịch, đối soát payout 2 chiều, aging. |
| 051 | [Void policy per-status + VoidReason + stock timing](plan-051/README.md) | shipped **một phần** | model `VoidReason` đã có; `CLAUDE.md` nói ma trận void (#1149) + timing trừ kho (#1150) **chưa làm** | Ma trận void theo trạng thái món, master lý do void, thời điểm trừ kho theo quán. |
| 052 | [Print pipeline v2](plan-052/README.md) | shipped **M1+M2** | `docs/guide/printing.md` ("plan-052 M1") | 4 transport theo máy in + ledger `print_jobs` + retry matrix + reprint authorization. |
| 053 | [Print template registry](plan-053/README.md) | shipped **M1–M5** | `docs/guide/print-templates.md` | Template phiếu in tập trung ở Cloud (brand → shop), version bất biến, sync DOWN. |
| 054 | [PayPay dynamic QR cho customer-web](plan-054/README.md) | shipped (pilot) | `docs/guide/paypay-customer-web-qr.md` + commit `feat(payment): PayPay dynamic QR for customer-web (plan-054) (#1231)` | Khách quét QR PayPay theo đơn; **refund vẫn phải làm tay trên portal PayPay**. |
| 055 | [Cưỡng chế effective payment option](plan-055/README.md) | approved · T1.1 xong | — | Kiểm policy hiện là **opt-in của client**: không gửi `gateway_option_id` thì server bỏ qua, nên phương thức shop đã tắt vẫn thanh toán được. Bật cưỡng chế phải đi sau backfill revision (dev: 4/9 branch) + rollout 3 client, nếu không là từ chối tiền thật tại quầy. Tiền đề của plan-047 T7.6. |

> **Lệch số hiệu cần người quyết:** `CLAUDE.md` mô tả "plan-031 — Currency change
> guard" trong cụm ca thu ngân, nhưng plan-031 (đã archive — xem git history) là **Takeaway
> order payment countdown**. Một trong hai chỗ sai số; trang này không tự sửa
> `CLAUDE.md`.

## Archived — shipped (27)

Thư mục `archive/` đã bị xoá khỏi cây (#2188) — git history giữ nguyên toàn bộ
file; bảng dưới là bản ghi những gì từng ship.

| # | Plan | Đã giao gì |
|---|------|-----------|
| 001 | Menu schema restructure | `menu_products` + `menu_product_skus` thay `menu_items`, thêm `product_skus.selling_price`. |
| 002 | Brand switcher | Chuyển brand trên customer-web bằng sheet, không cần API. |
| 003 | Catalog allergen + approval workflow | Truy vết dị ứng (HACCP) + workflow phê duyệt recipe/product dùng chung một trait. |
| 004 | Dine-in order, payment & transaction flow | Vòng đời đơn dine-in, `OrderPayment` split-tender, close nguyên tử kèm trừ kho + nhả bàn. |
| 005 | Customer backend API | API công khai QR → menu → order → gọi nhân viên (guard `customer` dùng model `Customer`). |
| 006 | Order create/update rework | Tạo đơn header-only mặc định `spot`, gán nhiều bàn, endpoint init + update. |
| 007 | POS order integration (happy path) | Nối pos-web vào API đơn: open → add → checkout → cash → close + void. |
| 008 | Dynamic notification platform | Inbox 2 bảng chuẩn hoá, actor/subject/recipient đa hình, HQ audit, bell + `/inbox`. |
| 009 | Customer account page (phase 1) | Trang `/account` chỉ đọc + logout. |
| 010 | Customer account self-service (phase 2+3) | Sửa profile, đổi mật khẩu, lịch sử đơn. |
| 011 | Takeaway guest contact fields | Lưu tên + số điện thoại khách mang đi, ràng buộc ở server. |
| 012 | Notification broadcast platform | Audience engine, template trong DB, đa kênh, Reverb theo brand, composer hẹn giờ. |
| 013 | Topping management | 5 schema topping, CRUD HQ, UI admin, guard menu (phase 1). |
| 014 | Time-based menu scheduling | `menu_schedules` (giờ + thứ), `getCurrentMenu()` theo lịch, tab Schedules. |
| 015 | POS topping picker + cart grouping | Chọn topping trên POS với snapshot giá (flat + free_up_to_n), nhóm dòng giỏ. |
| 017 | Material management with lot tracking | Lô nguyên vật liệu, FEFO, genealogy + trace UI, thu hồi, cảnh báo hạn (FSMA-204). |
| 018 | Material lot tracking — phase 2 | Reservation, tách lô, kho dị ứng, timeline audit, drill thu hồi, CoA, trả lại. |
| 021 | Split bill — equal + by-items | Chia đều theo số người + chia theo món/unit, `order_payments.metadata` cho reprint. |
| 026 | Branch review | Khách đánh giá quán 1–5 sao sau khi trả tiền, hiển thị điểm tổng hợp. |
| 031 | Takeaway order payment countdown | `payment_due_at` + job `CancelOverdueTakeawayOrders`, đồng hồ đếm trên customer-web. |
| 035 | Takeaway payment policy + email + phone | Trả-trước-khi-làm (brand + override quán), email checkout, validate số theo quốc gia. |
| 037 | Takeaway counter-pay confirmation + timeout | Bước xác nhận trả tại quầy + timeout do admin đặt. |
| 038 | POS bill printing + KDS realtime + debt + VAT invoice | In phiếu bếp/thanh toán mọi kiểu split, KDS realtime có replay buffer, nợ, hoá đơn VAT. |
| 039 | Share-bill counter-pay propagation | Lan trạng thái trả tại quầy từ customer-web sang kiosk. |
| 040 | Inventory domain audit hardening | ~88 lỗi kho đã xác nhận, 11 cụm A–K (FK/unique, FEFO null-expiry, bảo toàn khi tách lô…). |
| 042 | Sync divergence hardening | Sync workstation → Cloud hội tụ tất định: dead-letter, cascade, recovery, delete-guard. |
| — | fix #847 — per-org IAM role customization | Trả lại quyền sửa permission theo từng org sau một regression. |

## Đã xoá khỏi cây — đợt 2 (#2188)

Các plan này chưa từng qua `archive/` — shipped/đóng xong là xoá thẳng khỏi cây
(đợt 2 của #2188). Git history giữ nguyên toàn bộ file.

| # | Plan | Bằng chứng ship | Đã giao gì |
|---|------|-----------------|-----------|
| 027 | KDS device — kitchen display | app `godx-kds/` + `docs/guide/setup-kds-device.md` + `docs/reference/api-kds.md` | Màn hình bếp: PWA ghép nối mã 6 số, bump món realtime. |
| 028 | KDS API thickening | `docs/reference/api-kds.md` (gen-2) + `docs/explanation/api-as-boundary.md` | Làm dày API KDS (order + item lifecycle) sau phê bình "thin API". |
| 029 | Per-shop split-bill rounding | `ShopOrderSetting::split_bill_rounding` + `App\Support\RoundingMode` | Mỗi quán chọn quy tắc làm tròn khi chia bill. |
| 030 | Cashier shift — open & close | `docs/guide/cashier-shift-recovery.md` + bảng `till_sessions` | Mở/kết ca: đếm tiền đầu ca, đối soát POS ↔ tiền thật ↔ terminal. |
| 032 | Stale shift reaper | lệnh `tills:expire-stale-shifts` + `docs/guide/cashier-shift-recovery.md` | Ba cửa thoát cho ca treo: force-abandon, scheduler expire, manual-settle. |
| 033 | Split-by-item — server validation + preview | `docs/explanation/split-by-items.md` (4 mã 422 + endpoint preview) | Kiểm `item_allocations` ở server + endpoint preview dùng chung mọi mặt. |
| 034 | Dine-in shared-order session per table | bảng `table_sessions` + `CustomerOrder.tableSession` (#1216) | Một bàn = một session, nhiều điện thoại gọi vào cùng một đơn. |
| 036 | Manager till tracking | `docs/guide/manager-till-tracking.md` | Màn hình quản lý: ca đang mở, lịch sử đối soát, Z-report PDF. |
| 041 | Unified `ORD-` order code | bảng `order_code_counters` | Mã `ORD-{year}-{NNNN}` liền mạch, Cloud giữ counter, offline không trùng số. |
| 049 | Order adjustments — generalized pricing layer | **GỠ BỎ** (#2041) — removal record, không phải ship | Bảng `order_adjustments`/`order_adjustment_allocations` mô hình hoá trùng `order_conditions`, chưa client nào ghi vào. Toàn bộ engine + 2 bảng + cổng Go đã xoá; **đừng dựng lại**. Xem `docs/guide/tax-types.md`. |
| — | fix #815 — Stripe theo tiền tệ của branch | commit `fix(backend): charge Stripe in the branch's priced currency` (2026-07-15) | Charge Stripe bằng tiền tệ đã niêm yết của branch. |
| — | fix #900 — guard trạng thái đơn ở LAN | commit `fix(workstation): guard terminal-status orders in LAN lifecycle mutations` (2026-07-20) | `update()`/`addItems()` không được sửa đơn đã ở trạng thái cuối. |
| — | fix #902 — gate sellability của sản phẩm | commit `fix(catalog): gate product sellability on order + menu paths (#902)` (2026-07-20) | Chỉ bán SKU thuộc product `active`, chặn cả đường order lẫn menu. |
| — | fix #1159 — Kiosk đọc printer config từ Cloud | issue #1159 CLOSED 2026-07-28; `KioskPrinterReplicaController` + route `GET /api/v1/kiosk/printers` có trong cây | Bảng cũ ghi `draft` là **SAI** — đã ship. Kiosk device token đọc cấu hình máy in từ Cloud. |
| — | fix #1702 — menu gộp làm `sku_id` hết duy nhất | tracker closed; frontmatter `shipped` (#1842) | Sửa khoá duy nhất sau menu gộp (#1185). |
| — | fix #1715 — giỏ giữ giá khuyến mãi sau khi khung giờ đóng | tracker closed; frontmatter `shipped` (#1842) | Khách không còn thấy một giá nhưng bị tính giá khác khi promotion hết khung giờ. |
| — | refund-bugs-520-523 (không frontmatter — vô hình với mọi phép quét) | #520 + #523 CLOSED; commit `9839a1ce9` (fix #523) + bump `f5992cff4` (merge #520…) | Phân tích 3 bug refund LAN/Cloud + visual; cả hai issue đã fix từ 2026-07. |
| — | Cloud-first workstation | abandoned #1879; nội dung khảo sát chuyển vào **issue #2210** | Khảo sát 2026-07-23: Cloud làm nguồn sự thật, workstation teo thành print-agent + dự phòng. |

## Tài liệu lẻ trong cây này

- [material-system-deep-dive.md](material-system-deep-dive.md) — mổ xẻ hệ nguyên
  vật liệu; nền cho plan-017/018/022/024/040.

Đây là tài liệu **tham chiếu**, không phải plan: nó không mang trạng thái nào để
đóng, và plan-022/plan-024 còn trỏ tới nó bằng đường dẫn tương đối.

**Một plan là một THƯ MỤC.** File `plan-*.md` đặt thẳng ở đây thì vô hình với mọi
phép quét trạng thái (kể cả `tal`) trong khi vẫn trông như plan với người đọc —
`PlansDirectoryHoldsOnlyPlansTest` chặn việc đó từ #1900. Bản ghi thiết kế thuộc
về `docs/explanation/`; hai cái đã dời khỏi đây:

- [docs/explanation/pos-web-cloud-auth.md](../docs/explanation/pos-web-cloud-auth.md)
  — auth pos-web thẳng lên Cloud (**đã ship**: `backend/routes/api/pos.php`, 81 route).
- [docs/explanation/menu-set-timeout-button.md](../docs/explanation/menu-set-timeout-button.md)
  — nút Set Timeout ở màn menu (**đã ship**: `Menu::cart_timeout_minutes`).
