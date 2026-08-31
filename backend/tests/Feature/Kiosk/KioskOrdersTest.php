<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\File;
use App\Models\OrderItemTopping;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\Zone;
use App\Omnify\Enums\FileStatusEnum;
use Illuminate\Support\Facades\DB;
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

    $this->zone = Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->deviceToken = Str::random(32);

    $this->device = Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'active',
        'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->table = Table::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'zone_id' => $this->zone->id,
        'is_active' => true,
    ]);
});

it('returns the active order for a table', function () {
    $order = CustomerOrder::factory()->checkout()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'total_amount' => 3000,
        'paid_amount' => 0,
    ]);

    $this->table->update(['current_order_id' => $order->id]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/orders?table_id={$this->table->id}")
        ->assertOk();

    expect($response->json('data.id'))->toBe($order->id);
    expect($response->json('data.total'))->toBe(3000.0);
    expect($response->json('data'))->toHaveKeys(['id', 'table_id', 'table_name', 'items', 'subtotal', 'discount', 'tax_amount', 'service_charge', 'total', 'paid_amount', 'currency']);
});

it('resolves item name by request locale and guards empty with (unknown)', function () {
    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
    ]);
    $product->translateOrNew('ja')->name = 'コーヒー';
    $product->translateOrNew('en')->name = 'Coffee';
    $product->translateOrNew('vi')->name = 'Cà phê';
    $product->save();
    // SKU has no own name → resolver falls back to the localized product name.
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'name' => null, 'selling_price' => 1000, 'is_active' => true]);

    $order = CustomerOrder::factory()->checkout()->create([
        'branch_id' => $this->branch->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
        'subtotal' => 1000, 'total_amount' => 1000, 'paid_amount' => 0,
    ]);
    // No SKU name → the resolver must derive a localized name from the product.
    $order->items()->create([
        'product_sku_id' => $sku->id, 'quantity' => 1, 'unit_price' => 1000, 'original_unit_price' => 1000,
        'subtotal' => 1000, 'status' => 'served', 'served_at' => now(),
        'tax_rate' => 0,
    ]);
    $this->table->update(['current_order_id' => $order->id]);

    foreach (['vi' => 'Cà phê', 'ja' => 'コーヒー', 'en' => 'Coffee'] as $locale => $expected) {
        $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
            ->getJson("/api/v1/kiosk/orders?table_id={$this->table->id}&locale={$locale}")
            ->assertOk()
            ->assertJsonPath('data.items.0.name', $expected)
            ->assertJsonPath('data.items.0.product_name', $expected)
            ->assertJsonPath('data.items.0.sku_code', $sku->sku);
    }
});

it('falls back across locales when the requested locale has no product name', function () {
    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
    ]);
    // ONLY a Japanese name exists.
    $product->translateOrNew('ja')->name = 'コーヒー';
    $product->save();
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'name' => null, 'selling_price' => 1000, 'is_active' => true]);

    $order = CustomerOrder::factory()->checkout()->create([
        'branch_id' => $this->branch->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
        'subtotal' => 1000, 'total_amount' => 1000, 'paid_amount' => 0,
    ]);
    $order->items()->create([
        'product_sku_id' => $sku->id, 'quantity' => 1, 'unit_price' => 1000, 'original_unit_price' => 1000,
        'subtotal' => 1000, 'status' => 'served', 'served_at' => now(),
        'tax_rate' => 0,
    ]);
    $this->table->update(['current_order_id' => $order->id]);

    // Request vi — no vi translation → must fall back to ja, never empty.
    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/orders?table_id={$this->table->id}&locale=vi")
        ->assertOk()
        ->assertJsonPath('data.items.0.name', 'コーヒー')
        ->assertJsonPath('data.items.0.product_name', 'コーヒー');
});

it('falls back to the SKU code when the product has no name in any locale', function () {
    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
    ]);
    // Wipe every name source (base column + any translation) — a data error
    // the resolver must survive without showing '(unknown)'.
    DB::table('products')->where('id', $product->id)->update(['name' => '']);
    DB::table('product_translations')->where('product_id', $product->id)->delete();
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id, 'name' => null, 'sku' => 'PV-NONAME-1', 'selling_price' => 1000, 'is_active' => true,
    ]);

    $order = CustomerOrder::factory()->checkout()->create([
        'branch_id' => $this->branch->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
        'subtotal' => 1000, 'total_amount' => 1000, 'paid_amount' => 0,
    ]);
    $order->items()->create([
        'product_sku_id' => $sku->id, 'quantity' => 1, 'unit_price' => 1000, 'original_unit_price' => 1000,
        'subtotal' => 1000, 'status' => 'served', 'served_at' => now(),
        'tax_rate' => 0,
    ]);
    $this->table->update(['current_order_id' => $order->id]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/orders?table_id={$this->table->id}")
        ->assertOk()
        ->assertJsonPath('data.items.0.name', 'PV-NONAME-1'); // never '(unknown)'
});

