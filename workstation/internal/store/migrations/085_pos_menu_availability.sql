-- plan-056 — bật/tắt món ngay trên POS, chạy được cả khi mất mạng.
--
-- Hai bảng, hai vòng đời hoàn toàn khác nhau. Gộp chúng lại là hỏng, và hỏng
-- im lặng.
--
-- ## 1. `pos_menu_product_skus` — BẢN SAO, bị xoá sạch mỗi lần pull
--
-- Feed `menu-catalog` gộp `menu_product_skus` của Cloud vào `pos_product_skus`
-- theo `product_sku_id`, nên UUID của hàng pivot KHÔNG sống sót — mà bật/tắt
-- biến thể lại sống đúng trên hàng pivot đó. Không có bảng này thì:
--
--   · không có địa chỉ để ghi ngược một cú tắt biến thể lên Cloud, và
--   · một `product_sku` nằm ở HAI menu chỉ có MỘT trạng thái trên máy trạm —
--     tắt size L ở menu trưa cũng tắt luôn ở menu tối, hoặc (đúng như lỗi đang
--     chạy trước plan-056) không tắt được ở đâu cả.
--
-- Nó nằm cùng nhóm với `pos_menu_products`: `PullMenuCatalog` DELETE sạch rồi
-- INSERT lại, Cloud là nguồn sự thật.
--
-- ## 2. `pos_menu_availability_overrides` — GHI CỤC BỘ, SỐNG SÓT qua pull
--
-- Đây là lý do bảng 1 không đủ. Thu ngân tắt món lúc mất mạng ⇒ ghi vào máy
-- trạm ⇒ op nằm chờ trong `sync_queue`. Nếu ghi thẳng vào bảng 1, chỉ cần một
-- thay đổi KHÔNG LIÊN QUAN ở HQ kích hoạt một lượt pull là cú tắt đó bị
-- `DELETE FROM` xoá mất — món hết hàng lặng lẽ bày bán lại.
--
-- Bảng này KHÔNG có khoá ngoại sang bảng 1 hay `pos_menu_products`, cố ý: FK sẽ
-- kéo nó chết theo mỗi lần pull, tức đúng thứ nó sinh ra để tránh.
--
-- Đọc = LEFT JOIN + COALESCE(override, replica). Đối soát 4 bước ở cuối mỗi
-- lượt pull thành công (xem `reconcileAvailabilityOverrides` trong
-- sync_pull_pos.go).

CREATE TABLE IF NOT EXISTS pos_menu_product_skus (
    id                  TEXT PRIMARY KEY,   -- UUID hàng menu_product_skus của Cloud
    menu_product_id     TEXT NOT NULL,
    product_sku_id      TEXT NOT NULL,
    is_active           INTEGER NOT NULL DEFAULT 1,
    -- Giá CHỈ ĐỂ HIỂN THỊ trên màn quản lý. Đường bán vẫn đọc
    -- pos_product_skus.selling_price; định giá từ đây sẽ dựng lại đúng cái
    -- gộp-xuyên-menu mà bảng này sinh ra để gỡ.
    selling_price       INTEGER,
    is_price_overridden INTEGER NOT NULL DEFAULT 0,
    disabled_reason     TEXT,
    disabled_at         TEXT,
    disabled_by_name    TEXT,
    local_synced_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_pos_menu_product_skus_mp
    ON pos_menu_product_skus(menu_product_id);
CREATE INDEX IF NOT EXISTS idx_pos_menu_product_skus_sku
    ON pos_menu_product_skus(product_sku_id);

CREATE TABLE IF NOT EXISTS pos_menu_availability_overrides (
    -- 'menu_product' | 'menu_product_sku'
    entity_type   TEXT    NOT NULL,
    entity_id     TEXT    NOT NULL,
    is_active     INTEGER NOT NULL,
    reason        TEXT,
    actor_user_id TEXT,
    actor_name    TEXT,
    -- Lúc NGƯỜI BẤM bấm. Đi lên Cloud nguyên vẹn để báo cáo không dồn cả ca
    -- offline vào đúng cái phút máy trạm nối lại mạng.
    acted_at      TEXT    NOT NULL,
    pending_sync  INTEGER NOT NULL DEFAULT 1,
    PRIMARY KEY (entity_type, entity_id)
);

CREATE INDEX IF NOT EXISTS idx_pos_menu_availability_pending
    ON pos_menu_availability_overrides(pending_sync);

-- Cột `disabled_*` trên bảng món (bảng đã có từ migration 018). Cùng bộ ba với
-- Cloud để màn quản lý hiện được "Hết hàng · 14:32 · Ann" mà không phải hỏi
-- thêm một feed nữa.
ALTER TABLE pos_menu_products ADD COLUMN disabled_reason TEXT;
ALTER TABLE pos_menu_products ADD COLUMN disabled_at TEXT;
ALTER TABLE pos_menu_products ADD COLUMN disabled_by_name TEXT;
