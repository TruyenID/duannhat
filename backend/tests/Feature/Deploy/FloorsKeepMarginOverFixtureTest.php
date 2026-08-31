<?php

declare(strict_types=1);

/**
 * #2568 — sàn của `deploy:verify-production-seed` phải cách xa số hàng FIXTURE.
 *
 * ## Cơ chế đã sinh ra hai sự cố deploy, không phải con số cụ thể
 *
 * Người viết sàn mở `database/seeders/fixtures/catalog/`, đếm, rồi ghim con số
 * đó. Nó đọc lên rất hợp lý — "ảnh chụp có 419 sản phẩm nên production phải có
 * ít nhất chừng ấy" — và nó SAI ở đúng một chỗ: **fixture không phải
 * production**. Fixture có 42 zone, production có 36; fixture mang zone
 * `TRUYEN` do người tạo tay hôm 2026-07-18 mà production chưa bao giờ có.
 *
 * Hậu quả đo được ngày 2026-08-12: `MIN_ZONES = 4` (đúng bằng số zone 人形町
 * trong fixture) làm deploy production đỏ ngay lượt đầu tiên nó chạy — và đỏ
 * NỬA CHỪNG, vì bước verify nằm sau `migrate` + `seed` nên phần đồng bộ authz
 * sang Platform không chạy. `MIN_MENU_PRODUCTS = 99` (đúng bằng fixture) còn
 * chưa nổ chỉ vì chưa ai gỡ món nào khỏi menu.
 *
 * Nên rào ở đây canh **khoảng cách**, không canh giá trị: một sàn được phép là
 * bất cứ số nào, miễn nó đủ thấp dưới ảnh chụp để nghiệp vụ bình thường —
 * thêm/bớt món, đóng một quán — không bao giờ chạm tới.
 *
 * ## Vì sao 60%
 *
 * Restore hỏng để lại catalog gần 0; nghiệp vụ không giảm một nửa trong một
 * lần. Ngưỡng đặt ở 60% cho mỗi sàn thoải mái ngồi quanh mốc "một nửa" mà
 * không phải chỉnh rào mỗi lần ai đó làm tròn khác đi vài đơn vị.
 *
 * ## Rào này KHÔNG nói production có bao nhiêu
 *
 * Test không với tới production được. Nó chỉ chặn cơ chế chép-từ-fixture. Số
 * đo production thật, kèm ngày đo, nằm trong docblock của chính các hằng số —
 * đó mới là chỗ hiệu chỉnh chúng.
 */

use App\Console\Commands\Deploy\VerifyProductionSeedCommand;
use App\Models\Brand;

uses()->group('deploy');

/** Sàn ⇒ cách đếm số hàng tương ứng trong fixture. */
function fixtureCountFor(string $constant): int
{
    $dir = database_path('seeders/fixtures/catalog');
    $rows = static fn (string $file): array => json_decode(
        (string) file_get_contents("{$dir}/{$file}"), true, 512, JSON_THROW_ON_ERROR
    );

    return match ($constant) {
        'MIN_BRANCHES' => count($rows('branches.json')),
        'MIN_PRODUCTS' => count($rows('products.json')),
        'MIN_FILES' => count($rows('files.json')),
        // Cùng lát cắt mà lệnh đếm: một menu, chỉ dòng còn sống.
        'MIN_MENU_PRODUCTS' => count(array_filter(
            $rows('menu_products.json'),
            static fn (array $r): bool => ($r['menu_id'] ?? null) === '019f6efa-2f83-71a8-b061-2c8f9435718a'
                && ($r['deleted_at'] ?? null) === null,
        )),
        default => throw new RuntimeException(
            "Sàn `{$constant}` chưa khai cách đếm fixture trong bài test này. ".
            'Thêm một sàn thì phải thêm luôn ánh xạ ở đây — nếu không rào #2568 '.
            'im lặng bỏ qua đúng cái sàn mới, và cơ chế chép-từ-fixture quay lại.'
        ),
    };
}

it('mọi sàn đều nằm đủ thấp dưới số hàng fixture', function () {
    $constants = collect((new ReflectionClass(VerifyProductionSeedCommand::class))->getConstants())
        ->filter(fn (mixed $v, string $k): bool => str_starts_with($k, 'MIN_'));

    expect($constants)->not->toBeEmpty('Không tìm thấy sàn nào — bộ dò hỏng, và bài này thành vô nghĩa.');

    foreach ($constants as $name => $floor) {
        $fixture = fixtureCountFor($name);
        $ratio = $floor / $fixture;

        expect($ratio)->toBeLessThanOrEqual(0.6, sprintf(
            "%s = %d, mà fixture có %d hàng (%.0f%%).\n".
            "Sàn bám sát ảnh chụp là cơ chế đã làm deploy production đỏ hai lần (#2542, #2568):\n".
            "fixture KHÔNG phải production — nó có cả hàng người tạo tay để thử.\n".
            'Hiệu chỉnh từ số đo production thật (xem docblock của hằng số), rồi lấy khoảng một nửa.',
            $name, $floor, $fixture, $ratio * 100,
        ));
    }
});

it('vẫn bắt được ảnh chụp rỗng — sàn thấp KHÔNG phải sàn vô dụng', function () {
    // Nới sàn xuống một nửa chỉ có nghĩa nếu cái nó canh vẫn còn. DB test rỗng
    // là đúng hình dạng của một restore hỏng, và gate phải ném.
    //
    // Phải đi qua `--after-restore` (#2574): chạy trần thì lệnh không đếm gì
    // nữa, nên một bài gọi trần vẫn xanh — nhưng xanh nhờ `brands === 1` ném,
    // KHÔNG phải nhờ sàn. Bài đó đọc lên như đang canh sàn trong khi không.
    Brand::factory()->create(['slug' => 'betoya']);

    $this->artisan('deploy:verify-production-seed', ['--after-restore' => true]);
})->throws(RuntimeException::class);
