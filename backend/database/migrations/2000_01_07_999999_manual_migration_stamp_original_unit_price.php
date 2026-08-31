<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #2640 — đóng dấu `original_unit_price` cho các dòng cũ, TRƯỚC khi #2617 khoá
 * cột thành NOT NULL.
 *
 * ## Vì sao phải có file này
 *
 * `migrations/omnify/2000_01_08_000000_alter_customer_order_items_table.php`
 * chạy `->change()` không `nullable()`, tức MODIFY sang NOT NULL. Đo production
 * 2026-08-12: **341 / 377 dòng đang NULL**. `ALTER` sẽ nổ, và `deploy-xserver`
 * hỏng GIỮA CHỪNG — ba bước Platform phía sau (`export-authz-manifest` →
 * `service:sync-authz-manifest` → `ServiceUserAccess`) không chạy, nên quyền
 * user trên Platform ngừng được đồng bộ (#2463).
 *
 * ## Vì sao omnify không tự làm được
 *
 * Tài liệu omnify liệt kê đúng một dòng cho ca này: đổi `nullable` ⇒ một câu
 * lệnh sửa kiểu cột. Nó **chỉ sinh DDL** — và không thể biết
 * `original_unit_price` nên bằng gì. Chỉ người viết schema mới biết đó là
 * `unit_price`.
 *
 * Ruling #2188 đã ghi quy trình đúng, gồm HAI bước: *"dữ liệu cũ reseed/backfill
 * LẦN CUỐI rồi xoá lệnh, cột snapshot chuyển NOT NULL"*. Đây là bước một.
 *
 * ⚠️ Docblock ở đây cố ý KHÔNG viết nguyên văn câu lệnh DDL nào.
 * `.githooks/pre-commit` quét toàn bộ nội dung file — kể cả comment — nên một
 * câu trích dẫn tài liệu cũng bị đếm là "migration viết tay khai schema" và
 * commit bị chặn. Đã vấp đúng thế ở lượt đầu của chính file này.
 *
 * ## Vì sao là migration VIẾT TAY, đặt ở thư mục cha
 *
 * `database/migrations/omnify/` bị `omnify generate --force` xoá sạch và dựng
 * lại. File ở thư mục cha thì omnify không đụng, nên bước đóng dấu sống sót qua
 * mọi lượt regen. Tên `2000_01_07_999999` sắp **trước** `2000_01_08_000000` —
 * Laravel sắp theo TÊN FILE, không theo thư mục. Repo đã có tiền lệ đúng khuôn
 * này: `2000_01_02_999999_manual_migration_backfill_customer_order_condition_columns`
 * (#2041).
 *
 * ## Đóng dấu bằng `unit_price`, và điều đó KHÔNG làm sai hiển thị
 *
 * Ruling #2132 §B: `original_unit_price` là dấu vết định hình giá, **bằng
 * `unit_price` khi không cơ chế nào hạ giá**. Dòng cũ chưa từng có promotion áp
 * thì đúng là ca đó. POS/receipt chỉ gạch ngang khi `original > unit`, nên đóng
 * dấu bằng nhau **không sinh ra một dòng gạch ngang nào** — đây là lý do phép
 * đóng dấu này an toàn với giao diện, không chỉ với ràng buộc DB.
 *
 * ## Một lần, không idempotent-vì-tình-cờ
 *
 * `WHERE original_unit_price IS NULL` làm nó chạy lại được mà không giẫm lên
 * dòng đã có giá gốc thật (dòng có promotion). Sau khi cột thành NOT NULL thì
 * mệnh đề đó khớp 0 dòng — chạy lại vô hại.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Bảng chưa tồn tại (cài mới từ đầu): không có gì để đóng dấu, và
        // migration omnify tạo bảng sẽ dựng cột đúng kiểu ngay từ đầu.
        if (! Schema::hasTable('customer_order_items')) {
            return;
        }

        // Cột nguồn phải có mặt. Nếu không, dừng LỚN TIẾNG thay vì đóng dấu
        // bằng một giá trị bịa — im lặng ở đây là ghi sai vào sổ tiền.
        foreach (['unit_price', 'original_unit_price'] as $column) {
            if (! Schema::hasColumn('customer_order_items', $column)) {
                throw new RuntimeException(
                    "customer_order_items.{$column} không tồn tại — không đóng dấu được. ".
                    'Kiểm thứ tự migration trước khi chạy tiếp.'
                );
            }
        }

        $stamped = DB::table('customer_order_items')
            ->whereNull('original_unit_price')
            ->update(['original_unit_price' => DB::raw('unit_price')]);

        // Đây là bước chặn một lượt deploy hỏng, nên nó phải để lại dấu vết
        // đọc được trong log deploy — không phải một migration im lặng.
        echo "  [#2640] đóng dấu original_unit_price cho {$stamped} dòng\n";

        // Fail-closed: nếu còn dòng NULL sau khi đóng dấu (ví dụ `unit_price`
        // cũng NULL ở đâu đó), DỪNG NGAY. Để nó chạy tiếp thì migration omnify
        // kế bên sẽ nổ, và lúc đó ta đã ở giữa một lượt deploy dở dang.
        $remaining = DB::table('customer_order_items')->whereNull('original_unit_price')->count();
        if ($remaining > 0) {
            throw new RuntimeException(
                "Còn {$remaining} dòng `customer_order_items.original_unit_price` NULL sau khi đóng dấu ".
                '— `unit_price` của chúng cũng NULL. Migration NOT NULL kế tiếp sẽ gãy; '.
                'xử lý các dòng này trước khi deploy.'
            );
        }
    }

    /**
     * KHÔNG có `down()` khôi phục.
     *
     * Không thể phân biệt dòng nào vốn NULL với dòng nào có `original_unit_price`
     * bằng `unit_price` một cách hợp lệ (ruling #2132 §B nói đó chính là trạng
     * thái ĐÚNG khi không cơ chế nào hạ giá). Một `down()` set NULL lại sẽ xoá cả
     * dấu vết thật của các dòng ghi sau bản vá này.
     */
    public function down(): void
    {
        // cố ý no-op
    }
};
