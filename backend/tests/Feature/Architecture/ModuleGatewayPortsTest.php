<?php

declare(strict_types=1);

/**
 * #962 — cổng hẹp của đợt "một class, một hai cạnh".
 *
 * Bài học được ghim ở đây là bài học của #1544, viết lại cho lô cổng này: một
 * interface KHÔNG resolve được không phải ranh giới, nó là đồ trang trí — và nó
 * hỏng theo kiểu tệ nhất, vì deptrac vẫn xanh (cạnh đã biến mất khỏi baseline)
 * trong khi đường chạy thật ném `BindingResolutionException` ở production.
 * Deptrac đo HÌNH DẠNG đồ thị, không đo container.
 *
 * Nên mỗi cổng ở đây phải qua HAI cửa:
 *
 *   1. resolve được từ container, và ra đúng adapter đã khai;
 *   2. trả lời đúng câu hỏi nó sinh ra để trả lời, trên dữ liệu thật.
 *
 * Cửa 2 không thừa: năm cổng dưới đây thay những câu `where(...)` được chép
 * tay từ chỗ khác sang, và điều kiện dễ trượt nhất là những cái KHÔNG nằm trong
 * tham số — `branch_id IS NULL` của từ vựng tender, `is_active` của nguyên liệu.
 * Một adapter quên chúng vẫn resolve, vẫn xanh ở cửa 1, và âm thầm trả sai tập.
 */

use App\Models\Material;
use App\Models\TaxType;
use App\Models\TillTenderType;
use App\Models\Warehouse;
use App\Models\WarehouseMember;
use App\Services\Device\Contracts\NotifiableDeviceDirectory;
use App\Services\Device\Internal\EloquentNotifiableDeviceDirectory;
use App\Services\Inventory\Contracts\ActiveMaterialDirectory;
use App\Services\Inventory\Contracts\WarehouseMemberDirectory;
use App\Services\Inventory\EloquentActiveMaterialDirectory;
use App\Services\Inventory\EloquentWarehouseMemberDirectory;
use App\Services\Order\Contracts\BrandOrderPolicyDefaults;
use App\Services\Promotion\Contracts\PersonalCouponMinting;
use App\Services\Promotion\PersonalCouponMinter;
use App\Services\Shop\Contracts\TableOccupancy;
use App\Services\Shop\EloquentBrandOrderPolicyDefaults;
use App\Services\Shop\EloquentTableOccupancy;
use App\Services\Tax\Contracts\TaxTypeDirectory;
use App\Services\Tax\Internal\EloquentTaxTypeDirectory;
use App\Services\Till\Contracts\OrgTenderVocabulary;
use App\Services\Till\EloquentOrgTenderVocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('G1: mọi cổng #962 resolve ra ĐÚNG adapter đã khai', function (string $port, string $adapter) {
    expect(app()->make($port))->toBeInstanceOf($adapter);
})->with([
    [NotifiableDeviceDirectory::class, EloquentNotifiableDeviceDirectory::class],
    [WarehouseMemberDirectory::class, EloquentWarehouseMemberDirectory::class],
    [ActiveMaterialDirectory::class, EloquentActiveMaterialDirectory::class],
    [TaxTypeDirectory::class, EloquentTaxTypeDirectory::class],
    [PersonalCouponMinting::class, PersonalCouponMinter::class],
    [OrgTenderVocabulary::class, EloquentOrgTenderVocabulary::class],
    [BrandOrderPolicyDefaults::class, EloquentBrandOrderPolicyDefaults::class],
    [TableOccupancy::class, EloquentTableOccupancy::class],
]);

it('G2: từ vựng tender chỉ nhận khoá CẤP TỔ CHỨC còn hoạt động', function () {
    $orgKey = TillTenderType::factory()->create([
        'tender_key' => 'cash', 'branch_id' => null, 'is_active' => true,
    ]);
    $orgId = (string) $orgKey->organization_id;

    // Ba bẫy, và cả ba nằm NGOÀI tham số của cổng — đúng loại điều kiện mà một
    // adapter chép thiếu vẫn resolve được và vẫn xanh ở G1.
    TillTenderType::factory()->create([
        'organization_id' => $orgId, 'tender_key' => 'retired', 'branch_id' => null, 'is_active' => false,
    ]);
    $branchScoped = TillTenderType::factory()->create([
        'organization_id' => $orgId, 'tender_key' => 'branch_only', 'is_active' => true,
    ]);
    $branchScoped->forceFill(['branch_id' => (string) Str::uuid()])->saveQuietly();

    $vocab = app(OrgTenderVocabulary::class);

    expect($vocab->hasActiveOrgKey($orgId, 'cash'))->toBeTrue()
        ->and($vocab->hasActiveOrgKey($orgId, 'retired'))->toBeFalse()
        ->and($vocab->hasActiveOrgKey($orgId, 'branch_only'))->toBeFalse()
        ->and($vocab->hasActiveOrgKey($orgId, 'never_existed'))->toBeFalse();

    expect($vocab->activeOrgKeysAmong($orgId, ['cash', 'retired', 'branch_only', 'never_existed']))
        ->toEqual(['cash'])
        ->and($vocab->activeOrgKeysAmong($orgId, []))->toEqual([]);
});

