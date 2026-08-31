<?php

use Database\Seeders\PostSeeder;
use Illuminate\Database\Eloquent\Model;

/**
 * Bản dịch do seeder ghi phải SỐNG qua `Model::withoutEvents()`.
 *
 * ## Vì sao đây là một bất biến chứ không phải chuyện dữ liệu demo
 *
 * `DatabaseSeeder` `use WithoutModelEvents` (`DatabaseSeeder.php:10`), nên cả
 * lượt `migrate:fresh --seed` chạy trong `Model::withoutEvents()`. Astrotomic
 * bền hoá bản dịch trong hook `static::saved(...)`, nên mọi cách ghi giao việc
 * cho hook cha đều bốc hơi **lặng lẽ** — không lỗi, không cảnh báo.
 *
 * Nó đã hai lần đi tới chỗ **quán không thu được tiền**:
 *
 *   1. `payment_gateway_option_translations` rỗng ⇒ thu ngân thấy
 *      `internal.cash.v1` thay vì "Tiền mặt (sổ nội bộ)" (#2470 mục 4).
 *   2. Bản dịch rỗng ⇒ trường i18n phát ra `[]` ⇒ máy trạm giải mã vào
 *      `map[string]string` thất bại ⇒ MỘT trường rỗng giết cả lượt giải mã
 *      feed. Đã xảy ra ở hai feed khác nhau (#2470 mục 3, và `name_i18n` của
 *      till-tender-types).
 *
 * ## Vì sao đo HÀNH VI chứ không đọc hình dạng mã
 *
 * Hai kiểu ghi trông gần như nhau và chỉ một kiểu sống — đã đo trực tiếp:
 *
 *   A. `$m->translateOrNew('ja')->name = 'x'; $m->save();`          → **0 hàng**
 *   B. `$t = $m->translateOrNew('ja'); $t->name = 'x'; $t->save();` → 1 hàng
 *
 * Khác biệt nằm ở chỗ `save()` được gọi trên CÁI GÌ, và một rào đọc mã sẽ phải
 * đoán điều đó qua regex. Chạy seeder thật rồi đếm hàng thì không phải đoán.
 */
uses()->group('architecture');

it('PostSeeder giữ được bản dịch khi chạy trong withoutEvents', function () {
    Model::withoutEvents(fn () => $this->seed(PostSeeder::class));

    // Ba bảng, ba mức lồng nhau (category → tag → post): trước khi vá cả ba đều
    // về 0, nên một bảng thôi không đủ để nói bản vá phủ hết.
    expect(DB::table('post_category_translations')->count())->toBeGreaterThan(0)
        ->and(DB::table('post_tag_translations')->count())->toBeGreaterThan(0)
        ->and(DB::table('post_translations')->count())->toBeGreaterThan(0);
});

it('bản dịch có NỘI DUNG thật, không phải hàng rỗng', function () {
    // Đếm hàng thôi thì một bản vá ghi ra hàng trắng vẫn xanh — mà hàng trắng
    // chính là thứ `translationsOf()` lọc bỏ, nên nó quay lại đúng `[]` đã giết
    // feed. Số hàng không phải thứ cần bảo vệ; nội dung mới là.
    Model::withoutEvents(fn () => $this->seed(PostSeeder::class));

    $empty = DB::table('post_category_translations')
        ->where(fn ($q) => $q->whereNull('name')->orWhere('name', ''))
        ->count();

    expect($empty)->toBe(0);
    expect(DB::table('post_category_translations')->distinct()->pluck('locale')->sort()->values()->all())
        ->toBe(['en', 'ja', 'vi']);
});

it('KHÔNG nhân đôi hàng khi seeder chạy lại — cổng chỉ có nghĩa nếu idempotent', function () {
    // `migrate --force` + `db:seed` chạy MỖI lần deploy production, nên một
    // seeder ghi thêm hàng mỗi lượt sẽ âm thầm phình bảng dịch trên prod.
    Model::withoutEvents(fn () => $this->seed(PostSeeder::class));
    $first = DB::table('post_category_translations')->count();

    Model::withoutEvents(fn () => $this->seed(PostSeeder::class));

    expect(DB::table('post_category_translations')->count())->toBe($first);
});

/**
 * Bánh cóc trên số chỗ dùng `translateOrNew` trong seeder.
 *
 * Rào hành vi ở trên chỉ phủ những seeder nó gọi tên. Bánh cóc này phủ phần còn
 * lại theo chiều ngược: một chỗ dùng MỚI xuất hiện thì đỏ, và người thêm phải
 * chứng minh nó thuộc kiểu B — hoặc dùng `WritesTranslations`, đường đã được đo
 * là sống.
 *
 * Hai chỗ còn trong danh sách đều là kiểu B (`$trans->save()` trực tiếp) và đều
 * ĐÚNG. Chúng ở lại thay vì bị đổi sang trait vì cả hai mang thêm một luật riêng
 * — `if (empty($trans->name))`, tức CHỈ ĐIỀN KHI CÒN TRỐNG. `writeTranslations()`
 * ghi đè, nên đổi chúng sang trait là thay đổi hành vi núp dưới một lần dọn dẹp.
 *
 * Chỗ thứ ba (`LocalDevSeeder`) không mang luật đó — nó đồng bộ vô điều kiện,
 * tức đúng khuôn của trait — nên đã chuyển.
 */
it('không có chỗ dùng translateOrNew MỚI trong seeder', function () {
    $counts = [];
    foreach (glob(database_path('seeders/*.php')) as $file) {
        $n = substr_count((string) file_get_contents($file), 'translateOrNew(');
        if ($n > 0) {
            $counts[basename($file)] = $n;
        }
    }
    ksort($counts);

    expect($counts)->toBe([
        // Cả hai là kiểu B + luật "chỉ điền khi trống" — xem docblock trên.
        'CategoriesPerBrandSeeder.php' => 1,
        'DashboardSeeder.php' => 1,
    ], "Danh sách chỗ dùng `translateOrNew` trong seeder đã đổi.\n"
        ."• Chỗ MỚI: dùng `Concerns\\WritesTranslations` thay vì thêm vào đây.\n"
        ."  Đặt trường rồi `\$parent->save()` sẽ mất sạch bản dịch dưới\n"
        ."  `withoutEvents`, mà `DatabaseSeeder` luôn chạy trong đó.\n"
        .'• Số GIẢM: tốt — cập nhật danh sách cho khớp.');
});
