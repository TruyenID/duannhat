-- #2162 (phần B của #2127) — dấu vết kiểm toán khi Cloud ghi đè tiền.
--
-- Cloud thắng là quyết định ĐÚNG (Cloud là sổ kế toán), nhưng sau khi nó thắng,
-- không còn chỗ nào đọc lại được "máy trạm từng nghĩ khác, và khác bao nhiêu":
-- alert (#2127 A) đóng lại khi có người ack, log thì xoay vòng. Bảng này là dấu
-- vết còn lại — đọc từ chính đơn hàng, sau cả hai.
--
-- Ruling đã chốt (#2162), theo thứ tự sức nặng:
--
--   1. KHÔNG phải một dòng `type=audit` trong `order_conditions`. Sổ đó mang
--      hai bất biến tổng (Σ(discount) == −discount_amount, total == subtotal +
--      Σ(amount)); một dòng không-tiền thay bất biến bằng quy ước mềm "nhớ loại
--      trừ ở mọi chỗ cộng" — đúng loại bẫy #2074/#2075 vừa dọn, và bẫy ấy không
--      kêu, nó chỉ ra số sai.
--   2. Append-only, KHÔNG FK cứng, KHÔNG tham gia phép cộng nào. Kế toán kép /
--      event-sourcing đều tách audit log khỏi sổ có số dư: sổ chứa dòng
--      không-tiền thì không còn tự kiểm tra được bằng phép cộng.
--
-- Mỗi dòng là MỘT lần ghi đè: cùng một đơn bị ghi đè hai lần là HAI dòng —
-- khác bảng `alerts`, nơi (kind, subject) gộp thành một dòng có `count` tăng.
-- Alert trả lời "có chuyện, ai đó nhìn đi"; bảng này trả lời "chính xác chuyện
-- gì, từng lần một".
--
-- `paid_locally` là tiền đã vào két TẠI THỜI ĐIỂM ghi đè — nó quyết định khoảng
-- lệch là 過不足 thật (tiền đã thu) hay mới chỉ là một tờ phiếu sắp in sai
-- (chưa thu). Snapshot, không đọc lại từ payments lúc đối soát: số đó đổi theo
-- thời gian, còn câu hỏi kiểm toán là về LÚC ĐÓ.

CREATE TABLE IF NOT EXISTS order_money_overwrites (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id              TEXT    NOT NULL,

    -- Ảnh chụp TRƯỚC (số máy trạm đang giữ) và số Cloud ghi đè, đủ 5 trường
    -- tiền của đơn — kể cả trường không đổi, để một dòng tự đứng được mà không
    -- phải join ngược về orders (bảng ấy đã bị ghi đè rồi).
    total_amount_local    INTEGER NOT NULL,
    total_amount_cloud    INTEGER NOT NULL,
    subtotal_local        INTEGER NOT NULL,
    subtotal_cloud        INTEGER NOT NULL,
    tax_amount_local      INTEGER NOT NULL,
    tax_amount_cloud      INTEGER NOT NULL,
    service_charge_local  INTEGER NOT NULL,
    service_charge_cloud  INTEGER NOT NULL,
    discount_amount_local INTEGER NOT NULL,
    discount_amount_cloud INTEGER NOT NULL,

    paid_locally          INTEGER NOT NULL,
    created_at            TEXT    NOT NULL    -- RFC3339 UTC
);

-- Phép đọc duy nhất của bảng: "đơn này đã từng bị ghi đè chưa, và như thế nào"
CREATE INDEX IF NOT EXISTS idx_order_money_overwrites_order
    ON order_money_overwrites (order_id, created_at);
