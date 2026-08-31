<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use Database\Seeders\CatalogSnapshotSeeder;
use Database\Seeders\OrderSnapshotSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * #2472 — ba cột tiền của ảnh chụp phải tới được `order_conditions`.
 *
 * ## Lỗi mà bài này ghim
 *
 * #2041 chuyển `discount_amount` · `service_charge` · `tax_amount` từ CỘT của
 * `customer_orders` sang sổ `order_conditions`. `OrderSnapshotSeeder` lọc cột
 * theo schema đích, nên ba cột ấy LUÔN rơi vào nhánh "bỏ cột không có trên DB
 * này" — và với ba cột NÀY, bỏ đi nghĩa là **vứt tiền**: 40/52 đơn ảnh chụp khai
 * tổng ¥6.925 thuế, nạp xong đọc ra 0 trong khi `total_amount` vẫn gồm phần thuế
 * đó. `subtotal − discount + service + tax` không còn bằng `total`, nên dòng
 * 端数調整 của pos-web hiện `+¥225` trên một đơn chẳng có gì để làm tròn.
 *
 * ## Hai bất biến, cả hai đều là "thà mất chi tiết còn hơn sổ sai tổng"
 *
 * 1. **Chỉ thêm, không đè.** Trên DB đang sống, sổ do `writeConditions` ghi giàu
 *    hơn (một dòng mỗi mức kèm `taxable_base`). Thay nó bằng một dòng phẳng của
 *    ảnh chụp cũ là đi lùi.
 * 2. **Tách theo mức chỉ khi Σ khớp header.** Ảnh chụp hiện tại có phần lớn đơn
 *    lệch giữa Σ thuế theo dòng và `tax_amount` đầu đơn (đo được: 27 phẳng / 13
 *    tách), nên nhánh phẳng là đường CHÍNH chứ không phải ngoại lệ.
 *
 * Hai bài dưới cố ý gộp nhiều khẳng định: dựng thế giới cho seeder này cần cả
 * `CatalogSnapshotSeeder`, nên tách nhỏ ra sáu bài là trả giá seed nặng sáu lần.
 */

/**
 * Dựng thế giới tối thiểu mà `OrderSnapshotSeeder` cần rồi chạy nó.
 *
 * Tên riêng, không dùng chung với `OrderSnapshotLineTaxTypeTest`: helper của
 * Pest là hàm TOÀN CỤC, hai file cùng đặt một tên là lỗi "cannot redeclare" ở
 * tận lượt nạp — không phải một bài test đỏ.
 */
function seedSnapshotConditionLedgerWorld(): void
{
    $manifest = json_decode(
        (string) file_get_contents(database_path('seeders/fixtures/catalog/manifest.json')),
        true, flags: JSON_THROW_ON_ERROR,
    );
    $source = $manifest['source'];

    $organization = Organization::factory()->create(['console_organization_id' => (string) Str::uuid()]);
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
}

/** @return array<string, float> id đơn ⇒ tax_amount, đọc THẲNG từ fixture */
function snapshotHeaderTax(): array
{
    $rows = json_decode(
        (string) file_get_contents(database_path('seeders/fixtures/orders/customer_orders.json')),
        true, flags: JSON_THROW_ON_ERROR,
    );

    $out = [];
    foreach ($rows as $row) {
        if ((float) ($row['tax_amount'] ?? 0) !== 0.0) {
            $out[(string) $row['id']] = round((float) $row['tax_amount'], 2);
        }
    }

    return $out;
}

it('#2472 — sổ cộng ra ĐÚNG bằng header của ảnh chụp, từng đơn một', function () {
    $expected = snapshotHeaderTax();
    expect($expected)->not->toBeEmpty('fixture không còn đơn nào có thuế — bài này hết canh gì');

    seedSnapshotConditionLedgerWorld();

    // Chỉ đối chiếu những đơn THỰC SỰ vào được: #2440 cố ý bỏ hàng trỏ vào chi
    // nhánh/bàn mà hệ này không có (ảnh chụp đơn lệch một nhịp dump với ảnh chụp
    // catalog). Đếm theo fixture thô sẽ biến một chính sách có chủ đích thành
    // một bài test đỏ.
    $seeded = DB::table('customer_orders')->pluck('id')->map(strval(...))->flip();
    $expected = array_filter($expected, fn (float $v, string $id): bool => $seeded->has($id), ARRAY_FILTER_USE_BOTH);
    expect($expected)->not->toBeEmpty('không đơn có thuế nào sống sót — bài test hết ghim');

    $actual = DB::table('order_conditions')
        ->where('type', 'tax')
        ->whereIn('conditionable_id', array_keys($expected))
        ->groupBy('conditionable_id')
        ->selectRaw('conditionable_id, ROUND(SUM(amount), 2) AS total')
        ->pluck('total', 'conditionable_id');

    $mismatched = [];
    foreach ($expected as $id => $want) {
        $got = (float) ($actual[$id] ?? 0);
        if (abs($want - $got) >= 0.005) {
            $mismatched[$id] = "header {$want} ≠ sổ {$got}";
        }
    }

    expect($mismatched)->toBe([], 'sổ không cộng ra bằng header của ảnh chụp')
        ->and($actual)->toHaveCount(count($expected), 'có đơn mang tiền mà không có dòng sổ nào');

    // Dấu + hình dạng: `writeConditions()` khai Σ(discount) == −discount_amount,
    // còn tax/service_charge dương. Sai dấu một loại là `total = subtotal + Σ` gãy.
    foreach (DB::table('order_conditions')->where('source', 'snapshot')->get() as $row) {
        $meta = json_decode((string) $row->meta, true) ?: [];
        expect($meta)->toHaveKey('seeded_from');

        if ($row->type === 'discount') {
            expect((float) $row->amount)->toBeLessThanOrEqual(0.0);
        } else {
            expect((float) $row->amount)->toBeGreaterThanOrEqual(0.0);
        }

        if ($row->type !== 'tax') {
            continue;
        }

        // Dòng phẳng phải NÓI RÕ vì sao không tách được, nếu không người đọc sổ
        // sẽ tưởng đơn đó chỉ có một mức thuế.
        expect($meta)->toHaveKey($row->rate === null ? 'unsplit_reason' : 'rate_group');
    }
});

it('#2472 — chạy seeder lần hai KHÔNG nhân đôi sổ (rào "chỉ thêm, không đè")', function () {
    seedSnapshotConditionLedgerWorld();

    $before = DB::table('order_conditions')->where('source', 'snapshot')->count();
    $sumBefore = (float) DB::table('order_conditions')->where('source', 'snapshot')->sum('amount');
    expect($before)->toBeGreaterThan(0);

    (new OrderSnapshotSeeder)->run();

    expect(DB::table('order_conditions')->where('source', 'snapshot')->count())->toBe($before)
        ->and((float) DB::table('order_conditions')->where('source', 'snapshot')->sum('amount'))
        ->toEqualWithDelta($sumBefore, 0.005);
});
