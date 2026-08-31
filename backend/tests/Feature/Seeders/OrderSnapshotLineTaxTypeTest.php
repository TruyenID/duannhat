<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\TaxType;
use Database\Seeders\CatalogSnapshotSeeder;
use Database\Seeders\OrderSnapshotSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * #2430 — `OrderSnapshotSeeder` dán nhãn thuế cho dòng đơn theo RATE CỦA CHÍNH
 * DÒNG ĐÓ, không phải theo id thuế production.
 *
 * Bản cũ map id-thuế-production → id-local bằng rate của dòng ĐẦU TIÊN mang id
 * đó (`$rateByTaxId[$id] ??= $row['tax_rate']`). Trong fixture hiện tại, 6 dòng
 * mang id REDUCED cũ và dòng đầu tiên có `tax_rate = 10.00`, nên cả 6 — kể cả
 * 5 dòng snapshot 8% — bị dán nhãn STANDARD.
 *
 * Tiền không sai (rate/amount là snapshot bất biến trên chính dòng đơn), nhưng
 * nhãn thì sai — và dữ liệu dev/QA sinh ra từ đây chính là thứ người ta dùng để
 * mắt-thường soát báo cáo thuế. Theo ruling 2026-08-11, 5 dòng 8% đó là hàng
 * MANG VỀ hợp lệ, nên nhãn của chúng phải là REDUCED.
 */

/** Kỳ vọng đọc THẲNG từ fixture — fixture đổi thì bài test đi theo. */
function orderLineFixture(): array
{
    return json_decode(
        (string) file_get_contents(__DIR__.'/../../../database/seeders/fixtures/orders/customer_order_items.json'),
        true, flags: JSON_THROW_ON_ERROR,
    );
}

function seedBetoyaCatalogAndOrders(): Brand
{
    $manifest = json_decode(
        (string) file_get_contents(__DIR__.'/../../../database/seeders/fixtures/catalog/manifest.json'),
        true, flags: JSON_THROW_ON_ERROR,
    );
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
    DB::table('branch_translations')->delete();

    (new CatalogSnapshotSeeder)->run();
    (new OrderSnapshotSeeder)->run();

    return $brand;
}

it('gán REDUCED cho ĐÚNG những dòng đơn snapshot 8%, không san phẳng về STANDARD', function () {
    // Tiền đề: fixture phải có CẢ HAI rate, nếu không bài test chẳng ghim gì.
    $inFixture = [];
    foreach (orderLineFixture() as $row) {
        $inFixture[number_format((float) $row['tax_rate'], 2, '.', '')] ??= 0;
        $inFixture[number_format((float) $row['tax_rate'], 2, '.', '')]++;
    }
    expect($inFixture)->toHaveKeys(['8.00', '10.00'])
        ->and($inFixture['8.00'])->toBeGreaterThan(0);

    $brand = seedBetoyaCatalogAndOrders();

    // Kỳ vọng tính trên những dòng THỰC SỰ vào được, không phải fixture thô:
    // #2440 cố ý BỎ hàng trỏ vào chi nhánh/bàn mà hệ này không có (ảnh chụp đơn
    // và ảnh chụp catalog lệch một nhịp dump). Đếm theo fixture thô sẽ biến một
    // chính sách có chủ đích thành một bài test đỏ.
    $seededOrderIds = DB::table('customer_orders')->pluck('id')->flip();
    $expected = [];
    foreach (orderLineFixture() as $row) {
        if (! $seededOrderIds->has((string) $row['customer_order_id'])) {
            continue;
        }
        $expected[number_format((float) $row['tax_rate'], 2, '.', '')] ??= 0;
        $expected[number_format((float) $row['tax_rate'], 2, '.', '')]++;
    }
    expect($expected['8.00'] ?? 0)->toBeGreaterThan(0, 'không còn dòng 8% nào sống sót — bài test hết ghim');

    $idByCode = TaxType::where('brand_id', $brand->id)->pluck('id', 'code');

    $actual = DB::table('customer_order_items')
        ->selectRaw('tax_type_id, COUNT(*) as n')
        ->groupBy('tax_type_id')
        ->pluck('n', 'tax_type_id');

    expect((int) ($actual[$idByCode['REDUCED']] ?? 0))->toBe($expected['8.00'])
        ->and((int) ($actual[$idByCode['STANDARD']] ?? 0))->toBe($expected['10.00']);
});

it('mỗi dòng đơn mang tax_type_id có rate KHỚP ĐÚNG tax_rate đã snapshot', function () {
    // Bất biến chặt hơn phép đếm ở trên: không dòng nào được mang nhãn của một
    // rate khác. Đây là thứ mà cách map theo-id không bao giờ bảo đảm được.
    seedBetoyaCatalogAndOrders();

    $mismatched = DB::table('customer_order_items as i')
        ->join('tax_types as t', 't.id', '=', 'i.tax_type_id')
        ->whereRaw('CAST(t.rate AS DECIMAL(5,2)) <> CAST(i.tax_rate AS DECIMAL(5,2))')
        ->count();

    expect($mismatched)->toBe(0);
});

it('không dòng đơn nào giữ lại id thuế production — FK RESTRICT sang tax_types phải khớp', function () {
    seedBetoyaCatalogAndOrders();

    $dangling = DB::table('customer_order_items as i')
        ->whereNotNull('i.tax_type_id')
        ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
            ->from('tax_types as t')
            ->whereColumn('t.id', 'i.tax_type_id'))
        ->count();

    expect($dangling)->toBe(0);
});

it('rate không có tax_type tương ứng là LỖI CỨNG, không phải im lặng gán bừa', function () {
    // Luật của docs/guide/tenant-provisioning.md: "Một id không ánh xạ được là
    // LỖI, không phải null". Một rate không ánh xạ được cũng vậy — nếu không,
    // seeder lại quay về đúng chỗ nó vừa thoát ra: chọn đại một type rồi đi tiếp.
    $manifest = json_decode(
        (string) file_get_contents(__DIR__.'/../../../database/seeders/fixtures/catalog/manifest.json'),
        true, flags: JSON_THROW_ON_ERROR,
    );
    $source = $manifest['source'];

    $organization = Organization::factory()->create(['console_organization_id' => (string) Str::uuid()]);
    $brand = Brand::factory()->create([
        'slug' => $source['brand_slug'], 'is_active' => true,
        'console_organization_id' => $organization->console_organization_id,
    ]);
    foreach ($source['branches'] as $slug) {
        Branch::factory()->create([
            'slug' => $slug, 'is_active' => true,
            'console_organization_id' => $organization->console_organization_id,
            'console_brand_id' => $brand->console_brand_id,
        ]);
    }
    DB::table('branch_translations')->delete();
    (new CatalogSnapshotSeeder)->run();

    // Gỡ đúng loại 8% đi: giờ 5 dòng mang về không còn chỗ nào để trỏ tới.
    TaxType::where('brand_id', $brand->id)->where('code', 'REDUCED')->delete();

    expect(fn () => (new OrderSnapshotSeeder)->run())
        ->toThrow(RuntimeException::class, 'tax_rate');
});
