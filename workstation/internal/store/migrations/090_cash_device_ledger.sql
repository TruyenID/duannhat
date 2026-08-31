-- T1 của #2876 (#2878) — sổ lượt thu tiền 釣銭機 phải ĐI LÊN được Cloud.
--
-- Bảng `cash_changer_sessions` (081, #2535 B10) sinh ra để phục hồi sau một lần
-- tắt máy: nó trả lời "còn phiên nào chưa ngã ngũ không". Nó KHÔNG trả lời
-- "lượt thu đó đã diễn ra như thế nào" — và Cloud cần đúng câu thứ hai.
--
-- ## Vì sao KHÔNG tái dụng cột `outcome`
--
-- `outcome` mang từ vựng PHỤC HỒI — 'recorded' | 'returned' | 'retained' |
-- 'unknown' — tức "CHÚNG TA đã làm gì với lượt đó". Cloud cần từ vựng MÁY —
-- 'finish' | 'cancel' | 'abort' | 'timeout' | 'failure' — tức "MÁY đã làm gì".
--
-- Hai câu hỏi khác nhau, và ánh xạ giữa chúng là lossy theo cả hai chiều:
-- 'unknown' có thể là abort (mất điện) hoặc failure (máy lỗi) và ta không phân
-- biệt được; 'returned' gộp cả cancel lẫn hết-tiền-thối. Nhét cả hai vào một
-- cột là đúng cái bẫy #2860 đã trả giá — bảy cách viết cho ba khái niệm, sống
-- nhiều tháng, không gì đỏ.
--
-- Nên `machine_outcome` là cột RIÊNG, mang NGUYÊN VĂN trạng thái kết thúc của
-- adapter. `outcome` giữ nguyên nghĩa cũ, không ai phải sửa lại chỗ đọc nó.
--
-- ## `peripheral_device_id` KHÔNG phải `server_id`
--
-- `cashChangerServerID()` ưu tiên `metadata.server_id`/`serial` — một chuỗi
-- SERIAL của máy — và chỉ fallback về `peripheral_devices.id`. Chuỗi đó tốt
-- cho dòng audit tại chỗ (`cash_changer:<id>`) nhưng Cloud khoá theo UUID
-- thiết bị, nên hai thứ phải là hai cột. Gộp lại thì quán nào có `serial`
-- trong metadata sẽ đẩy lên một khoá Cloud không tra được.
--
-- ## `synced_at`
--
-- NULL = chưa đẩy. Đây là toàn bộ trạng thái của đường sync-UP: không có hàng
-- đợi riêng, không có cờ thứ hai. Cloud idempotent theo
-- (peripheral_device_id, glory_transaction_id) nên đẩy lại là vô hại — cột này
-- chỉ để KHỎI đẩy lại, không phải để đảm bảo đúng đắn.

ALTER TABLE cash_changer_sessions ADD COLUMN peripheral_device_id TEXT NOT NULL DEFAULT '';
ALTER TABLE cash_changer_sessions ADD COLUMN machine_outcome      TEXT NOT NULL DEFAULT '';
ALTER TABLE cash_changer_sessions ADD COLUMN deposited            INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cash_changer_sessions ADD COLUMN change_due           INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cash_changer_sessions ADD COLUMN dispensed            INTEGER NOT NULL DEFAULT 0;
ALTER TABLE cash_changer_sessions ADD COLUMN error_title          TEXT NOT NULL DEFAULT '';
ALTER TABLE cash_changer_sessions ADD COLUMN finished_at          TEXT;
ALTER TABLE cash_changer_sessions ADD COLUMN synced_at            TEXT;

-- Phép đọc của vòng đẩy: "hàng nào đã ngã ngũ ở MÁY mà chưa lên Cloud".
--
-- Điều kiện là `machine_outcome <> ''`, KHÔNG phải `resolved_at IS NOT NULL`:
-- một phiên có thể được đóng lại vì hết giờ mà chưa bao giờ hỏi được máy, và
-- đẩy một hàng không có kết cục máy lên Cloud là đẩy một khẳng định bịa.
CREATE INDEX IF NOT EXISTS idx_cash_changer_sessions_unsynced
    ON cash_changer_sessions (started_at)
    WHERE synced_at IS NULL AND machine_outcome <> '';
