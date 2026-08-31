-- #2885 — đẩy `order_money_overwrites` lên Cloud: cột đánh dấu ĐÃ GỬI.
--
-- Bảng 080 là bằng chứng đối soát TIỀN, và cho tới nay nó chưa bao giờ rời khỏi
-- máy trạm. Đo được ngày 2026-08-15: để trả lời "ba cảnh báo
-- `cloud_money_overwrite` đang treo là những đơn nào" phải lần ngược
-- `audit_logs` của Cloud bằng tay, tra Stripe API, và cuối cùng vẫn phải hỏi
-- trí nhớ người. Sổ Cloud thì cân (464 đơn kết thúc, 1 đơn lệch đã giải thích)
-- — nhưng "sổ Cloud cân" KHÔNG chứng minh máy trạm đồng ý, vì cảnh báo đo
-- khoảng lệch giữa HAI hệ và một nửa dữ liệu nằm ngoài tầm với của HQ.
--
-- # Vì sao là `synced_at`, KHÔNG phải hàng đợi `sync_queue`
--
-- `sync_queue` mang ngữ nghĩa "một mutation phải tới nơi, có attempts, có
-- dead-letter, có max_attempts". Bảng này thì ngược lại: nó là LỊCH SỬ, đã
-- xảy ra rồi, và việc HQ chưa nhận được không làm nó kém đúng đi. Một cột đánh
-- dấu trên chính hàng bằng chứng giữ đúng quan hệ đó — không có hàng thứ hai
-- để lệch với hàng thứ nhất, và không có đường nào xoá được bằng chứng vì
-- "đã hết lượt thử".
--
-- # Vì sao NULL-able, và vì sao KHÔNG có default
--
-- NULL ở đây nghĩa là "chưa gửi", một trạng thái vận hành thật và tạm thời —
-- không phải nhánh tương thích dữ liệu cũ mà #2188 cấm. Mọi hàng đang có trên
-- máy trạm production đúng là chưa gửi, nên NULL là giá trị ĐÚNG cho chúng,
-- không phải giá trị thiếu.
--
-- # Vì sao đánh dấu chứ không đẩy lại mãi
--
-- Đường đẩy alert (#2695) đẩy lại MỌI alert đang mở mỗi phút — trên production
-- đó là 4.721 dòng log/ngày. Với ảnh chụp trạng thái thì đúng; với bản ghi lịch
-- sử thì sai: gửi một lần, đánh dấu, thôi. Khử trùng phía Cloud theo
-- `(device_id, local_id)` là lưới an toàn cho lần gửi trùng do mất dấu
-- (mất điện giữa 2xx và UPDATE), KHÔNG phải cơ chế chính.
ALTER TABLE order_money_overwrites ADD COLUMN synced_at TEXT; -- RFC3339 UTC, NULL = chưa gửi

-- Index MỘT PHẦN, đúng vị ngữ của vòng đẩy (`WHERE synced_at IS NULL ORDER BY id`).
-- Một index đầy đủ sẽ lớn dần theo toàn bộ lịch sử ghi đè của quán trong khi
-- câu hỏi duy nhất hỏi nó là "còn gì CHƯA gửi" — tập hợp gần như luôn rỗng.
CREATE INDEX IF NOT EXISTS idx_order_money_overwrites_pending
    ON order_money_overwrites (id)
    WHERE synced_at IS NULL;
