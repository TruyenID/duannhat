<?php

use App\Models\Branch;
use App\Models\ShopOrderSetting;
use App\Models\Zone;
use App\Services\Provisioning\BranchBaselineProvisioner;
use Database\Seeders\ShopOrderSettingSeeder;

/**
 * #2320 — seeder này KHÔNG còn tạo hàng `shop_order_settings`; việc đó thuộc
 * {@see BranchBaselineProvisioner}. Ở đây chỉ còn phí phục vụ demo cho dev.
 */
function seederBranch(): Branch
{
    $branch = Branch::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);
    Zone::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $branch->id,
    ]);

    return $branch;
}

it('không tạo hàng cài đặt nào — việc đó là của baseline provisioner', function () {
    $branch = seederBranch();

    (new ShopOrderSettingSeeder)->run();

    expect(ShopOrderSetting::where('branch_id', $branch->id)->exists())->toBeFalse();
});

it('nâng phí phục vụ demo lên 5% cho hàng baseline vừa dựng', function () {
    $branch = seederBranch();
    app(BranchBaselineProvisioner::class)->ensure($branch);

    (new ShopOrderSettingSeeder)->run();

    $setting = ShopOrderSetting::where('branch_id', $branch->id)->firstOrFail();

    expect((float) $setting->service_charge_rate)->toBe(5.0)
        ->and((float) $setting->service_charge_tax_rate)->toBe(10.0);
});

it('không đụng vào con số người vận hành đã đặt', function () {
    $branch = seederBranch();

    ShopOrderSetting::create([
        'branch_id' => $branch->id,
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'service_charge_rate' => 3.5,
        'currency_code' => 'USD',
    ]);

    (new ShopOrderSettingSeeder)->run();
    (new ShopOrderSettingSeeder)->run();

    $settings = ShopOrderSetting::where('branch_id', $branch->id)->get();

    expect($settings)->toHaveCount(1)
        ->and((float) $settings->first()->service_charge_rate)->toBe(3.5)
        ->and($settings->first()->currency_code)->toBe('USD');
});
