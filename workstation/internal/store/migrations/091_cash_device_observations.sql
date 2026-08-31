-- T2 (#2879) + T5 (#2882) của #2876 — hai sổ quan sát máy 釣銭機 chờ đẩy lên Cloud.
--
-- Cùng khuôn `synced_at` với `cash_changer_sessions` (088): NULL = chưa đẩy.
-- Cột đó KHÔNG phải cơ chế đảm bảo đúng đắn — Cloud idempotent theo khoá tự
-- nhiên lo phần đó — nó chỉ để KHỎI đẩy lại.

-- ---------------------------------------------------------------------------
-- 在高 tại ranh ca (T2)
-- ---------------------------------------------------------------------------
--
-- Adapter đã có `GetInventory` (在高取得) từ đầu và KHÔNG AI GỌI — đo được:
--   grep -rn "GetInventory" internal --include=*.go  (trừ khai + test) → RỖNG
--
-- Nên chốt ca chỉ có hai chân: sổ ↔ NGƯỜI đếm. Bảng này là chân thứ ba.
--
-- `uncertain_denominations` là mảnh không được bỏ: `CashErrorStatus.Cash` cho
-- biết mệnh giá nào MÁY TỰ KHAI là không chắc. Đem mệnh giá đó đi tính lệch là
-- bịa ra một con số rồi bắt quán đi tìm tiền không mất.
CREATE TABLE IF NOT EXISTS cash_device_inventory_snapshots (
    id                      TEXT PRIMARY KEY,
    peripheral_device_id    TEXT NOT NULL,
    till_session_id         TEXT NOT NULL,
    count_phase             TEXT NOT NULL,        -- 'opening' | 'closing'
    denominations           TEXT NOT NULL,        -- JSON {mệnh giá: số lượng}
    uncertain_denominations TEXT NOT NULL DEFAULT '[]', -- JSON list
    bill_reject_count       INTEGER NOT NULL DEFAULT 0,
    machine_seq_no          INTEGER,
    captured_at             TEXT NOT NULL,        -- RFC3339 UTC
    synced_at               TEXT,
    UNIQUE (peripheral_device_id, till_session_id, count_phase)
);

CREATE INDEX IF NOT EXISTS idx_cash_device_inv_unsynced
    ON cash_device_inventory_snapshots (captured_at)
    WHERE synced_at IS NULL;

-- ---------------------------------------------------------------------------
-- Sự cố có dấu thời gian (T5)
-- ---------------------------------------------------------------------------
--
-- `cash_changer_sessions.error_title` (088) đã đủ cho lỗi xảy ra TRONG một lượt
-- thu. Hai nhóm nặng nhất thì không có lượt thu nào để bám vào:
--
--   forbidden    — IP máy trạm ngoài allowlist của adapter. CẤU HÌNH SAI, và nó
--                  im lặng hàng tuần.
--   connectivity — đứt cáp / mất điện / máy chưa sẵn sàng.
--
-- `cleared_at` là mảnh cho phép tính THỜI LƯỢNG — con số quy ra tiền, và là thứ
-- phân biệt bảng này với một dòng log.
--
-- MỘT LẦN XẢY RA = MỘT HÀNG. Collector poll theo `pollInterval`, nên một sự cố
-- kéo dài 2 phút sinh ra hàng trăm lượt GẶP lỗi — ghi mỗi lượt thì sổ thành rác
-- và sẽ bị tắt. `UNIQUE` dưới đây là thứ cưỡng chế điều đó.
CREATE TABLE IF NOT EXISTS cash_device_error_events (
    id                   TEXT PRIMARY KEY,
    peripheral_device_id TEXT NOT NULL,
    error_title          TEXT NOT NULL,          -- nguyên văn glory.Error.Title
    error_group          TEXT NOT NULL,          -- change_shortage|needs_operator|connectivity|forbidden
    occurred_at          TEXT NOT NULL,          -- RFC3339 UTC
    cleared_at           TEXT,                   -- NULL = đang còn
    glory_transaction_id TEXT NOT NULL DEFAULT '',
    till_session_id      TEXT NOT NULL DEFAULT '',
    synced_at            TEXT,
    UNIQUE (peripheral_device_id, error_title, occurred_at)
);

-- Sự cố ĐANG MỞ: dùng để đóng nó lại khi lỗi hết, và để không đẻ hàng mới cho
-- cùng một sự cố ở lượt poll kế.
CREATE INDEX IF NOT EXISTS idx_cash_device_err_open
    ON cash_device_error_events (peripheral_device_id, error_title)
    WHERE cleared_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_cash_device_err_unsynced
    ON cash_device_error_events (occurred_at)
    WHERE synced_at IS NULL;
