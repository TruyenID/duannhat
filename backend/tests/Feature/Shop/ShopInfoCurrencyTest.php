<?php

declare(strict_types=1);

/**
 * #1260 — admin-web had no way to learn a shop's currency, so four shop screens
 * interpolated `¥` directly and a Vietnamese shop was shown its VND takings as
 * yen. ShopInfoController is where the shop-scoped facts already arrive
 * (`timezone` came the same way for #1248), so the currency joins them.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Models\User;
use Illuminate\Support\Str;

function shopInfoCurrencyFixture(): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId, 'is_active' => true]);
    $shop = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);
    $user = User::factory()->create(['console_organization_id' => $orgId]);
    // Same helper the sibling ShopInfoControllerTest uses; without it the
    // request is a 403 rather than a currency assertion.
    grantOrgAccess($user, $orgId);

    return [$shop, $user];
}

it('reports the currency the shop actually takes money in', function () {
    [$shop, $user] = shopInfoCurrencyFixture();
    ShopOrderSetting::factory()->create([
        'branch_id' => $shop->id,
        'currency_code' => 'VND',
    ]);

    $this->actingAs($user)
        ->getJson("/api/v1/shops/{$shop->slug}")
        ->assertOk()
        ->assertJsonPath('data.currency_code', 'VND');
});

it('falls back to the branch column when no settings row exists yet', function () {
    // A shop created but never configured still has to render money somehow;
    // branches.currency is the older column and the only thing left to read.
    [$shop, $user] = shopInfoCurrencyFixture();
    $shop->forceFill(['currency' => 'JPY'])->save();

    $this->actingAs($user)
        ->getJson("/api/v1/shops/{$shop->slug}")
        ->assertOk()
        ->assertJsonPath('data.currency_code', 'JPY');
});
