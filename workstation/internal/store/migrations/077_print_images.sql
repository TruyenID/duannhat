-- 077 — #1957 mảnh B: cache ảnh in ở máy trạm.
--
-- Cloud raster hoá MỘT LẦN và gửi xuống bitmap 1-bit đã đóng gói; máy trạm không
-- bao giờ thấy PNG. Lý do nằm ở chỗ khác với "cho nhẹ": hai bộ GIẢI MÃ ảnh khớp
-- nhau từng pixel là lời hứa lớn hơn nhiều so với hai bộ MÃ HOÁ khớp nhau ở một
-- header tám byte — và chỉ cái thứ hai ghim được bằng golden fixture. Để Go tự
-- giải mã PNG là mở một mặt trận parity mới (scaling + ngưỡng của GD so với một
-- thư viện Go) cho một tính năng trang trí.
--
-- HAI bảng, vì hai thứ có vòng đời khác nhau:
--
--   print_image_blobs   — byte, khoá theo content_hash. BẤT BIẾN: một hash chỉ
--                         ứng đúng một chuỗi byte. Không bao giờ UPDATE.
--   print_image_current — con trỏ "ảnh nào đang dùng cho (source, bề rộng)".
--                         Đây mới là thứ THAY ĐỔI khi HQ publish logo mới.
--
-- Gộp lại thì mỗi lần đổi logo sẽ ghi đè byte cũ, và một bản in lại không còn
-- đường nào tìm về bitmap đã thật sự in ra. Tách ra thì byte cũ vẫn nằm đó.
--
-- Byte lưu BLOB chứ không TEXT: chúng là nhị phân. Cloud chuyển qua base64 vì
-- JSON không mang được byte thô, nhưng giải mã một lần lúc lưu rồi thôi — giữ
-- base64 trên đĩa là cõng thêm 33% dung lượng và một bước giải mã ở MỌI lần in.
--
-- TR-05: thiếu bytes thì phiếu VẪN IN, chỉ thiếu khối. Bảng này rỗng là trạng
-- thái hợp lệ của một máy chưa từng online, không phải một lỗi cần chặn bán hàng.
CREATE TABLE IF NOT EXISTS print_image_blobs (
    content_hash TEXT    NOT NULL PRIMARY KEY,
    width_dots   INTEGER NOT NULL,
    height_dots  INTEGER NOT NULL,
    byte_length  INTEGER NOT NULL,
    data         BLOB    NOT NULL,
    fetched_at   TEXT    NOT NULL
);

CREATE TABLE IF NOT EXISTS print_image_current (
    source         TEXT    NOT NULL,
    max_width_dots INTEGER NOT NULL,
    content_hash   TEXT    NOT NULL,
    version        INTEGER NOT NULL DEFAULT 0,
    -- Giờ treo tường của CHI NHÁNH ("YYYY-MM-DD HH:MM:SS"), KHÔNG phải một mốc
    -- thời gian tuyệt đối — hệt như print_templates.effective_from (#1091). Lưu
    -- dạng timestamp sẽ kéo nó qua một lượt đổi múi giờ và dời đúng bằng độ lệch
    -- của chi nhánh. So sánh là so chuỗi, đúng vì định dạng này sắp xếp được.
    effective_from TEXT,
    cloud_updated_at TEXT,
    fetched_at     TEXT    NOT NULL,
    PRIMARY KEY (source, max_width_dots)
);

-- Tra lúc in là "byte cho (source, bề rộng này)", đi qua con trỏ rồi tới blob.
CREATE INDEX IF NOT EXISTS idx_print_image_current_hash
    ON print_image_current (content_hash);