it('still resolves a soft-deleted product name (history via withTrashed)', function () {
    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
    ]);
    $product->translateOrNew('en')->name = 'Latte';
    $product->save();
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'name' => null, 'selling_price' => 1000, 'is_active' => true]);

    $order = CustomerOrder::factory()->checkout()->create([
        'branch_id' => $this->branch->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
        'subtotal' => 1000, 'total_amount' => 1000, 'paid_amount' => 0,
    ]);
    $order->items()->create([
        'product_sku_id' => $sku->id, 'quantity' => 1, 'unit_price' => 1000, 'original_unit_price' => 1000,
        'subtotal' => 1000, 'status' => 'served', 'served_at' => now(),
        'tax_rate' => 0,
    ]);
    $this->table->update(['current_order_id' => $order->id]);

    $product->delete(); // soft delete after the order exists

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/orders?table_id={$this->table->id}&locale=en")
        ->assertOk()
        ->assertJsonPath('data.items.0.product_name', 'Latte');
});

it('prices an open (pre-checkout) order live from branch service config + line snapshots', function () {
    // Tax/service are stamped only at checkout; an open order stores 0. The
    // kiosk bill must still show them so it matches the split-by-items preview.
    // plan-043 T6.2 — tax is now driven by the per-line snapshot rate (10%),
    // not the dropped branch tax_rate; service charge still comes from config.
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'service_charge_rate' => 5,
        'currency_code' => 'JPY',
    ]);

    $order = CustomerOrder::factory()->open()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'subtotal' => 2500,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 2500,
        'paid_amount' => 0,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => 2500,
        'subtotal' => 2500,
        'tax_rate' => 10,
        'status' => 'pending',
    ]);
    $this->table->update(['current_order_id' => $order->id]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/orders?table_id={$this->table->id}")
        ->assertOk();

    expect($response->json('data.subtotal'))->toBe(2500.0);
    expect($response->json('data.service_charge'))->toBe(125.0);
    expect($response->json('data.tax_amount'))->toBe(250.0);
    expect($response->json('data.total'))->toBe(2875.0);
});

it('returns frozen stored pricing for a checked-out order even if rates change', function () {
    // After checkout the columns are authoritative; a later rate change must
    // not retro-price the order. forOrder() returns stored for finalized.
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'service_charge_rate' => 99,
        'currency_code' => 'JPY',
    ]);

    $order = CustomerOrder::factory()->checkout()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'subtotal' => 2500,
        'service_charge' => 125,
        'tax_amount' => 250,
        'total_amount' => 2875,
        'paid_amount' => 0,
    ]);
    $this->table->update(['current_order_id' => $order->id]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/orders?table_id={$this->table->id}")
        ->assertOk();

    expect($response->json('data.tax_amount'))->toBe(250.0);
    expect($response->json('data.total'))->toBe(2875.0);
});

it('#501 — a submitted takeaway shows its stored total, not a live recompute', function () {
    // The bug: a takeaway the customer submitted at 2,200đ (its stored total)
    // rang up more on the kiosk because the resource re-priced it with the
    // CURRENT branch rates. A submitted order is LOCKED — the kiosk must show
    // exactly the persisted total customer-web shows, no matter the live rates.
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'service_charge_rate' => 5,
        'currency_code' => 'VND',
    ]);

    $order = CustomerOrder::factory()->create([
        'order_type' => 'takeaway',
        'status' => 'confirmed', // submitted, awaiting counter payment (not open/dining)
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'subtotal' => 2200,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 2200, // stored total the customer agreed to
        'paid_amount' => 0,
    ]);

    // Takeaway counter-pay resolves by order code (no table).
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/orders?code={$order->order_code}")
        ->assertOk();

    // Stored total, NOT a 2200 + 10% + 5% recompute.
    expect($response->json('data.total'))->toBe(2200.0);
    expect($response->json('data.tax_amount'))->toBe(0.0);
    expect($response->json('data.service_charge'))->toBe(0.0);
});

it('returns data null when table has no active order', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/orders?table_id={$this->table->id}")
        ->assertOk();

    expect($response->json('data'))->toBeNull();
});

