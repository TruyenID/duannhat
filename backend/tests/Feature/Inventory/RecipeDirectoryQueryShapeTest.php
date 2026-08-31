<?php

declare(strict_types=1);

/**
 * #1567 — ba truy vấn "công thức mới nhất" của `RecipeDirectory` KHÁC NHAU, và
 * khác biệt đó có nghĩa nghiệp vụ.
 *
 * Gộp chúng thành một method "linh hoạt" (truyền cờ `onlyApproved`, `orderBy`…)
 * là cách đánh mất nó, và mất im lặng: cả ba đều trả về một `RecipeSnapshot`
 * hợp lệ, chỉ là **sai công thức**.
 *
 *   - đóng lô              → còn hiệu lực VÀ đã duyệt, mới nhất theo `updated_at`
 *   - kiểm tra khi sửa lô  → còn hiệu lực, BẤT KỂ duyệt, mới nhất theo `updated_at`
 *   - dung sai / sản lượng → ĐÃ DUYỆT, BẤT KỂ `is_active`, mới nhất theo `approved_at`
 *
 * Dữ liệu dưới đây dựng đúng để **ba câu hỏi trả về ba công thức khác nhau**.
 */

use App\Models\Material;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Services\Product\Contracts\RecipeDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function seedRecipe(Material $material, array $attrs): string
{
    $id = (string) Str::uuid();
    DB::table('recipes')->insert(array_merge([
        'id' => $id,
        'material_id' => (string) $material->id,
        'organization_id' => (string) $material->organization_id,
        'brand_id' => $material->brand_id,
        'sku' => 'RCP-'.substr($id, 0, 8),
        'name' => 'seed',
        'output_quantity' => 1,
        'ingredients' => json_encode([]),
        'created_at' => now(),
    ], $attrs));

    return $id;
}

it('ba câu hỏi "mới nhất" trả về BA công thức khác nhau', function () {
    $material = Material::factory()->create();
    $mid = (string) $material->id;

    // A: active + approved, sửa GẦN ĐÂY, duyệt LÂU RỒI
    $a = seedRecipe($material, [
        'is_active' => true,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'updated_at' => now()->subDay(),
        'approved_at' => now()->subYear(),
    ]);
    // B: active nhưng CHƯA duyệt, sửa MỚI NHẤT
    $b = seedRecipe($material, [
        'is_active' => true,
        'approval_status' => ApprovalStatusEnum::Pending->value,
        'updated_at' => now(),
        'approved_at' => null,
    ]);
    // C: KHÔNG active nhưng đã duyệt GẦN ĐÂY NHẤT
    $c = seedRecipe($material, [
        'is_active' => false,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'updated_at' => now()->subYear(),
        'approved_at' => now(),
    ]);

    $dir = app(RecipeDirectory::class);

    expect($dir->latestActiveApprovedForMaterial($mid)?->id)->toBe($a, 'đóng lô phải lấy A (active + approved, updated_at mới nhất trong nhóm đó)')
        ->and($dir->latestActiveForMaterial($mid)?->id)->toBe($b, 'kiểm tra khi sửa phải lấy B (active, bất kể duyệt, updated_at mới nhất)')
        ->and($dir->latestApprovedForMaterial($mid)?->id)->toBe($c, 'dung sai/sản lượng phải lấy C (approved, bất kể active, approved_at mới nhất)');
});

it('snapshot mang ĐỦ trường, không phụ thuộc cột nào được select', function () {
    // #1301 — bản cũ `select()` cột khác nhau ở mỗi chỗ gọi, nên một trường null
    // có thể là "không có dữ liệu" HOẶC "cột không được chọn". Cổng đọc đủ cột.
    $material = Material::factory()->create();
    $id = seedRecipe($material, [
        'is_active' => true,
        'approval_status' => ApprovalStatusEnum::Approved->value,
        'output_quantity' => 7.5,
        'ingredients' => json_encode([['type' => 'variant', 'variant_id' => 'sku-1', 'quantity' => 2, 'unit' => 'g']]),
        'yield_variance_tolerance_pct' => 12.5,
        'updated_at' => now(),
        'approved_at' => now(),
    ]);

    $snap = app(RecipeDirectory::class)->find($id);

    expect($snap)->not->toBeNull()
        ->and($snap->outputQuantity)->toBe(7.5)
        ->and($snap->yieldVarianceTolerancePct)->toBe(12.5)
        ->and($snap->isApproved())->toBeTrue()
        // Giữ NGUYÊN hình dạng thô: một công thức được phép tham chiếu SKU khác
        // làm nguyên liệu (`variant_id`), và ProductionOrderService dựa vào đó.
        ->and($snap->ingredients[0]['type'])->toBe('variant')
        ->and($snap->ingredients[0]['variant_id'])->toBe('sku-1');
});

it('id không tồn tại trả null', function () {
    expect(app(RecipeDirectory::class)->find((string) Str::uuid()))->toBeNull();
});
