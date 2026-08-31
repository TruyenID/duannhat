-- #2860 — viết lại từ vựng chia bill đã lưu LOCAL về canonical.
--
-- `even` · `by_items` · `by_amount`. Lý do chọn đúng ba giá trị đó, và vì sao
-- `even` chứ không phải `equal`: xem enum phía Cloud
-- (`backend/app/Services/Order/Enums/OrderSplitMode.php`).
--
-- # Vì sao máy trạm cũng cần migration, không chỉ Cloud
--
-- Cùng lượt đổi này gỡ nhánh `case "even", "equal"` khỏi `print_service.go` và
-- `print_receipt.go`. Sau khi gỡ, một khoản thanh toán CŨ còn nằm trong SQLite
-- với `metadata.split_mode = "equal"` sẽ không khớp nhánh nào — nó rơi xuống
-- nhánh suy đoán `splitCount > 1`, mà `split_count` không phải lúc nào cũng có
-- trong blob. Khi không có, phiếu in ra như **hoá đơn thường thay vì phiếu
-- chia**: mất dòng "phần của người thứ N", đúng thứ khách cầm để đối chiếu.
--
-- Dữ liệu ấy sống trên hai máy Windows ngoài quán và không tự cập nhật, nên
-- "vài ngày nữa nó tự hết" là không đúng — nó ở đó cho tới khi có người viết lại.
--
-- # Khớp với migration phía Cloud
--
-- Cùng ánh xạ, cùng lý lẽ với
-- `backend/database/migrations/2026_08_15_100000_manual_migration_canonicalize_split_mode_vocabulary.php`.
-- Hai bản phải đi cùng nhau: Cloud viết lại bản của nó, máy trạm viết lại bản
-- của nó, và không bên nào phải biết tên cũ sau đó.
--
-- # json_set là an toàn ở đây
--
-- SQLite bản đi kèm workstation có JSON1. `json_set` giữ nguyên mọi khoá khác
-- của blob — quan trọng vì `metadata` còn chứa `print_history`, `bill_index`,
-- `item_allocations`…
--
-- Hai điều kiện chặn HAI thứ KHÁC NHAU, và bản đầu của comment này quy công
-- nhầm chỗ (sửa 2026-08-18, đo bằng chính SQLite):
--
--   json_valid   → chặn metadata là chuỗi rỗng hoặc JSON hỏng
--   json_extract → trả NULL cho mọi thứ KHÔNG phải object có khoá đó, và
--                  chính vế này mới loại chuỗi đã escape hai lần
--
-- Vì sao khác biệt ấy đáng ghi: `json_valid('"{\"split_mode\":\"equal\"}"')`
-- trả **1**, không phải 0 — với SQLite đó là một chuỗi JSON hợp lệ. Ai đọc
-- comment cũ rồi bỏ `json_extract` đi vì tưởng `json_valid` đã lo liệu sẽ mở
-- ra đúng cái lỗ mà cả hai vế đang cùng đóng.
--
-- Hệ quả CÓ CHỦ ĐÍCH: một hàng escape hai lần mang từ vựng cũ sẽ KHÔNG được
-- migrate. Đo 2026-08-18 trên production: 0/56 hàng `order_payments` có
-- metadata mang hình dạng đó. Không viết thêm nhánh xử lý cho một hình dạng
-- chưa đo được là có thật — đó đúng là loại nhánh tương thích mà #2188 cấm.
-- `MetadataString()` (`domain/payment.go`) đã chuẩn hoá ở đường GHI, nên hình
-- dạng này chỉ có thể đến từ hàng ra đời trước bước chuẩn hoá đó.
-- Hành vi được ghim ở `TestMigration087LeavesDoubleEscapedMetadataAlone`.
UPDATE payments
   SET metadata = json_set(metadata, '$.split_mode', 'even')
 WHERE metadata IS NOT NULL
   AND json_valid(metadata)
   AND json_extract(metadata, '$.split_mode') IN ('equal', 'by_people', 'split_even');

UPDATE payments
   SET metadata = json_set(metadata, '$.split_mode', 'by_amount')
 WHERE metadata IS NOT NULL
   AND json_valid(metadata)
   AND json_extract(metadata, '$.split_mode') = 'custom';

-- Đường Stripe/PayPay đặt tên trường khác cho CÙNG từ vựng. Bỏ sót chỗ này là
-- thứ dễ xảy ra nhất: grep theo `split_mode` không thấy nó.
UPDATE payments
   SET metadata = json_set(metadata, '$.split_type', 'even')
 WHERE metadata IS NOT NULL
   AND json_valid(metadata)
   AND json_extract(metadata, '$.split_type') IN ('equal', 'by_people', 'split_even');

UPDATE payments
   SET metadata = json_set(metadata, '$.split_type', 'by_amount')
 WHERE metadata IS NOT NULL
   AND json_valid(metadata)
   AND json_extract(metadata, '$.split_type') = 'custom';

-- KHÔNG có khối cho `orders.split_mode`: cột đó **không tồn tại** ở schema máy
-- trạm (đã kiểm toàn bộ `internal/store/migrations/`). Chế độ cả bàn thoả thuận
-- chỉ sống ở Cloud; máy trạm đọc chế độ theo TỪNG khoản thanh toán, từ blob
-- `payments.metadata` ở trên. Viết một `UPDATE orders SET split_mode` "cho đủ
-- bộ" sẽ làm migration chết ngay lúc khởi động app.
