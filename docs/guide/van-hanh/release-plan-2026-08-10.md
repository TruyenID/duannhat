# Release Plan — 10/08/2026

- **Ngày phát hành**: 10/08/2026

## Thời gian thực hiện (10/08/2026)

| Mốc                | Giờ Nhật (JST, UTC+9) | Giờ Việt Nam (ICT, UTC+7) |
| ------------------ | --------------------- | ------------------------- |
| Bắt đầu triển khai | **16:00**             | **14:00**                 |

## Phạm vi

| Hệ thống         | Nội dung                                                                     |
| ---------------- | ---------------------------------------------------------------------------- |
| **POS**          | Mở/đóng ca, bán hàng & thanh toán, in hoá đơn/phiếu, chuyển chế độ LAN/Cloud |
| **Admin-web**    | Quản lý thiết bị, món ăn/menu/bàn, cài đặt quán & ca, giám sát ca            |
| **Customer-web** | Web khách hàng: gọi món bằng QR, thanh toán                                  |
| **Workstation**  | Sync UP/DOWN với Cloud, dựng máy in, quản lý đơn & in lại phiếu              |
| **Thiết bị**     | Máy in (ESC/POS/StarPRNT), Glory (máy đổi tiền / 釣銭機)                     |

## Chi tiết từng hạng mục

### POS

- **Đăng nhập / ghép nối**: mã 6 ký tự từ Admin, thời hạn 15 phút; sau 3 lần lỗi auth liên
  tiếp tự đăng xuất về màn hình ghép nối.
- **Chuyển chế độ kết nối** qua badge góc phải: **LAN** (🟢), **Cloud auto** (🟡),
  **Cloud** (🔵), **Disconnected** (🔴). Khuyến nghị chế độ **Automatic (LAN → Cloud)**
  để vẫn bán khi workstation mất kết nối (nhưng lưu ý: chế độ Cloud **không in được**).
- **Mở ca** (`/shop/{store}/shift/open`): đếm tiền theo mệnh giá (notes + coins),
  nhập "Opened by", đối soát **gap payments** giữa ca trước khi mở ca mới (cash để
  riêng — không tính gộp vào float). In phiếu mở ca tự động (best-effort, lỗi máy in
  không chặn mở ca).
- **Bán hàng & thanh toán**: đơn bàn, thanh toán cash / thẻ / PayPay / ví; ghi nợ; in
  hoá đơn VAT, hoá đơn đỏ (red invoice — **CHỈ IN, không lưu DB**).
- **Đóng ca** (`/shop/{store}/shift/close`): in phiếu tổng kết ca chia theo khối (chi
  tiết thuế theo mức rate, theo payment method, service charge, đếm tiền, mệnh giá);
  mỗi toggle khối lưu ngay và áp dụng cho **mọi máy POS trong tiệm**. Toggle "chi tiết
  thuế" **chỉ quản lý đăng nhập mới đổi được**.
- **Cài đặt POS**: chỉ có 1 trang — các toggle in phiếu đóng ca. Máy in, đổi tên thiết
  bị, đơn vị tiền/tax nằm ở Workstation / Admin (xem bảng "POS không có").

### Admin-web

- **Thiết bị**: tạo thiết bị (tms / workstation / kiosk / kds / pos-web), sinh mã ghép
  nối (mã 6 ký tự, 15 phút, コードを再発行 để cấp lại), revoke thiết bị → POS/workstation
  sẽ tự đăng xuất và cần mã mới.
- **Quản lý món/menu/bàn**: quản lý món, nhóm món, menu (cửa sổ thời gian, takeaway),
  bàn & trạng thái bàn (đặt/đang dùng/vệ sinh…). Tạo/sửa/xoá bàn yêu cầu **Shop Manager**
  (vi phạm trả 403).
- **Quản lý ca (Till/Shift)**: Chỉ **Shop Manager trở lên** vào được khu giám sát ca
  (URL của Staff/Shop Staff trả 403). Xem ca đang mở, **settle bằng tay** ca hết hạn do
  inactivity, chốt chain, đối soát gap payments ở lần mở ca kế.
- **Cài đặt quán**: đơn vị tiền, tax types (標準/軽減/非課税), giờ mở cửa theo timezone
  quán (business time), mệnh giá tiền (`金種`), cấu hình máy Glory, đăng ký số 登録番号
  (T+13).
- **Báo cáo**: báo cáo doanh thu theo ngày (theo business time của quán).

### Customer-web (dine + takeaway)

- **Gọi món bằng QR** trên bàn → đơn gửi về workstation → auto-in kitchen ticket + hold
  slip nếu bật `Auto-print kitchen tickets (dine-in)`.
- **Thanh toán online** (PayPay / thẻ qua QR).
- Nút **"Call staff"** hiện **đang bị tắt** bằng cờ — nếu tiệm cần, báo IT bật.

### Workstation

- **Cài đặt máy**: WS App (macOS/Windows/Linux), `~/.ws-app/` chứa config + SQLite +
  backup (mỗi 6h, giữ 7 bản). Xác lập `cloud_api_url`, port (mặc định 8080).
- **Ghép nối**: mã từ Admin, loại device **`workstation`** (mã của loại khác → báo lỗi);
  giới hạn 5 lần/phút. Sau khi ghép tự tải menu → zones → tables → 30 ngày lịch sử đơn.