it('returns data null when order is closed', function () {
    $order = CustomerOrder::factory()->closed()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->table->update(['current_order_id' => $order->id]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/orders?table_id={$this->table->id}")
        ->assertOk();

    expect($response->json('data'))->toBeNull();
});

it('returns 404 when table_id belongs to a different branch', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
    ]);
    $otherZone = Zone::factory()->create(['branch_id' => $otherBranch->id, 'organization_id' => $otherOrgId]);
    $otherTable = Table::factory()->create([
        'branch_id' => $otherBranch->id,
        'organization_id' => $otherOrgId,
        'zone_id' => $otherZone->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/orders?table_id={$otherTable->id}")
        ->assertNotFound();
});

it('returns 403 when device type is not kiosk', function () {
    $tmsToken = Str::random(32);
    Device::factory()->create([
        'type' => 'tms',
        'status' => 'active',
        'device_token' => $tmsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$tmsToken}"])
        ->getJson("/api/v1/kiosk/orders?table_id={$this->table->id}")
        ->assertForbidden();
});

it('returns 422 when table_id is missing', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/kiosk/orders')
        ->assertUnprocessable();
});

it('returns data null when current_order_id points to an order from a different branch', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
    ]);

    $foreignOrder = CustomerOrder::factory()->checkout()->create([
        'branch_id' => $otherBranch->id,
        'brand_id' => $otherBrand->id,
        'organization_id' => $otherOrgId,
        'total_amount' => 3000,
    ]);

    // Table belongs to THIS branch but current_order_id points to a foreign branch order
    $this->table->update(['current_order_id' => $foreignOrder->id]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/orders?table_id={$this->table->id}")
        ->assertOk();

    expect($response->json('data'))->toBeNull();
});

it('returns data null when order is in pending status', function () {
    $order = CustomerOrder::factory()->create([
        'status' => 'pending',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'total_amount' => 3000,
    ]);

    $this->table->update(['current_order_id' => $order->id]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/orders?table_id={$this->table->id}")
        ->assertOk();

    expect($response->json('data'))->toBeNull();
});

it('returns the active order by code', function () {
    $order = CustomerOrder::factory()->checkout()->create([
        'order_code' => 'ORD-2026-TEST01',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'total_amount' => 2500,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/kiosk/orders?code=ORD-2026-TEST01')
        ->assertOk();

    expect($response->json('data.id'))->toBe($order->id);
    expect($response->json('data.total'))->toBe(2500.0);
    expect($response->json('data'))->toHaveKeys(['id', 'table_id', 'table_name', 'items', 'subtotal', 'discount', 'tax_amount', 'service_charge', 'total', 'paid_amount', 'currency']);
});

it('resolves item image_url from gallery (preferred) and thumbnail (fallback)', function () {
    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

    // Product A — has a gallery image (galleryFirst is preferred).
    $productA = Product::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
    ]);
    $galleryFile = File::factory()->create([
        'organization_id' => $this->orgId,
        'fileable_type' => (new Product)->getMorphClass(),
        'fileable_id' => $productA->id,
        'status' => FileStatusEnum::Permanent,
        'collection' => 'gallery',
        'sort_order' => 0,
    ]);
    $skuA = ProductSku::factory()->create(['product_id' => $productA->id, 'selling_price' => 1000, 'is_active' => true]);

    // Product B — no gallery, only a thumbnail (fallback path).
    $productB = Product::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
    ]);
    $thumbFile = File::factory()->create([
        'organization_id' => $this->orgId,
        'fileable_type' => (new Product)->getMorphClass(),
        'fileable_id' => $productB->id,
        'status' => FileStatusEnum::Permanent,
        'collection' => 'thumbnail',
    ]);
    $skuB = ProductSku::factory()->create(['product_id' => $productB->id, 'selling_price' => 1000, 'is_active' => true]);

    // Product C — no image at all → image_url must be null.
    $productC = Product::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
    ]);
    $skuC = ProductSku::factory()->create(['product_id' => $productC->id, 'selling_price' => 1000, 'is_active' => true]);

    $order = CustomerOrder::factory()->checkout()->create([
        'order_code' => 'ORD-2026-IMG001',
        'branch_id' => $this->branch->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
        'total_amount' => 3000,
    ]);
    foreach ([$skuA, $skuB, $skuC] as $sku) {
        $order->items()->create([
            'product_sku_id' => $sku->id, 'quantity' => 1, 'unit_price' => 1000, 'original_unit_price' => 1000,
            'subtotal' => 1000, 'status' => 'served', 'served_at' => now(),
            'tax_rate' => 0,
        ]);
    }

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/kiosk/orders?code=ORD-2026-IMG001')
        ->assertOk();

    $items = collect($response->json('data.items'))->keyBy('sku_code');
    expect($items[$skuA->sku]['image_url'])->toBe($galleryFile->getUrl());
    expect($items[$skuB->sku]['image_url'])->toBe($thumbFile->getUrl());
    expect($items[$skuC->sku]['image_url'])->toBeNull();
});

