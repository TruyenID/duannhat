<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use Database\Seeders\CatalogSnapshotSeeder;
use Illuminate\Support\Facades\DB;

/**
 * #2320 — ảnh chụp nói sao thì DB phải vậy.
 *
 * Bất biến duy nhất bài test này ghim: `CatalogSnapshotSeeder` phân bổ tax type
 * **khớp CHÍNH XÁC** những gì ảnh chụp khai, và một id không ánh xạ được là LỖI
 * chứ không im lặng thành null. Trước bản sửa #2320, mỗi lượt seed san phẳng
 * mọi thứ về STANDARD theo hai đường độc lập: `transformRows()` set
 * `tax_type_id = null` cho mọi hàng, và `JapaneseTaxSeeder` (đã gỡ) `update()`
 * toàn bộ product của brand về STANDARD không kèm `whereNull`.
 *
 * ## Bản gốc của docblock này diễn giải SAI, đừng khôi phục nó
 *
 * Nó viết rằng 13 hàng product gán 軽減税率 8% trong ảnh chụp cũ là "đúng nhóm
 * 軽減税率 hợp pháp", và gọi việc chúng thành 10% là lỗi THU VƯỢT. **Ruling chủ
 * dự án 2026-08-11 nói ngược lại: 8% CHỈ đến từ MENU MANG VỀ; sản phẩm mặc định
 * 10%.** 13 hàng ấy gán 8% thẳng trên `products` là sai ngay từ đầu — để nguyên
 * thì khách ăn TẠI QUÁN cũng chỉ chịu 8%, vì `TaxResolver` cố ý không biết loại
 * đơn (#1099) nên không thể tự phát hiện chỗ này sai.
 *
 * Ảnh chụp `famgia_tempo_20260810` (d18319ace) không còn hàng nào như vậy —
 * đúng chiều. Tiền đề cũ "REDUCED phải > 0" đã gỡ ở 33da4c65e; lý do ghi trong
 * commit đó ("Betoya discontinued every reduced-rate item") **không chính xác**
 * — 5 món vẫn đang bán, chúng chỉ chuyển từ 8% sang 10% cho đúng luật.
 *
 * Nguồn hợp lệ của 8% được ghim riêng ở
 * `tests/Feature/Tax/ReducedRateComesOnlyFromTakeawayMenuTest.php`.
 *
 * Con số đọc thẳng từ fixture chứ không chép tay: fixture đổi thì bài test đi theo.
 */
