-- #2535 B10 — phiên thu tiền 釣銭機 phải sống sót qua một lần restart.
--
-- Trước bảng này, phiên sống HOÀN TOÀN trong bộ nhớ (`CashChangerService.active`).
-- Workstation tắt giữa lúc máy đã nhận tiền và trước khi `RecordCashPayment`
-- chạy xong ⇒ không payment, không alert, không queue item, **không gì để
-- reconcile**. Tiền mặt thật nằm trong ngăn kéo và hệ thống không biết nó tồn
-- tại. #1810 đã sửa phần POS treo spinner; phía TIỀN thì chưa ai đụng tới.
--
-- Hàng được ghi TRƯỚC khi gọi máy. Một hàng thừa (phiên chưa bao giờ nhận tiền)
-- là vô hại và tự đóng ở lượt đối soát kế; một lượt thu KHÔNG có hàng nào là
-- thứ không tìm lại được.
--
-- `glory_transaction_id` để RỖNG lúc tạo và được đóng dấu ngay khi máy trả id.
-- Đó là khác biệt quyết định lúc khởi động lại:
--
--   có id  → hỏi thẳng máy giao dịch đó kết thúc thế nào, xử theo câu trả lời
--   rỗng   → không ai biết máy có nhận tiền hay không ⇒ cảnh báo cho NGƯỜI,
--            tuyệt đối không đoán
--
-- KHÔNG có FK sang `orders`: bảng này là dấu vết phục hồi, và nó phải đọc được
-- kể cả khi hàng đơn đã bị dọn đi vì lý do khác.
CREATE TABLE IF NOT EXISTS cash_changer_sessions (
    id                   TEXT PRIMARY KEY,           -- session id của service
    order_id             TEXT NOT NULL,
    amount               INTEGER NOT NULL,           -- số máy được đòi (phần còn thiếu)
    glory_transaction_id TEXT NOT NULL DEFAULT '',   -- rỗng cho tới khi máy trả id
    surface              TEXT NOT NULL DEFAULT '',   -- 'pos' | 'kiosk' — ai mở lượt thu
    started_at           TEXT NOT NULL,              -- RFC3339 UTC
    resolved_at          TEXT,                       -- NULL = còn dở, đây là thứ lượt đối soát tìm
    outcome              TEXT                        -- 'recorded' | 'returned' | 'retained' | 'unknown'
);

-- Phép đọc duy nhất: "còn phiên nào chưa ngã ngũ không" — chạy đúng một lần lúc
-- khởi động. Index một phần vì hàng đã resolved không bao giờ được hỏi lại.
CREATE INDEX IF NOT EXISTS idx_cash_changer_sessions_unresolved
    ON cash_changer_sessions (started_at)
    WHERE resolved_at IS NULL;
