# Vai workstation, đường đẩy print job, và ngưỡng failover

Chốt 2026-08-13 (#2689). Nền: #2210 là bản khảo sát cloud-first 2026-07-23, để
ba câu hỏi treo. Giai đoạn 0 của nó (auth) hoá ra **đã ship** — xem #2687.

## Ràng buộc vật lý, không phải lựa chọn

**Cloud không mở được socket tới máy in LAN.** Mọi thiết kế "cloud-first cho máy
in" vì thế chỉ có thể là *cloud quyết định + render, LAN đẩy byte*. Workstation
không biến mất. Đây là điểm quan trọng nhất của cả hồ sơ và nó không đổi.

## 1. Workstation GIỮ vai hub offline

Không teo thành agent câm. Quyền chia theo **loại dữ liệu**:

| | nguồn sự thật |
|---|---|
| catalog, giá, menu, template, cấu hình | Cloud |
| đơn đang phục vụ, phiên bàn, hàng đợi in | **workstation** |

Workstation phải bán được khi mất mạng.

Đây là chuẩn ngành, không phải nợ kỹ thuật: Toast — hệ POS nhà hàng lớn nhất Bắc
Mỹ — dùng **local hub trên LAN phân phối đơn giữa các terminal** ("Local Sync"),
tự bật offline sau ~40 giây và tự chuyển lại. Tức topology này *là* thứ ngành
hội tụ về, không phải thứ cần gỡ bỏ.

Hệ quả: đừng đề xuất xoá `sync_service.go`, hàng đợi sync, hay chữ ký offline
(#1092). Chúng là tài sản, không phải legacy — và #2188 **không** áp vào đây.

## 2. Print job: push + poll làm SÀN

Reverb đẩy để trễ thấp; agent **vẫn poll** chu kỳ thấp làm lưới an toàn.

**Cấm push-only.** Một frame websocket rớt không được phép làm mất phiếu bếp.
Repo này đã có bằng chứng broadcast đi vào ngõ cụt được, nên push-only ở đây
không phải rủi ro lý thuyết.

Đây cũng đúng đường Star Micronics đi: CloudPRNT dùng HTTP poll, CloudPRNT Next
chuyển sang MQTT push để giảm trễ và tải — nhưng **giữ poll**, không bỏ. Và repo
này đã chạy mô hình poll trong production: `backend/routes/api/cloudprnt.php`
phục vụ đúng bộ ba `poll` / `fetch` / `confirm`.

## 3. Failover TỰ ĐỘNG, có trần cho tiền

Mẫu circuit breaker chuẩn:

- **Ngắt mạch** sau N lỗi **liên tiếp** trên chính lời gọi API — không dùng một
  endpoint thăm dò riêng, vì nó đo sức khoẻ của chính nó chứ không đo đường mà
  đơn hàng thật sự đi qua.
- **Half-open tự dò** sau backoff: cho một lượt gọi thật đi qua; xanh thì đóng
  mạch, đỏ thì ngắt lại **ngay** (không bắt đếm lại từ đầu — vừa có bằng chứng
  tươi).
- **Một lượt thành công phá chuỗi.** Không có tín hiệu này thì bộ đếm chỉ tăng,
  và các cái chớp rải rác suốt một ca cộng dồn thành một lần ngắt mạch dù
  workstation chưa bao giờ thật sự hỏng.
- **Bật lại là TỰ ĐỘNG.** Bắt người bấm tay là anti-pattern: giữa giờ phục vụ
  không ai bấm, và quán kẹt ở chế độ dự phòng.
- **Luôn hiện trạng thái** cho nhân viên biết đang chạy chế độ nào.

Đã cài ở `web/pos/src/services/workstation/base-url-resolver.ts`:
`FAILURE_THRESHOLD = 3`, backoff tái dùng `UNREACHABLE_BACKOFF_MS = 30_000`,
trạng thái suy ra qua `breakerState()` chứ không giữ enum riêng (hai nguồn sự
thật cho cùng một trạng thái là chỗ chúng trôi khỏi nhau).

**Đường TIỀN phải có trần phơi nhiễm** — chưa cài. Square chặn cứng thanh toán
thẻ offline ở 24 giờ kể từ giao dịch offline đầu tiên. Failover tự động cho
đường đọc thì an toàn; cho đường thu tiền thì retry không đủ, phải có trần.

## Còn nợ

- poll sàn cho print job (chu kỳ + idempotency với `print_jobs`)
- trần phơi nhiễm cho thu tiền offline
- hiện trạng thái mạch trên giao diện POS

Không mở lại cloud-first như một epic. Ai chạm ba việc trên thì bắt đầu từ
khoảng trống **đo được**, không từ bản khảo sát #2210.