it('G3: danh bạ nguyên liệu bỏ hàng đã tắt, và mảng rỗng KHÔNG quét cả bảng', function () {
    $active = Material::factory()->create(['is_active' => true]);
    $inactive = Material::factory()->create(['is_active' => false]);

    $directory = app(ActiveMaterialDirectory::class);

    expect($directory->activeByIds([(string) $active->id, (string) $inactive->id])->pluck('id')->all())
        ->toEqual([$active->id])
        // Bảo vệ đường `$parentMaterialIds === []` của `ProductSkuService::checkUsage`:
        // một `whereIn('id', [])` sai chỗ sẽ trả về CẢ BẢNG ở vài trình điều khiển.
        ->and($directory->activeByIds([])->count())->toBe(0);
});

it('G4: danh bạ thành viên kho trả đúng người giữ đúng vai ở đúng kho', function () {
    $warehouse = Warehouse::factory()->create();
    $other = Warehouse::factory()->create();

    $manager = WarehouseMember::factory()->create([
        'warehouse_id' => $warehouse->id, 'role' => 'manager',
    ]);
    WarehouseMember::factory()->create(['warehouse_id' => $warehouse->id, 'role' => 'staff']);
    WarehouseMember::factory()->create(['warehouse_id' => $other->id, 'role' => 'manager']);

    expect(app(WarehouseMemberDirectory::class)
        ->userIdsWithRoleInWarehouse((string) $warehouse->id, 'manager'))
        ->toEqual([(string) $manager->user_id]);
});

it('G5: loại thuế "thuộc về brand" so sánh NULL bằng NULL, không bằng `= NULL`', function () {
    $taxType = TaxType::factory()->create();
    $directory = app(TaxTypeDirectory::class);

    expect($directory->belongsToBrand(
        (string) $taxType->id,
        (string) $taxType->organization_id,
        (string) $taxType->brand_id,
    ))->toBeTrue();

    // Brand khác ⇒ false. Đây là cái chặn việc đóng dấu loại thuế của brand khác
    // lên sản phẩm, tức thứ làm hỏng bucket báo cáo theo thuế suất.
    expect($directory->belongsToBrand(
        (string) $taxType->id,
        (string) $taxType->organization_id,
        (string) Str::uuid(),
    ))->toBeFalse();

    // Tổ chức khác ⇒ false, kể cả khi brand khớp.
    expect($directory->belongsToBrand(
        (string) $taxType->id,
        (string) Str::uuid(),
        (string) $taxType->brand_id,
    ))->toBeFalse();

    /*
     * Ca NULL-vs-NULL cố ý KHÔNG ghim ở đây, và đây là lý do — vì nó từng được
     * ghim rồi bị bỏ, nên đáng ghi lại để không ai thêm lại lần thứ ba.
     *
     * Lập luận cho nó: bản gốc so `(string) $a !== (string) $b`, tức HAI NULL LÀ
     * BẰNG NHAU, nên một cổng dịch thẳng sang `where('brand_id', null)` sẽ sinh
     * `= NULL`, không khớp gì, và biến một phép gán hợp lệ thành 422.
     *
     * Vì sao vẫn bỏ: phía HỎI là `categories.organization_id` / `brand_id`, và cả
     * hai là **NOT NULL** ở migration (`2000_01_01_000029_create_categories_table`
     * khai `uuid(...)` trần, không `nullable()`). Không có đường nào đưa null tới
     * cổng, nên assertion đó ghim một hành vi không tồn tại — và giữ nó buộc chữ
     * ký phải nới thành `?string`, tức mở một trạng thái mà DB đã cấm.
     */
});
