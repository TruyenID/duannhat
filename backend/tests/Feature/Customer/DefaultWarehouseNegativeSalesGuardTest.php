<?php

/**
 * Plan-024 logic-risk regression.
 *
 * StockDeductionService::getDefaultWarehouse() (home of the close-time phase 5
 * since plan-051; previously OrderClosingService) lazily creates a branch
 * "DEFAULT" warehouse when a fresh branch has none. The lazy-create defaults
 * must honour the schema default (allow_negative_sales = false) — otherwise a
 * shop that never opted in silently gets the strict-shortage guard disabled,
 * corrupting inventory accounting on the sales path.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Warehouse;
use App\Services\Inventory\StockDeductionService;
use Illuminate\Support\Str;

it('lazily-created default warehouse keeps allow_negative_sales = false (schema default)', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);

    $brand = Brand::factory()->create([
        'console_organization_id' => $orgId,
    ]);

    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    // No warehouse exists for this branch → getDefaultWarehouse must create one.
    expect(Warehouse::where('branch_id', $branch->id)->exists())->toBeFalse();

    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'brand_id' => $brand->id,
        'organization_id' => $orgId,
    ]);

    $warehouseId = app(StockDeductionService::class)->getDefaultWarehouse((string) $order->branch_id, (string) $order->organization_id);

    $warehouse = Warehouse::findOrFail($warehouseId);

    // #2532 — mã sinh theo CHI NHÁNH, không phải hằng `DEFAULT` dùng chung cả
    // tổ chức. Ghim tiền tố + tính unique, không ghim chuỗi đầy đủ: cái đang
    // được bảo vệ là "hai chi nhánh không đụng nhau ở unique org+code".
    expect($warehouse->code)->toStartWith('DEFAULT-')
        ->and($warehouse->branch_id)->toBe($branch->id)
        ->and((bool) $warehouse->allow_negative_sales)->toBeFalse();
});

/**
 * #2532 — chi nhánh THỨ HAI của cùng tổ chức.
 *
 * Đây là ca đã xảy ra thật trên production 2026-08-12: org có đúng một
 * warehouse `code=DEFAULT` gắn 本郷店; 人形町店 chưa có kho nào. Mọi lượt đóng
 * đơn ở 人形町店 rẽ vào nhánh tạo-mới, insert vỡ
 * `warehouses_organization_id_code_unique` (1062) và rơi vào rào
 * `inventory.stock_drift`: **34 đơn thu tiền xong mà kho không trừ**.
 *
 * Không mock gì cả — ca này chỉ đúng khi chạm DB thật, vì thứ nổ là một unique
 * index chứ không phải một nhánh code.
 */
it('#2532 — chi nhánh thứ hai của org KHÔNG đụng unique org+code của chi nhánh đầu', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);

    $branchA = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);
    $branchB = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    // Hình dạng dữ liệu CŨ, đúng như production: một kho `DEFAULT` org-wide,
    // gắn chi nhánh A, và A không còn kho nào khác.
    Warehouse::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branchA->id,
        'code' => 'DEFAULT',
        'is_active' => true,
    ]);

    $service = app(StockDeductionService::class);

    $warehouseB = Warehouse::findOrFail($service->getDefaultWarehouse((string) $branchB->id, $orgId));

    expect($warehouseB->branch_id)->toBe($branchB->id)
        ->and($warehouseB->code)->not->toBe('DEFAULT')
        ->and(Warehouse::where('organization_id', $orgId)->count())->toBe(2);

    // Gọi lại phải trả về CHÍNH hàng đó, không sinh hàng thứ ba: khoá tra và
    // khoá unique nay là một.
    expect($service->getDefaultWarehouse((string) $branchB->id, $orgId))->toBe($warehouseB->id)
        ->and(Warehouse::where('organization_id', $orgId)->count())->toBe(2);
});

it('#2532 — kho mặc định đã XOÁ MỀM được khôi phục, không bị insert đè lên unique index', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    $service = app(StockDeductionService::class);
    $first = Warehouse::findOrFail($service->getDefaultWarehouse((string) $branch->id, $orgId));
    $first->delete();

    // `deleted_at` không nằm trong unique index, nên hàng đã xoá mềm vẫn giữ
    // chỗ: bỏ qua nó rồi INSERT là đúng 1062 của #2532 dưới hình dạng khác.
    $second = Warehouse::findOrFail($service->getDefaultWarehouse((string) $branch->id, $orgId));

    expect($second->id)->toBe($first->id)
        ->and($second->trashed())->toBeFalse()
        ->and(Warehouse::withTrashed()->where('organization_id', $orgId)->count())->toBe(1);
});