function snapshotFixture(string $name): array
{
    return json_decode(
        file_get_contents(__DIR__."/../../../database/seeders/fixtures/catalog/{$name}.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/** @return array{brand: Brand, expected: array<string, int>} */
function betoyaTenant(): array
{
    $manifest = snapshotFixture('manifest');
    $source = $manifest['source'];

    $organization = Organization::factory()->create([
        'console_organization_id' => (string) Str::uuid(),
    ]);

    $brand = Brand::factory()->create([
        'slug' => $source['brand_slug'],
        'is_active' => true,
        'console_organization_id' => $organization->console_organization_id,
    ]);

    foreach ($source['branches'] as $slug) {
        Branch::factory()->create([
            'slug' => $slug,
            'is_active' => true,
            'console_organization_id' => $organization->console_organization_id,
            'console_brand_id' => $brand->console_brand_id,
        ]);
    }

    // `Branch::factory()` tự sinh bản dịch tên; ảnh chụp mang bản dịch riêng với
    // id surrogate của DB nguồn, nên hai bên đụng unique (branch_id, locale).
    // Chi nhánh thật đến từ đường đồng bộ Platform và KHÔNG có bản dịch sẵn —
    // xoá ở đây là dựng lại đúng trạng thái đó, không phải né lỗi.
    DB::table('branch_translations')->delete();

    // Đếm kỳ vọng NGAY TỪ fixture: id nguồn → mã, rồi đếm theo mã.
    $codeBySourceId = [];
    foreach (snapshotFixture('tax_types') as $row) {
        $codeBySourceId[$row['id']] = $row['code'];
    }

    // CHỈ đếm hàng còn sống: ảnh chụp mang cả product đã xoá mềm (8/13 hàng
    // 軽減税率), và mọi truy vấn dưới đây đi qua global scope của SoftDeletes.
    $expected = [];
    foreach (snapshotFixture('products') as $row) {
        if (($row['deleted_at'] ?? null) !== null) {
            continue;
        }
        $code = $codeBySourceId[$row['tax_type_id'] ?? ''] ?? 'NULL';
        $expected[$code] = ($expected[$code] ?? 0) + 1;
    }

    return ['brand' => $brand, 'expected' => $expected];
}

it('giữ nguyên 軽減税率 8% mà ảnh chụp gán cho từng món', function () {
    ['brand' => $brand, 'expected' => $expected] = betoyaTenant();

    // KHÔNG còn đòi $expected['REDUCED'] > 0 làm tiền đề — ảnh chụp
    // famgia_tempo_20260810 (d18319ace) xác nhận Betoya đã ngừng bán mọi món
    // 軽減税率 8%, verified trực tiếp trên DB nguồn (#2419): 190/190 sản phẩm
    // còn sống đều STANDARD. Bài test vẫn ghim đúng bất biến — phân bổ
    // REDUCED/STANDARD phải khớp CHÍNH XÁC những gì ảnh chụp nói, dù con số đó
    // là 0 hay 13 — chỉ là fixture hiện tại không còn minh hoạ nhánh REDUCED>0
    // nữa. Nhánh "không silent-null" vẫn ghim riêng ở test dưới.
    (new CatalogSnapshotSeeder)->run();

    $idByCode = TaxType::where('brand_id', $brand->id)->pluck('id', 'code');
    expect($idByCode)->toHaveCount(3);

    $actualReduced = Product::where('brand_id', $brand->id)
        ->where('tax_type_id', $idByCode['REDUCED'])
        ->count();
    $actualStandard = Product::where('brand_id', $brand->id)
        ->where('tax_type_id', $idByCode['STANDARD'])
        ->count();

    expect($actualReduced)->toBe($expected['REDUCED'] ?? 0)
        ->and($actualStandard)->toBe($expected['STANDARD'] ?? 0)
        // Không món nào bị bỏ lại không thuế.
        ->and(Product::where('brand_id', $brand->id)->whereNull('tax_type_id')->count())->toBe(0);
});

it('giữ nguyên loại thuế mặc định mà ảnh chụp gán cho chi nhánh', function () {
    ['brand' => $brand] = betoyaTenant();

    $codeBySourceId = [];
    foreach (snapshotFixture('tax_types') as $row) {
        $codeBySourceId[$row['id']] = $row['code'];
    }

    $expectedReducedBranches = 0;
    foreach (snapshotFixture('shop_order_settings') as $row) {
        if (($codeBySourceId[$row['default_tax_type_id'] ?? ''] ?? null) === 'REDUCED') {
            $expectedReducedBranches++;
        }
    }
    // KHÔNG còn đòi $expectedReducedBranches > 0 — cùng lý do ở test phía
    // trên (#2419): ảnh chụp famgia_tempo_20260810 hiện tại không còn chi
    // nhánh nào lấy REDUCED làm mặc định. Assert dưới vẫn ghim đúng số đếm
    // khớp ảnh chụp, dù số đó là 0.

    (new CatalogSnapshotSeeder)->run();

    $reducedId = TaxType::where('brand_id', $brand->id)->where('code', 'REDUCED')->value('id');

    expect(ShopOrderSetting::where('default_tax_type_id', $reducedId)->count())
        ->toBe($expectedReducedBranches);
});

it('seed hai lần liên tiếp không san phẳng dấu thuế', function () {
    ['brand' => $brand, 'expected' => $expected] = betoyaTenant();

    (new CatalogSnapshotSeeder)->run();
    (new CatalogSnapshotSeeder)->run();

    $reducedId = TaxType::where('brand_id', $brand->id)->where('code', 'REDUCED')->value('id');

    expect(Product::where('brand_id', $brand->id)->where('tax_type_id', $reducedId)->count())
        ->toBe($expected['REDUCED'] ?? 0)
        ->and(TaxType::where('brand_id', $brand->id)->count())->toBe(3);
});

it('một id thuế lạ trong ảnh chụp là LỖI, không phải null im lặng', function () {
    ['brand' => $brand] = betoyaTenant();
    (new CatalogSnapshotSeeder)->run();

    // Dựng lại đúng trạng thái mà một dump MỚI (mang loại thuế riêng của brand,
    // chưa có trong tax_types.json) sẽ tạo ra khi đi qua transformRows.
    $seeder = new CatalogSnapshotSeeder;
    $reflection = new ReflectionClass($seeder);

    $map = $reflection->getProperty('taxTypeMap');
    $map->setValue($seeder, $reflection->getMethod('buildTaxTypeMap')->invoke($seeder, $brand));

    $remap = $reflection->getMethod('remapTaxTypeId');

    // Id đã biết thì ánh xạ được.
    $known = snapshotFixture('tax_types')[0]['id'];
    expect($remap->invoke($seeder, $known, 'products', 'tax_type_id'))->not->toBeNull();

    // Id lạ thì DỪNG — bản cũ ghi null ở đúng chỗ này và đánh mất 8% của 13 món.
    expect(fn () => $remap->invoke($seeder, '11111111-2222-3333-4444-555555555555', 'products', 'tax_type_id'))
        ->toThrow(RuntimeException::class);

    // null vẫn là null (product không gán gì) — không phải lỗi.
    expect($remap->invoke($seeder, null, 'products', 'tax_type_id'))->toBeNull();
});

it('mọi loại thuế trong ảnh chụp đều được tax_types.json mô tả', function () {
    $known = array_column(snapshotFixture('tax_types'), 'id');

    $referenced = [];
    foreach (['products', 'menu_products', 'shop_order_settings'] as $table) {
        foreach (snapshotFixture($table) as $row) {
            foreach (['tax_type_id', 'default_tax_type_id'] as $column) {
                $value = $row[$column] ?? null;
                if ($value !== null && $value !== '') {
                    $referenced[$value] = $table;
                }
            }
        }
    }

    $unknown = array_diff(array_keys($referenced), $known);

    expect($unknown)->toBe([], 'tax_types.json thiếu mô tả cho: '.implode(', ', $unknown));
});