it('returns data null when code does not match any order', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/kiosk/orders?code=ORD-2026-NONEXIST')
        ->assertOk();

    expect($response->json('data'))->toBeNull();
});

it('resolves a closed order by code so post-payment refresh + already-paid scan work', function () {
    // A by_items split's FINAL payment closes the order; the kiosk's
    // refreshOrder() must still resolve it to see remaining==0 (isComplete)
    // and finish the flow cleanly instead of looping at completion. A fresh
    // scan of an already-paid order likewise resolves → "đã thanh toán".
    $order = CustomerOrder::factory()->closed()->create([
        'order_code' => 'ORD-2026-CLOSED',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/kiosk/orders?code=ORD-2026-CLOSED')
        ->assertOk();

    expect($response->json('data'))->not->toBeNull();
    expect($response->json('data.id'))->toBe($order->id);
});

it('prioritizes code over table_id when both are provided', function () {
    $order = CustomerOrder::factory()->checkout()->create([
        'order_code' => 'ORD-2026-PRIO01',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'total_amount' => 1500,
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/kiosk/orders?table_id='.$this->table->id.'&code=ORD-2026-PRIO01')
        ->assertOk();

    expect($response->json('data.id'))->toBe($order->id);
});

/**
 * Helper: attach a single CustomerOrderItem with two topping rows
 * (qty=5 for "Egg" and qty=1 for "Cheese") to an existing order.
 *
 * Used to prove KioskOrderResource emits `quantity` on each extras entry
 * so the kiosk receipt can render "5 x Egg ¥500" instead of falling back
 * to qty=1 (the bug fix).
 */
function seedKioskOrderToppings(CustomerOrder $order, Brand $brand, string $orgId): CustomerOrderItem
{
    $pt = ProductType::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $product = Product::factory()->create([
        'organization_id' => $orgId, 'brand_id' => $brand->id, 'product_type_id' => $pt->id,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 1000, 'is_active' => true]);

    $orderItem = CustomerOrderItem::create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'unit_price' => 1000,
        'original_unit_price' => 1000,
        'subtotal' => 1000,
        'status' => 'pending',
        'tax_rate' => 0,
    ]);

    $group = ToppingGroup::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'is_active' => true,
        'modifier_type' => 'add',
        'selection_type' => 'multiple',
    ]);

    foreach ([['Egg', 5], ['Cheese', 1]] as [$name, $qty]) {
        $tProduct = Product::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id, 'product_type_id' => $pt->id]);
        $tProduct->translateOrNew('ja')->name = $name;
        $tProduct->save();
        $tSku = ProductSku::factory()->create(['product_id' => $tProduct->id, 'selling_price' => 100, 'is_active' => true]);
        $tItem = ToppingGroupItem::factory()->create(['topping_group_id' => $group->id, 'product_id' => $tProduct->id]);
        OrderItemTopping::create([
            'customer_order_item_id' => $orderItem->id,
            'topping_group_item_id' => $tItem->id,
            'product_sku_id' => $tSku->id,
            'quantity' => $qty,
            'unit_price' => 100,
            'original_unit_price' => 100,
            'status' => 'pending',
        ]);
    }

    return $orderItem;
}

it('emits quantity (>1 and =1) on extras[] for the by-table endpoint', function () {
    $order = CustomerOrder::factory()->checkout()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'total_amount' => 1500,
    ]);
    seedKioskOrderToppings($order, $this->brand, $this->orgId);
    $this->table->update(['current_order_id' => $order->id]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson("/api/v1/kiosk/orders?table_id={$this->table->id}")
        ->assertOk();

    $extras = collect($response->json('data.items.0.extras'));
    expect($extras)->toHaveCount(2);
    expect($extras->pluck('quantity')->all())->toBe([5, 1]);
    // Stays integer, never coerced to float / string.
    expect($extras->first()['quantity'])->toBeInt();
});

it('emits quantity on extras[] for the by-code endpoint', function () {
    $order = CustomerOrder::factory()->checkout()->create([
        'order_code' => 'ORD-2026-EXTQTY',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'total_amount' => 1500,
    ]);
    seedKioskOrderToppings($order, $this->brand, $this->orgId);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->deviceToken}"])
        ->getJson('/api/v1/kiosk/orders?code=ORD-2026-EXTQTY')
        ->assertOk();

    $extras = collect($response->json('data.items.0.extras'));
    expect($extras->pluck('quantity')->all())->toBe([5, 1]);
});
