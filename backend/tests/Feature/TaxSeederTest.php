<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Models\Zone;
use Database\Seeders\BaselineProvisioningSeeder;
use Illuminate\Database\Eloquent\Model;

/**
 * plan-043 T6.4 — bộ ba loại thuế chuẩn + dấu mặc định trên từng chi nhánh.
 *
 * #2320 — `TaxTypeSeeder` và `ShopOrderSettingSeeder` (phần tạo hàng) đã gộp
 * vào {@see BaselineProvisioningSeeder}. Bài test giữ nguyên những gì nó ghim;
 * chỉ đổi cửa vào.
 */
beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001'; // seeded by Pest beforeEach
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    // A product anchors the brand→org resolution used by the provisioner.
    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    Product::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    Zone::factory()->for($this->branch, 'branch')->create(['organization_id' => $this->orgId]);
});

it('seeds the 3 Japanese-standard tax types per brand with STANDARD as default', function () {
    (new BaselineProvisioningSeeder)->run();

    $types = TaxType::where('brand_id', $this->brand->id)->get()->keyBy('code');
    expect($types)->toHaveCount(3)
        ->and((float) $types['STANDARD']->rate)->toBe(10.0)
        ->and((float) $types['REDUCED']->rate)->toBe(8.0)
        ->and((float) $types['EXEMPT']->rate)->toBe(0.0)
        ->and($types['STANDARD']->is_default)->toBeTrue()
        ->and($types['REDUCED']->is_default)->toBeFalse();

    // Exactly one default per brand.
    expect(TaxType::where('brand_id', $this->brand->id)->where('is_default', true)->count())->toBe(1);
    // Translatable name persisted.
    expect($types['REDUCED']->translate('ja')->name)->toBe('軽減税率');
});

it('is idempotent — re-running creates no duplicates', function () {
    (new BaselineProvisioningSeeder)->run();
    (new BaselineProvisioningSeeder)->run();

    expect(TaxType::where('brand_id', $this->brand->id)->count())->toBe(3);
});

it('persists tax-type names even under Model::withoutEvents (BUG-9 regression)', function () {
    // DatabaseSeeder runs every sub-seeder inside WithoutModelEvents, which mutes
    // the Astrotomic `saved` event that would otherwise write the translation
    // sidecar rows. The standalone TaxTypeService must flush translations
    // explicitly (mirroring the generated base) or the seeded types come out
    // nameless — this is exactly the state a fresh `migrate:fresh --seed` hits.
    Model::withoutEvents(function () {
        (new BaselineProvisioningSeeder)->run();
    });

    $standard = TaxType::where('brand_id', $this->brand->id)->where('code', 'STANDARD')->firstOrFail();

    expect(DB::table('tax_type_translations')->where('tax_type_id', $standard->id)->count())->toBe(3)
        ->and($standard->fresh()->name)->not->toBeNull();

    // Every seeded type of the brand carries its 3 locale rows.
    $typeIds = TaxType::where('brand_id', $this->brand->id)->pluck('id');
    expect(DB::table('tax_type_translations')->whereIn('tax_type_id', $typeIds)->count())->toBe(9);
});

it('stamps every branch default_tax_type_id to the brand STANDARD (T6.1 gate)', function () {
    (new BaselineProvisioningSeeder)->run();

    $setting = ShopOrderSetting::where('branch_id', $this->branch->id)->first();
    $standard = TaxType::where('brand_id', $this->brand->id)->where('code', 'STANDARD')->first();

    // #2108: the baseline stamps the country default (JP ⇒ tax-included) via
    // ComplianceProfileResolver — this fixture org resolves to JP.
    expect($setting->default_tax_type_id)->toBe($standard->id)
        ->and((bool) $setting->prices_include_tax)->toBeTrue();

    // T6.1 gate: no branch left without a default.
    expect(ShopOrderSetting::whereNull('default_tax_type_id')->count())->toBe(0);
});
