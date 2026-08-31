<?php

declare(strict_types=1);

use App\Models\CustomerOrderItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #2640 — bước đóng dấu `original_unit_price` phải chạy TRƯỚC migration omnify
 * khoá cột thành NOT NULL.
 *
 * Bài chính ở đây là **THỨ TỰ**, không phải phép UPDATE. Phép UPDATE thì đọc là
 * hiểu; thứ tự thì hỏng im lặng, và hỏng ở nơi không ai nhìn: tài liệu omnify
 * nói rõ *"Stable-timestamp ALTERs are shifted by (LockVersion+1) days"*, nên
 * một lượt `omnify generate` trong tương lai CÓ THỂ dời
 * `2000_01_08_000000_alter_customer_order_items_table` sang ngày khác — và nếu
 * nó rơi ra TRƯỚC `2000_01_07_999999`, bước đóng dấu thành vô dụng còn deploy
 * production thì gãy, y như tình trạng bản vá này sinh ra để chặn.
 *
 * Laravel sắp migration theo TÊN FILE trên toàn bộ các thư mục đã đăng ký
 * (`database/migrations` + `database/migrations/omnify`), nên bài này so đúng
 * thứ mà runtime dùng.
 */
const STAMP_MIGRATION = '2000_01_07_999999_manual_migration_stamp_original_unit_price.php';
const NOT_NULL_MIGRATION = '2000_01_08_000000_alter_customer_order_items_table.php';

/** Danh sách migration đúng thứ tự Laravel sẽ chạy. */
function migrationOrder(): array
{
    $files = array_merge(
        glob(database_path('migrations/*.php')) ?: [],
        glob(database_path('migrations/omnify/*.php')) ?: [],
    );
    $names = array_map('basename', $files);
    sort($names, SORT_STRING);

    return $names;
}

it('bước đóng dấu chạy TRƯỚC migration khoá NOT NULL', function () {
    $order = migrationOrder();

    $stamp = array_search(STAMP_MIGRATION, $order, true);
    $lock = array_search(NOT_NULL_MIGRATION, $order, true);

    expect($stamp)->not->toBeFalse(
        'Không tìm thấy migration đóng dấu — nó bị đổi tên hoặc bị xoá. '.
        'Không có nó thì `ALTER … NOT NULL` gãy trên mọi DB còn dòng NULL.'
    );
    expect($lock)->not->toBeFalse(
        'Không tìm thấy migration khoá NOT NULL. Nếu omnify vừa đổi tên file '.
        '(tài liệu: ALTER bị dời (LockVersion+1) ngày), cập nhật hằng số ở '.
        'đầu file này VÀ kiểm lại thứ tự — đừng chỉ sửa cho test xanh.'
    );
    expect($stamp)->toBeLessThan($lock, sprintf(
        "Migration đóng dấu (%s) phải sắp TRƯỚC migration khoá NOT NULL (%s).\n".
        "Hiện tại: đóng dấu ở %d, khoá ở %d.\n".
        'Nguyên nhân thường gặp: một lượt `omnify generate` dời dấu thời gian của '.
        'file ALTER. Đổi tên file đóng dấu cho nhỏ hơn — ĐỪNG gỡ bài test này.',
        STAMP_MIGRATION, NOT_NULL_MIGRATION, $stamp, $lock,
    ));
});

/**
 * Migration đóng dấu KHÔNG được nằm trong `migrations/omnify/`.
 *
 * `omnify generate --force` xoá sạch thư mục đó rồi dựng lại từ YAML. Một bước
 * đóng dấu đặt nhầm vào đấy sẽ biến mất ở lượt regen kế, và không ai để ý cho
 * tới lượt deploy hỏng.
 */
it('bước đóng dấu nằm NGOÀI thư mục omnify', function () {
    expect(file_exists(database_path('migrations/'.STAMP_MIGRATION)))->toBeTrue()
        ->and(file_exists(database_path('migrations/omnify/'.STAMP_MIGRATION)))->toBeFalse();
});

/**
 * Sau khi cả hai migration đã chạy, bất biến phải đúng: cột NOT NULL và không
 * còn dòng nào NULL. Đây là hình dạng production sẽ có sau deploy.
 */
it('sau migration: cột có mặt và không dòng nào NULL', function () {
    expect(Schema::hasColumn('customer_order_items', 'original_unit_price'))->toBeTrue()
        ->and(Schema::hasColumn('customer_order_items', 'unit_price'))->toBeTrue()
        ->and(DB::table('customer_order_items')->whereNull('original_unit_price')->count())->toBe(0);
});

