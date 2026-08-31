<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CatalogRevision;
use App\Models\Device;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);

    $this->wsDevice = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('returns data=null when branch has no active menu', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu')
        ->assertOk()
        ->assertJson(['data' => null])
        ->assertJsonStructure(['generated_at']);
});

it('returns 401 without auth', function () {
    $this->getJson('/api/v1/workstation/menu')->assertUnauthorized();
});

it('returns 403 when kiosk device hits /workstation/menu', function () {
    $kioskToken = Str::random(64);
    Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'active',
        'device_token' => $kioskToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$kioskToken}"])
        ->getJson('/api/v1/workstation/menu')
        ->assertForbidden();
});

// =========================================================================
//  #1095/#1114 — catalog revision + topping flag ride the menu payload
// =========================================================================

it('#1114: carries catalog_revision and whether it prices toppings', function () {
    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->active()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 1000, 'is_active' => true]);
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id, 'status' => 'Active',
    ]);
    $line = MenuProduct::factory()->create(['menu_id' => $menu->id, 'product_id' => $product->id, 'is_active' => true]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $line->id, 'product_sku_id' => $sku->id,
        'selling_price' => 1000, 'is_price_overridden' => true, 'is_active' => true,
    ]);

    // Every freshly-minted revision is snapshot v2 → it CAN price toppings,
    // so the signer gate opens as soon as a revision exists at all.
    $first = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu')
        ->assertOk();
    expect($first->json('data.catalog_revision'))->toBeInt()
        ->and($first->json('data.catalog_revision_has_toppings'))->toBeTrue();

    // A LEGACY v1 snapshot (pre-#1114 fleet history) must keep the gate
    // closed — silence about toppings must never be read as priceable.
    $revision = CatalogRevision::query()
        ->where('branch_id', $this->branch->id)
        ->orderByDesc('revision')
        ->firstOrFail();
    $legacy = (array) $revision->snapshot;
    unset($legacy['v']);
    $revision->forceFill(['snapshot' => $legacy])->save();

    $second = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu')
        ->assertOk();
    expect($second->json('data.catalog_revision_has_toppings'))->toBeFalse();
});