- **Sync** (tự động, không có nút "sync now"): push order/payment/shift/status lên 5s,
  pull menu/branch/slow-data xuống 5s, kiểm tra mạng 10s (backoff tối đa 5 phút). Theo
  dõi qua banner đỏ (`n operations could not reach the server`, có bao nhiêu là tiền →
  cần đối soát) hoặc `/sync-recovery`.
- **Sync Recovery** (`/sync-recovery`): xử lý operation kẹt — **Recreate in Cloud / Retry /
  Discard**. ⚠️ Dòng có badge đỏ **Payment** (dính tiền) **không được Discard**, báo IT.
- **Đơn & in lại**: màn Orders chỉ để **in lại** (Kitchen / Hold / Receipt); không dùng để
  thao tác trạng thái (vocabulary cũ). Công việc hằng ngày làm trên POS.
- **Báo cáo**: Reports (Total Orders, Paid Orders, Revenue, Avg Order Value, Popular
  Items — top 10). Không có nút in/xuất (dùng POS/Admin cho chi tiết hơn).

### Thiết bị

- **Máy in** (Workstation → Devices, KHÔNG nằm ở Admin/POS):
  - Kết nối **Network (TCP)** `IP:port` (vd `192.168.1.100:9100`) hoặc **USB**
    (`/dev/usb/lp0`, `COM3`…). Paper width 80mm/58mm.
  - **3 vai trò**: Kitchen (厨房) · Hold / runner (ホールド) · Receipt
    (レシート — gồm phiếu mở ca, phiếu đóng ca, hoá đơn VAT, hoá đơn đỏ). Một máy có thể
    nhận nhiều vai (tiệm 1 máy duy nhất → gán cả 4).
  - Địa chỉ hợp lệ bị giới hạn (private IP / `.local`; không nhận IP public, đường dẫn
    chứa `..` bị loại). Port 1–65535.
  - Test in → `=== TEST PRINT === Printer OK!` + **cắt giấy**. Kiểm tra: nguồn, giấy,
    cùng subnet, IP, port 9100.
  - Banner `未割当の役割` báo vai trò chưa có máy; hệ thống tự fallback
    (receipt → kitchen → …). Không có máy phù hợp → POS báo "No printer configured".
  - Kỹ thuật: mã hoá **Shift_JIS** (tiếng Việt bị bỏ dấu), protocol **StarPRNT** (không
    phải Epson ESC/POS cho máy Star), lệnh cắt theo capability profile (#1950);
    **không bật Kanji mode** (treo Star mC-Print3). 58mm=32 ký tự/dòng, 80mm=48 ký tự.
  - Danh sách phiếu: kitchen ticket, hold slip, provisional bill, payment receipt, debt
    slip, VAT invoice, **red invoice**, phiếu mở ca, phiếu đóng ca, chain summary slip.
- **Glory (máy đổi tiền — 釣銭機 YRT-R08-MN)**:
  - Đấu qua adapter **HTTP/JSON trên LAN** (port 80, **không HTTPS**, IP allowlist —
    chỉ workstation IP gọi được). Cloud **không bao giờ** gọi thẳng máy.
  - Host duy nhất là **workstation-app** (`workstation/internal/device/glory/` +
    `service/cash_changer.go`). Đăng ký trong Cloud qua registry `peripheral_devices`
    kiểu `coin_changer`, chỉ để giám sát + gán doanh thu; lệnh start/dispense do
    workstation phát cục bộ.
  - Thanh toán cash qua Glory: `total` = tiền phải thu, deposit = tiền khách bỏ vào, khi
    `deposit ≥ total` → gọi **FixDeposit** → máy trả tiền thừa (`dispenseChange`) →
    `finish`. Poll ≥1s. Serialize (mutex) — máy chỉ xử lý 1 giao dịch/1 lúc.
  - **An toàn tiền**: chỉ ghi `finish` khi `GetTransaction` trả `finish` đúng
    `dispensedCash`. `cancel`/`timeout`/`abort`/`failure` → **không ghi doanh thu**;
    `timeout`/abort/failure cần **đối soát tay** (tiền có thể nằm trong máy). `empty`
    (thiếu tiền thối) → **bắt buộc Cancel** → nạp thêm → thử lại.
  - Heartbeat lên Cloud (状態/在高, ~30–60s) → bảng HQ hiển thị online / sắp hết tiền
    thối (`nearEmpty`) / lỗi.
  - **Chưa test trên máy thật** (#1051 — kiếm 1 tiệm có máy để smoke test). Lỗi `failure`
    cần dựng màn hình báo + quy trình đối soát drawer (open todo).

## Phân quyền cho nhân viên

Bổ sung cấu hình **vai trò / quyền** cho từng nhân viên (staff) trong org:

- Các mức hiện có: Org Admin (100) · Org Manager (80) · Shop Manager (60 —
  **tối thiểu để xem/vận hành quản lý ca Till**) · Staff (30) · Shop Staff (10 —
  không vào được khu quản lý ca).
- Phạt nhanh một số quy tắc để xác nhận đúng vai: quản lý ca (Till) chỉ Shop Manager+;
  tạo/sửa/xoá bàn yêu cầu Shop Manager; đổi toggle "chi tiết thuế" yêu cầu quản lý
  đăng nhập.

> **Lưu ý**: Nếu **không hoàn thành kịp** việc phân quyền nhân viên trước ngày release,
> sẽ **báo lên nhóm** để **HQ chủ động đóng/mở món** (thay vì phụ thuộc phân quyền nhân
> viên), nhằm tránh món bán nhầm trong giai đoạn đầu vận hành.
