-- #2901 — vòng đệm log cục bộ + tiến độ của các yêu cầu kéo log từ HQ.
--
-- # Vì sao phải có bảng, chứ không phải "bật log shipping"
--
-- Đo ngày 2026-08-15: `slog` mặc định ra **stderr**, và `slog.SetDefault` không
-- xuất hiện ở đâu ngoài test ⇒ máy trạm **chưa có logger dùng chung**, không
-- ghi file, không gửi đi đâu. Fleet là hai máy Windows ở hai quán, không ai
-- ngồi cạnh, nên "điều tra sự cố" hôm nay nghĩa là bay tới quán. Bảng này là
-- nửa lưu trữ của cái handler ghi lại (`internal/service/log_buffer.go`).
--
-- # Vì sao chỉ `info` trở lên, và vì sao đó gần như không mất gì
--
-- Đếm điểm gọi trên cây này: `Debug 7` · `Info 62` · `Warn 173` · `Error 79`.
-- `info+` là **314** điểm gọi; bỏ `debug` mất đúng 7 chỗ. Ngưỡng do chủ dự án
-- chốt (2026-08-16), và Cloud cưỡng chế lại bằng 422 — bảng này không được
-- chứa `debug`, kể cả khi có ai đó nới handler.
--
-- # Vì sao KHÔNG có cột `synced_at`
--
-- Khác `order_money_overwrites` (088) và các sổ 釣銭機 (090/091): những bảng ấy
-- gửi MỘT lần rồi thôi, nên "đã gửi" là thuộc tính của chính hàng. Ở đây một
-- dòng log có thể nằm trong cửa sổ thời gian của HAI yêu cầu điều tra khác
-- nhau, và cả hai đều có quyền nhận nó. Đánh dấu trên hàng sẽ làm yêu cầu thứ
-- hai nhận một khoảng trống mà không biết là trống — đúng bẫy "mẫu số bằng
-- không". Tiến độ vì thế thuộc về YÊU CẦU, không thuộc về dòng log
-- (`log_request_progress` bên dưới).
--
-- # Trần + tự cắt
--
-- Đây là vòng đệm trên máy của QUÁN, không phải kho lưu trữ. Nó có trần theo số
-- hàng và theo tuổi (`internal/service/log_buffer.go`), và phần cắt bám vào `id`
-- tự tăng nên luôn cắt đúng bản ghi CŨ NHẤT.
CREATE TABLE IF NOT EXISTS log_records (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    logged_at TEXT NOT NULL,                  -- RFC3339 UTC, dấu thời gian của CHÍNH bản ghi
    level     TEXT NOT NULL,                  -- 'info' | 'warn' | 'error' — KHÔNG BAO GIỜ 'debug'
    message   TEXT NOT NULL,                  -- khoá allowlist, nguyên văn
    attrs     TEXT NOT NULL DEFAULT '{}'      -- JSON, CHỈ các attr đã khai cho message này
);

-- Vòng đẩy hỏi theo cửa sổ thời gian rồi đi tiếp theo `id`; index này phục vụ
-- đúng vị ngữ đó và cũng là thứ giữ phép cắt theo tuổi rẻ.
CREATE INDEX IF NOT EXISTS idx_log_records_logged_at ON log_records (logged_at);

-- ---------------------------------------------------------------------------
-- Tiến độ theo TỪNG yêu cầu kéo
-- ---------------------------------------------------------------------------
--
-- Cloud khử trùng theo `(device_id, local_id)` nên gửi trùng là vô hại. Nhưng
-- KHÔNG có con trỏ thì máy trạm gửi mãi 500 dòng đầu tiên và **không bao giờ
-- tới được lô `final`** — yêu cầu treo vĩnh viễn ở HQ, và người điều tra nhìn
-- thấy một danh sách cụt mà không có gì nói cho họ biết là nó cụt.
--
-- `closed_at` là chốt chặn cho ba đường kết thúc, và cả ba đều phải im lặng:
-- gửi xong lô `final`; Cloud trả 404 (yêu cầu đã đóng/hết hạn — hợp đồng nói
-- backend deploy TRƯỚC, nên đây là trạng thái đã lường); Cloud trả 422 (payload
-- sai hình dạng — gửi lại y hệt mỗi phút không sửa được gì, chỉ đốt ngân sách
-- 250 req/phút mà đường đẩy TIỀN đang dùng chung).
CREATE TABLE IF NOT EXISTS log_request_progress (
    request_id    TEXT PRIMARY KEY,
    last_local_id INTEGER NOT NULL DEFAULT 0, -- `log_records.id` cuối cùng ĐÃ được Cloud xác nhận (2xx)
    sent_count    INTEGER NOT NULL DEFAULT 0, -- để tôn trọng `max_records` qua nhiều lô
    closed_at     TEXT,                       -- RFC3339 UTC, NULL = còn đang phục vụ
    updated_at    TEXT NOT NULL
);
