<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Services\Catalog\CatalogRevisionService;
use App\Services\Device\DeviceSigningKeyService;
use App\Services\Order\Offline\OfflineOrderSigningMessage as Msg;
use App\Services\Order\Offline\SelectionWire;
use Illuminate\Support\Str;

/*
 * #1097/#1114 — POST /workstation/orders/replay-offline over real HTTP.
 *
 * The signature is produced from the SAME wire JSON the request body carries
 * (parsed through SelectionWire, exactly as the endpoint does), so this test
 * exercises the full contract: wire → payload → digest → Ed25519 verify →
 * re-price from the claimed catalog revision → order row.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $standard = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'code' => 'STANDARD', 'rate' => 10, 'is_default' => true,
    ]);
    ShopOrderSetting::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'default_tax_type_id' => $standard->id,
        'prices_include_tax' => false,
        'currency_code' => 'JPY',
        'service_charge_rate' => 0,
        'service_charge_tax_rate' => 0,
        'tax_rounding_mode' => 'round',
        'tax_rounding_decimals' => 0,
    ]);

    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->active()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id, 'tax_type_id' => $standard->id,
    ]);
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id, 'selling_price' => 1000, 'is_active' => true,
    ]);
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id, 'status' => 'active',
    ]);
    $line = MenuProduct::factory()->create([
        'menu_id' => $menu->id, 'product_id' => $product->id, 'is_active' => true, 'tax_type_id' => null,
    ]);
    $this->menuSku = MenuProductSku::factory()->create([
        'menu_product_id' => $line->id, 'product_sku_id' => $sku->id,
        'selling_price' => 1000, 'is_price_overridden' => true, 'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);
    $this->device = Device::factory()->create([
        'type' => 'workstation', 'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId, 'branch_id' => $this->branch->id,
        'pairing_code' => null,
    ]);
    $keypair = sodium_crypto_sign_keypair();
    $this->secretKey = sodium_crypto_sign_secretkey($keypair);
    $this->signingKey = app(DeviceSigningKeyService::class)->issue(
        $this->device,
        base64_encode(sodium_crypto_sign_publickey($keypair)),
    );
    $this->signingKey->update(['issued_at' => now()->subDays(7)]);
    $this->signingKey->refresh();

    $this->revision = app(CatalogRevisionService::class)->currentFor($this->branch->id);
});

/** Build a signed request body from a wire-shape selection. */
function signedReplayBody(array $wireSelection, array $overrides = []): array
{
    $issuedAt = $overrides['issued_at'] ?? now()->subMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z');
    $expiresAt = now()->addHours(60)->utc()->format('Y-m-d\TH:i:s\Z');
    $deviceId = (string) test()->device->id;
    $revision = $overrides['catalog_revision'] ?? test()->revision->revision;
    $keyId = (string) test()->signingKey->id;

    $digest = Msg::selectionDigest(SelectionWire::parse($wireSelection));
    $message = Msg::message($deviceId, $deviceId, $revision, $issuedAt, $expiresAt, $keyId, $digest);
    $signature = base64_encode(sodium_crypto_sign_detached($message, test()->secretKey));

    return [
        'order_id' => $overrides['order_id'] ?? (string) Str::uuid(),
        'selection' => $wireSelection,
        'evidence' => [
            'device_id' => $deviceId,
            'issuer_id' => $deviceId,
            'catalog_revision' => $revision,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'key_id' => $keyId,
            'signature' => $signature,
        ],
    ];
}

function wireSelection(int $quantity = 2): array
{
    return [
        'lines' => [[
            'line_id' => (string) Str::uuid(),
            'menu_product_sku_id' => (string) test()->menuSku->id,
            'quantity' => $quantity,
        ]],
        'device_id' => (string) test()->device->id,
    ];
}

it('accepts a signed offline order over HTTP and bills it from the catalog: 2 × ¥1,000 + 10% = ¥2,200', function () {
    $body = signedReplayBody(wireSelection());

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders/replay-offline', $body)
        ->assertCreated()
        ->assertJsonPath('data.order_id', $body['order_id'])
        ->assertJsonPath('data.item_count', 1);

    $order = CustomerOrder::findOrFail($body['order_id']);
    expect((float) $order->total_amount)->toBe(2200.0)
        ->and((string) $order->branch_id)->toBe((string) $this->branch->id);
});

it('is idempotent on order_id — a retried sync returns the same order', function () {
    $body = signedReplayBody(wireSelection());

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders/replay-offline', $body)
        ->assertCreated();
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders/replay-offline', $body)
        ->assertCreated()
        ->assertJsonPath('data.order_id', $body['order_id']);

    expect(CustomerOrder::count())->toBe(1);
});

it('rejects a selection altered after signing with 422 + reason_code', function () {
    $body = signedReplayBody(wireSelection(quantity: 2));
    // The classic rewrite: sign 2 units, sync 5.
    $body['selection']['lines'][0]['quantity'] = 5;

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders/replay-offline', $body)
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'OFFLINE_EVIDENCE_REJECTED')
        ->assertJsonPath('reason_code', 'signature_invalid');

    expect(CustomerOrder::count())->toBe(0);
});

it('rejects a malformed selection before any verification', function () {
    $body = signedReplayBody(wireSelection());
    $body['selection']['lines'][0]['quantity'] = 0; // VO refuses non-positive

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/orders/replay-offline', $body)
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'OFFLINE_SELECTION_MALFORMED');
});

it('requires a workstation device token', function () {
    $this->postJson('/api/v1/workstation/orders/replay-offline', signedReplayBody(wireSelection()))
        ->assertUnauthorized();
});