/** Chạy lại trên cây đã sạch là no-op — không ném, không đổi số dòng. */
it('chạy lại được, không nổ', function () {
    $migration = require database_path('migrations/'.STAMP_MIGRATION);
    $before = DB::table('customer_order_items')->count();

    $migration->up();
    $migration->up();

    expect(DB::table('customer_order_items')->count())->toBe($before)
        ->and(DB::table('customer_order_items')->whereNull('original_unit_price')->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────────────
//  #2649 — chuỗi ĐÓNG DẤU → KHOÁ NOT NULL, chạy với dữ liệu NULL THẬT
//
//  Hai bài ở trên đo THỨ TỰ và chỗ ĐẶT file; hai bài kia (`không dòng nào NULL`,
//  `chạy lại được`) chạy trên bảng RỖNG vì CI luôn `migrate:fresh` — chúng đúng
//  một cách rỗng nghĩa: câu UPDATE chạm 0 dòng, và rào fail-closed chưa từng nổ
//  thử. Tức hình dạng production (bảng CÓ dòng NULL) chỉ chạy lần đầu trên
//  chính production.
//
//  Ba bài dưới dựng lại hình dạng đó trong test: hạ cột về nullable như TRƯỚC
//  #2617, chèn dòng NULL, rồi chạy migration thật.
// ─────────────────────────────────────────────────────────────────────────────

/** Hạ một cột của `customer_order_items` về nullable — trạng thái trước #2617. */
function relaxColumnToNullable(string $column): void
{
    Schema::table('customer_order_items', function (Blueprint $table) use ($column) {
        $table->decimal($column, 15, 2)->nullable()->change();
    });
}

it('đóng dấu dòng NULL bằng unit_price, rồi migration NOT NULL chạy được', function () {
    relaxColumnToNullable('original_unit_price');

    $item = CustomerOrderItem::factory()->create(['unit_price' => 1234.00]);
    DB::table('customer_order_items')->where('id', $item->id)->update(['original_unit_price' => null]);

    // Tiền đề: dòng NULL có thật. Không có nó thì bài này rỗng nghĩa y như hai
    // bài cũ, nên khẳng định trước khi đo.
    expect(DB::table('customer_order_items')->whereNull('original_unit_price')->count())->toBe(1);

    $before = DB::table('customer_order_items')->count();
    (require database_path('migrations/'.STAMP_MIGRATION))->up();

    $stamped = DB::table('customer_order_items')->where('id', $item->id)->first();
    expect((float) $stamped->original_unit_price)->toBe(1234.00)
        ->and(DB::table('customer_order_items')->count())->toBe($before);

    // Và cái đích thật sự của bước đóng dấu: ALTER kế tiếp không được ném.
    (require database_path('migrations/omnify/'.NOT_NULL_MIGRATION))->up();

    expect(DB::table('customer_order_items')->whereNull('original_unit_price')->count())->toBe(0);
});

it('ném khi còn dòng NULL sau khi đóng dấu — rào fail-closed', function () {
    // `unit_price` cũng NULL là cách DUY NHẤT một dòng sống sót qua câu UPDATE,
    // và đó đúng là ca mà rào sinh ra để chặn: đóng dấu bằng NULL rồi để ALTER
    // nổ giữa lượt deploy.
    relaxColumnToNullable('original_unit_price');
    relaxColumnToNullable('unit_price');

    $item = CustomerOrderItem::factory()->create();
    DB::table('customer_order_items')->where('id', $item->id)
        ->update(['original_unit_price' => null, 'unit_price' => null]);

    expect(fn () => (require database_path('migrations/'.STAMP_MIGRATION))->up())
        ->toThrow(RuntimeException::class, 'Còn 1 dòng');
});

it('không đụng dòng đã có original_unit_price', function () {
    relaxColumnToNullable('original_unit_price');

    $untouched = CustomerOrderItem::factory()->create([
        'unit_price' => 500.00,
        'original_unit_price' => 900.00,   // dòng khuyến mãi: strikethrough thật
    ]);
    $nulled = CustomerOrderItem::factory()->create(['unit_price' => 700.00]);
    DB::table('customer_order_items')->where('id', $nulled->id)->update(['original_unit_price' => null]);

    (require database_path('migrations/'.STAMP_MIGRATION))->up();

    expect((float) DB::table('customer_order_items')->where('id', $untouched->id)->value('original_unit_price'))->toBe(900.00)
        ->and((float) DB::table('customer_order_items')->where('id', $nulled->id)->value('original_unit_price'))->toBe(700.00);
});
