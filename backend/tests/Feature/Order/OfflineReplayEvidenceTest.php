<?php

use App\Exceptions\OfflineEvidenceRejected;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CatalogRevision;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuProductToppingItemOverride;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroup;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Services\Catalog\CatalogRevisionService;
use App\Services\Customer\CustomerOrderService;
use App\Services\Device\DeviceSigningKeyService;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Commands\CreateOrderCommand;
use App\Services\Order\Commands\ReplayOfflineOrderCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Offline\OfflineOrderSigningMessage as Msg;
use App\Services\Order\ValueObjects\OfflineOrderEvidence;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;
use App\Services\Order\ValueObjects\OrderSelectionPayload;
use App\Services\Order\ValueObjects\OrderToppingSelectionPayload;
use Illuminate\Support\Str;

/*
 * #1096 + #1097 — signed offline-order replay, end to end.
 *
 * This is the gate that decides whether Cloud believes money a device collected
 * while disconnected, so every test here is a REJECTION the system must make,
 * or a proof that an honest sale is priced from the catalog AS SOLD.
 *
 * The device never asserts money: it signs "device D sold selection S against
 * catalog revision R". Cloud re-prices S from R's immutable snapshot. So the
 * headline test is not "the total matches what the device said" — it is "the
 * total is what R's prices imply, even after the live menu moved".
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

    $this->standard = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'code' => 'STANDARD', 'rate' => 10, 'is_default' => true,
    ]);
    ShopOrderSetting::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'default_tax_type_id' => $this->standard->id,
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
        'product_type_id' => $pt->id, 'tax_type_id' => $this->standard->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id, 'selling_price' => 1000, 'is_active' => true,
    ]);

    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id, 'status' => 'Active',
    ]);
    $line = MenuProduct::factory()->create([
        'menu_id' => $menu->id, 'product_id' => $product->id, 'is_active' => true, 'tax_type_id' => null,
    ]);
    $this->menuSku = MenuProductSku::factory()->create([
        'menu_product_id' => $line->id, 'product_sku_id' => $this->sku->id,
        'selling_price' => 1000, 'is_price_overridden' => true, 'is_active' => true,
    ]);

    // The device + its registered signing key.
    $this->device = Device::factory()->create([
        'type' => 'workstation', 'status' => 'active',
        'organization_id' => $this->orgId, 'branch_id' => $this->branch->id,
        'device_token' => Str::random(64), 'pairing_code' => null,
    ]);
    $keypair = sodium_crypto_sign_keypair();
    $this->secretKey = sodium_crypto_sign_secretkey($keypair);
    $this->signingKey = app(DeviceSigningKeyService::class)->issue(
        $this->device,
        base64_encode(sodium_crypto_sign_publickey($keypair)),
    );
    // The device paired a week ago — so evidence dated in the recent past is
    // legitimately inside the key's validity window (a key issued "now" could
    // never have signed a sale that happened earlier).
    $this->signingKey->update(['issued_at' => now()->subDays(7)]);
    $this->signingKey->refresh();

    $this->revision = app(CatalogRevisionService::class)->currentFor($this->branch->id);
    expect($this->revision)->not->toBeNull('the menu build must have minted a catalog revision');

    $this->facade = app(OrderMutationFacade::class);
});

/** Build a signed replay command for `qty` units of the seeded menu line. */
function signedReplay(array $overrides = []): ReplayOfflineOrderCommand
{
    $deviceId = $overrides['device_id'] ?? (string) test()->device->id;
    $selection = $overrides['selection'] ?? new OrderSelectionPayload(
        lines: [new OrderLineSelectionPayload(
            (string) Str::uuid(),
            (string) test()->menuSku->id,
            $overrides['quantity'] ?? 2,
        )],
        deviceId: $deviceId,
    );

    $issuedAt = $overrides['issued_at'] ?? now()->subMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z');
    $expiresAt = $overrides['expires_at'] ?? now()->addHours(60)->utc()->format('Y-m-d\TH:i:s\Z');
    $revision = $overrides['catalog_revision'] ?? test()->revision->revision;
    $keyId = $overrides['key_id'] ?? (string) test()->signingKey->id;
    $issuerId = $overrides['issuer_id'] ?? $deviceId;

    $message = Msg::message($deviceId, $issuerId, $revision, $issuedAt, $expiresAt, $keyId, Msg::selectionDigest($selection));
    $signature = $overrides['signature'] ?? base64_encode(
        sodium_crypto_sign_detached($message, $overrides['secret_key'] ?? test()->secretKey),
    );

    $evidence = new OfflineOrderEvidence(
        $deviceId,
        $issuerId,
        $revision,
        $issuedAt,
        $expiresAt,
        $keyId,
        $signature,
    );

    return new ReplayOfflineOrderCommand(
        new MutationContext(test()->orgId, null, (string) Str::uuid(), (string) Str::uuid()),
        $overrides['order_id'] ?? (string) Str::uuid(),
        $overrides['branch_id'] ?? (string) test()->branch->id,
        $selection,
        $selection->fingerprint(),
        $evidence,
    );
}

// =========================================================================
//  The honest sale
// =========================================================================

it('accepts an honestly signed offline sale and bills it from the catalog AS SOLD: 2 x ¥1,000 + 10% = ¥2,200', function () {
    $result = $this->facade->replayOffline(signedReplay());

    $order = CustomerOrder::findOrFail($result->orderId);
    expect((float) $order->subtotal)->toBe(2000.0)
        ->and((float) $order->tax_amount)->toBe(200.0)
        ->and((float) $order->total_amount)->toBe(2200.0)
        ->and($order->items)->toHaveCount(1)
        ->and((float) $order->items->first()->unit_price)->toBe(1000.0)
        ->and((float) $order->items->first()->tax_rate)->toBe(10.0);
});

it('prices from the OLD revision after the live menu was re-priced — the customer already paid the old price', function () {
    $soldAtRevision = $this->revision->revision;

    // The shop doubles the price after the offline sale but before the sync.
    $this->menuSku->update(['selling_price' => 2000]);
    expect(app(CatalogRevisionService::class)->currentFor($this->branch->id)->revision)
        ->toBeGreaterThan($soldAtRevision);

    $result = $this->facade->replayOffline(signedReplay(['catalog_revision' => $soldAtRevision]));

    // ¥1,000 (as sold), NOT the ¥2,000 now on the menu.
    $order = CustomerOrder::findOrFail($result->orderId);
    expect((float) $order->subtotal)->toBe(2000.0)
        ->and((float) $order->total_amount)->toBe(2200.0)
        ->and((float) $order->items->first()->unit_price)->toBe(1000.0);
});

it('is idempotent on the order id — a retried sync makes one order, not two', function () {
    $orderId = (string) Str::uuid();
    $selection = new OrderSelectionPayload(
        lines: [new OrderLineSelectionPayload((string) Str::uuid(), (string) $this->menuSku->id, 1)],
        deviceId: (string) $this->device->id,
    );

    $first = $this->facade->replayOffline(signedReplay(['order_id' => $orderId, 'selection' => $selection]));
    $second = $this->facade->replayOffline(signedReplay(['order_id' => $orderId, 'selection' => $selection]));

    expect($second->orderId)->toBe($first->orderId)
        ->and(CustomerOrder::count())->toBe(1);
});

// =========================================================================
//  Key rejections
// =========================================================================

it('refuses evidence signed by a REVOKED key — a compromised device cannot bill retroactively', function () {
    app(DeviceSigningKeyService::class)->revoke($this->signingKey, 'tablet stolen');

    expect(fn () => $this->facade->replayOffline(signedReplay()))
        ->toThrow(OfflineEvidenceRejected::class);

    try {
        $this->facade->replayOffline(signedReplay());
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('signing_key_revoked')
            ->and($e->getMessage())->toContain('tablet stolen');
    }

    expect(CustomerOrder::count())->toBe(0);
});

it('refuses an unknown key id, and a key belonging to ANOTHER device', function () {
    try {
        $this->facade->replayOffline(signedReplay(['key_id' => (string) Str::uuid()]));
        $this->fail('an unknown key was accepted');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('unknown_signing_key');
    }

    // A second device with its own key; the first device signs with that key id.
    $otherDevice = Device::factory()->create([
        'type' => 'workstation', 'status' => 'active',
        'organization_id' => $this->orgId, 'branch_id' => $this->branch->id,
        'device_token' => Str::random(64), 'pairing_code' => null,
    ]);
    $otherKey = app(DeviceSigningKeyService::class)->issue(
        $otherDevice,
        base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
    );

    try {
        $this->facade->replayOffline(signedReplay(['key_id' => (string) $otherKey->id]));
        $this->fail("another device's key was accepted");
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('signing_key_device_mismatch');
    }

    expect(CustomerOrder::count())->toBe(0);
});

it('refuses a key that had not been issued yet at the claimed sale time', function () {
    // The claim predates the key's own issuance.
    $issuedAt = now()->subDays(30)->utc()->format('Y-m-d\TH:i:s\Z');

    try {
        $this->facade->replayOffline(signedReplay([
            'issued_at' => $issuedAt,
            'expires_at' => now()->subDays(29)->utc()->format('Y-m-d\TH:i:s\Z'),
        ]));
        $this->fail('evidence predating the key was accepted');
    } catch (OfflineEvidenceRejected $e) {
        // Expiry is checked before key-window; either rejection is fail-closed.
        expect($e->reasonCode)->toBeIn(['evidence_expired', 'signing_key_not_valid_at_issue']);
    }

    expect(CustomerOrder::count())->toBe(0);
});

// =========================================================================
//  Signature rejections
// =========================================================================

it('refuses a FORGED signature and a signature from a foreign keypair', function () {
    try {
        $this->facade->replayOffline(signedReplay(['signature' => base64_encode(str_repeat("\0", 64))]));
        $this->fail('a forged signature was accepted');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('signature_invalid');
    }

    // Correctly formed signature, wrong private key.
    $foreign = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    try {
        $this->facade->replayOffline(signedReplay(['secret_key' => $foreign]));
        $this->fail('a foreign-key signature was accepted');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('signature_invalid');
    }

    expect(CustomerOrder::count())->toBe(0);
});

it('refuses when the SELECTION was altered after signing — the classic quantity rewrite', function () {
    $signed = signedReplay(['quantity' => 1]);

    // Same envelope + signature, but 99 units instead of 1.
    $tampered = new ReplayOfflineOrderCommand(
        new MutationContext($this->orgId, null, (string) Str::uuid(), (string) Str::uuid()),
        (string) Str::uuid(),
        (string) $this->branch->id,
        $rewritten = new OrderSelectionPayload(
            lines: [new OrderLineSelectionPayload((string) Str::uuid(), (string) $this->menuSku->id, 99)],
            deviceId: (string) $this->device->id,
        ),
        $rewritten->fingerprint(),
        $signed->evidence,
    );

    try {
        $this->facade->replayOffline($tampered);
        $this->fail('a rewritten order was accepted');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('signature_invalid');
    }

    expect(CustomerOrder::count())->toBe(0);
});

it('refuses when the claimed CATALOG REVISION was swapped for a cheaper one after signing', function () {
    // Two revisions exist: the ¥1,000 one and a later ¥500 one.
    $expensive = $this->revision->revision;
    $this->menuSku->update(['selling_price' => 500]);
    $cheap = app(CatalogRevisionService::class)->currentFor($this->branch->id)->revision;

    $signed = signedReplay(['catalog_revision' => $expensive]);
    $swapped = new ReplayOfflineOrderCommand(
        new MutationContext($this->orgId, null, (string) Str::uuid(), (string) Str::uuid()),
        (string) Str::uuid(),
        (string) $this->branch->id,
        $signed->payload,
        $signed->selectionFingerprint,
        new OfflineOrderEvidence(
            $signed->evidence->deviceId,
            $signed->evidence->issuerId,
            $cheap, // ← swapped
            $signed->evidence->issuedAt,
            $signed->evidence->expiresAt,
            $signed->evidence->keyId,
            $signed->evidence->signature,
        ),
    );

    try {
        $this->facade->replayOffline($swapped);
        $this->fail('a swapped catalog revision was accepted');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('signature_invalid');
    }
});

// =========================================================================
//  Freshness + window rejections
// =========================================================================

it('refuses EXPIRED evidence — Cloud will not price a sale it can no longer date', function () {
    try {
        $this->facade->replayOffline(signedReplay([
            'issued_at' => now()->subDays(5)->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => now()->subDays(4)->utc()->format('Y-m-d\TH:i:s\Z'),
        ]));
        $this->fail('expired evidence was accepted');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('evidence_expired')
            ->and($e->getMessage())->toContain('manually');
    }
});

it('refuses evidence dated in the FUTURE beyond the clock-skew allowance', function () {
    try {
        $this->facade->replayOffline(signedReplay([
            'issued_at' => now()->addHours(2)->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => now()->addHours(50)->utc()->format('Y-m-d\TH:i:s\Z'),
        ]));
        $this->fail('future-dated evidence was accepted');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('evidence_issued_in_future');
    }
});

it('refuses a device that granted itself an over-wide offline window', function () {
    try {
        $this->facade->replayOffline(signedReplay([
            'issued_at' => now()->subHours(1)->utc()->format('Y-m-d\TH:i:s\Z'),
            // 30 days > the 72h ceiling.
            'expires_at' => now()->addDays(30)->utc()->format('Y-m-d\TH:i:s\Z'),
        ]));
        $this->fail('an unbounded offline window was accepted');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('evidence_window_too_wide');
    }
});

// =========================================================================
//  Device / tenant / catalog rejections
// =========================================================================

it('refuses a device selling into a branch it does not belong to', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    try {
        $this->facade->replayOffline(signedReplay(['branch_id' => (string) $otherBranch->id]));
        $this->fail('a cross-branch replay was accepted');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBeIn(['device_branch_mismatch', 'unknown_catalog_revision']);
    }

    expect(CustomerOrder::count())->toBe(0);
});

it('refuses a catalog revision that never existed', function () {
    try {
        $this->facade->replayOffline(signedReplay(['catalog_revision' => 9999]));
        $this->fail('a bogus catalog revision was accepted');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('unknown_catalog_revision');
    }
});

it('refuses to price against a revision whose snapshot was mutated in place', function () {
    // Someone edits the stored snapshot to halve the price.
    $snapshot = (array) $this->revision->snapshot;
    $snapshot[(string) $this->menuSku->id]['price'] = '1.00';
    CatalogRevision::whereKey($this->revision->id)->update(['snapshot' => json_encode($snapshot)]);

    try {
        $this->facade->replayOffline(signedReplay());
        $this->fail('a tampered snapshot was used to price money');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('catalog_revision_corrupt');
    }

    expect(CustomerOrder::count())->toBe(0);
});

it('refuses a menu line that was not sellable at the claimed revision', function () {
    $foreignMenuSku = (string) Str::uuid();
    $selection = new OrderSelectionPayload(
        lines: [new OrderLineSelectionPayload((string) Str::uuid(), $foreignMenuSku, 1)],
        deviceId: (string) $this->device->id,
    );

    try {
        $this->facade->replayOffline(signedReplay(['selection' => $selection]));
        $this->fail('a line absent from the revision was accepted');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('offline_line_absent_from_revision');
    }
});

// =========================================================================
//  Documented capability limits — refused, never mis-priced
// =========================================================================

it('refuses an off-menu line: with no recorded historical price there is nothing to verify', function () {
    $selection = new OrderSelectionPayload(
        lines: [new OrderLineSelectionPayload((string) Str::uuid(), null, 1, [], null, (string) $this->sku->id)],
        deviceId: (string) $this->device->id,
    );

    try {
        $this->facade->replayOffline(signedReplay(['selection' => $selection]));
        $this->fail('an off-menu offline line was accepted');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('offline_line_not_menu_anchored');
    }
});

/**
 * Attach a topping group to the parent product with one priced item, and
 * return [itemId, toppingSkuId]. Mirrors the live catalog shape: the price
 * lives on topping_group_item_skus (per-SKU row or NULL fallback).
 *
 * @return array{0: string, 1: string}
 */
function attachToppingTo(string $parentProductId, float $extraPrice, string $strategy = 'flat', int $freeQuantity = 0): array
{
    $group = ToppingGroup::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'price_strategy' => $strategy,
        'free_quantity' => $freeQuantity,
        'is_active' => true,
        'min_select' => 0,
        'max_select' => 5,
    ]);
    ProductToppingGroup::factory()->create([
        'product_id' => $parentProductId,
        'topping_group_id' => $group->id,
    ]);

    $toppingProduct = Product::factory()->active()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'product_type_id' => Product::findOrFail($parentProductId)->product_type_id,
    ]);
    $toppingSku = ProductSku::factory()->create([
        'product_id' => $toppingProduct->id, 'selling_price' => 0, 'is_active' => true,
    ]);
    $item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $group->id,
        'product_id' => $toppingProduct->id,
        'is_default' => false,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $item->id,
        'product_sku_id' => $toppingSku->id,
        'extra_price' => $extraPrice,
    ]);

    return [(string) $item->id, (string) $toppingSku->id];
}

/** A selection whose single line carries the given toppings. */
function selectionWithToppings(array $toppings, int $quantity = 1): OrderSelectionPayload
{
    return new OrderSelectionPayload(
        lines: [new OrderLineSelectionPayload(
            (string) Str::uuid(),
            (string) test()->menuSku->id,
            $quantity,
            $toppings,
        )],
        deviceId: (string) test()->device->id,
    );
}

it('prices toppings from the revision: ¥1,000 line + ¥150 topping, x2 = ¥2,300 + 10% = ¥2,530 (#1114)', function () {
    [$itemId, $toppingSkuId] = attachToppingTo((string) $this->sku->product_id, 150.0);
    // The attachment changed what an offline device would charge → new revision.
    $revision = app(CatalogRevisionService::class)->currentFor($this->branch->id);

    $selection = selectionWithToppings([
        new OrderToppingSelectionPayload($itemId, $toppingSkuId, 1),
    ], quantity: 2);

    $result = $this->facade->replayOffline(signedReplay([
        'selection' => $selection,
        'catalog_revision' => $revision->revision,
    ]));

    $order = CustomerOrder::findOrFail($result->orderId);
    expect((float) $order->subtotal)->toBe(2300.0)
        ->and((float) $order->tax_amount)->toBe(230.0)
        ->and((float) $order->total_amount)->toBe(2530.0);

    $item = $order->items->first();
    expect((float) $item->unit_price)->toBe(1000.0)
        // topping_subtotal is stored PER UNIT (the live path's semantic); the
        // x2 shows up in the order subtotal above.
        ->and((float) $item->topping_subtotal)->toBe(150.0)
        ->and($item->orderItemToppings)->toHaveCount(1)
        ->and((float) $item->orderItemToppings->first()->unit_price)->toBe(150.0);
});

it('keeps the OLD topping price after the shop re-prices the topping — a paid bill is never rewritten (#1114)', function () {
    [$itemId, $toppingSkuId] = attachToppingTo((string) $this->sku->product_id, 150.0);
    $soldAt = app(CatalogRevisionService::class)->currentFor($this->branch->id)->revision;

    // The shop triples the topping price after the offline sale.
    ToppingGroupItemSku::query()
        ->where('topping_group_item_id', $itemId)
        ->update(['extra_price' => 450]);
    // Force a fresh revision so the live catalog genuinely moved.
    app(CatalogRevisionService::class)->bumpFor((string) $this->branch->id);

    $result = $this->facade->replayOffline(signedReplay([
        'selection' => selectionWithToppings([
            new OrderToppingSelectionPayload($itemId, $toppingSkuId, 1),
        ]),
        'catalog_revision' => $soldAt,
    ]));

    // ¥1,000 + ¥150 (as sold) + 10% = ¥1,265 — not the ¥450 topping price now live.
    $order = CustomerOrder::findOrFail($result->orderId);
    expect((float) $order->subtotal)->toBe(1150.0)
        ->and((float) $order->total_amount)->toBe(1265.0)
        ->and((float) $order->items->first()->orderItemToppings->first()->unit_price)->toBe(150.0);
});

it('applies the revision-recorded free_up_to_n discount, not a re-derived one (#1114)', function () {
    // Group waives the first unit: 3 units @ ¥100 → only 2 charged.
    [$itemId, $toppingSkuId] = attachToppingTo((string) $this->sku->product_id, 100.0, 'free_up_to_n', 1);
    $revision = app(CatalogRevisionService::class)->currentFor($this->branch->id);

    $result = $this->facade->replayOffline(signedReplay([
        'selection' => selectionWithToppings([
            new OrderToppingSelectionPayload($itemId, $toppingSkuId, 3),
        ]),
        'catalog_revision' => $revision->revision,
    ]));

    // ¥1,000 + (3 units − 1 free) × ¥100 = ¥1,200 + 10% = ¥1,320.
    $order = CustomerOrder::findOrFail($result->orderId);
    expect((float) $order->subtotal)->toBe(1200.0)
        ->and((float) $order->total_amount)->toBe(1320.0);
});

it('prices a topping at the SHOP override, not the HQ base — the #1192 false rejection', function () {
    // Shop A re-prices "trân châu" to ¥250 on its own menu line; HQ base is ¥150.
    // The POS (Go) and the online pricer both charge ¥250, so a snapshot that
    // only recorded the HQ answer re-priced the sale LOW and refused it as
    // tampered — an honest offline order, blocked, at every shop that ever used
    // the shop-override feature.
    [$itemId, $toppingSkuId] = attachToppingTo((string) $this->sku->product_id, 150.0);
    $groupId = (string) ToppingGroupItem::findOrFail($itemId)->topping_group_id;

    MenuProductToppingItemOverride::create([
        'menu_product_id' => (string) $this->menuSku->menu_product_id,
        'topping_group_id' => $groupId,
        'topping_group_item_id' => $itemId,
        'product_sku_id' => $toppingSkuId,
        'is_hidden' => false,
        'override_price' => 250,
    ]);

    // Writing the override must itself version the catalog: it changed what
    // this branch charges offline.
    $revision = app(CatalogRevisionService::class)->currentFor($this->branch->id);
    $snapshot = (array) $revision->snapshot;
    expect((int) $snapshot['v'])->toBe(CatalogRevisionService::SNAPSHOT_VERSION)
        ->and($snapshot['topping_price_overrides'])
        ->toHaveKey($this->menuSku->menu_product_id.'|'.$itemId.'|'.$toppingSkuId)
        ->and($snapshot['topping_price_overrides'][$this->menuSku->menu_product_id.'|'.$itemId.'|'.$toppingSkuId])
        ->toBe('250.00');

    $result = $this->facade->replayOffline(signedReplay([
        'selection' => selectionWithToppings([
            new OrderToppingSelectionPayload($itemId, $toppingSkuId, 1),
        ]),
        'catalog_revision' => $revision->revision,
    ]));

    // ¥1,000 + ¥250 (shop price, as sold) + 10% = ¥1,375 — NOT ¥1,265 at HQ's ¥150.
    $order = CustomerOrder::findOrFail($result->orderId);
    expect((float) $order->subtotal)->toBe(1250.0)
        ->and((float) $order->total_amount)->toBe(1375.0)
        ->and((float) $order->items->first()->orderItemToppings->first()->unit_price)->toBe(250.0);
});

it('keeps the shop override price as sold after the shop re-prices it again (#1192)', function () {
    [$itemId, $toppingSkuId] = attachToppingTo((string) $this->sku->product_id, 150.0);
    $groupId = (string) ToppingGroupItem::findOrFail($itemId)->topping_group_id;

    $override = MenuProductToppingItemOverride::create([
        'menu_product_id' => (string) $this->menuSku->menu_product_id,
        'topping_group_id' => $groupId,
        'topping_group_item_id' => $itemId,
        'product_sku_id' => $toppingSkuId,
        'is_hidden' => false,
        'override_price' => 250,
    ]);
    $soldAt = app(CatalogRevisionService::class)->currentFor($this->branch->id);

    // The shop doubles it AFTER the offline sale — history must not move.
    $override->update(['override_price' => 500]);

    $result = $this->facade->replayOffline(signedReplay([
        'selection' => selectionWithToppings([
            new OrderToppingSelectionPayload($itemId, $toppingSkuId, 1),
        ]),
        'catalog_revision' => $soldAt->revision,
    ]));

    $order = CustomerOrder::findOrFail($result->orderId);
    expect((float) $order->subtotal)->toBe(1250.0)
        ->and((float) $order->total_amount)->toBe(1375.0);
});

it('falls back to the HQ price for menu lines the shop never overrode (#1192)', function () {
    // One branch, two menu lines: only the FIRST is overridden. The second must
    // still price from the product-keyed map, not inherit its neighbour's price.
    [$itemId, $toppingSkuId] = attachToppingTo((string) $this->sku->product_id, 150.0);
    $groupId = (string) ToppingGroupItem::findOrFail($itemId)->topping_group_id;

    $otherLine = MenuProduct::factory()->create([
        'menu_id' => $this->menuSku->menuProduct->menu_id,
        'product_id' => $this->sku->product_id,
        'is_active' => true,
        'tax_type_id' => null,
    ]);
    $otherMenuSku = MenuProductSku::factory()->create([
        'menu_product_id' => $otherLine->id,
        'product_sku_id' => $this->sku->id,
        'selling_price' => 1000,
        'is_price_overridden' => true,
        'is_active' => true,
    ]);

    MenuProductToppingItemOverride::create([
        'menu_product_id' => (string) $this->menuSku->menu_product_id,
        'topping_group_id' => $groupId,
        'topping_group_item_id' => $itemId,
        'product_sku_id' => $toppingSkuId,
        'is_hidden' => false,
        'override_price' => 250,
    ]);

    $revision = app(CatalogRevisionService::class)->currentFor($this->branch->id);

    $selection = new OrderSelectionPayload(
        lines: [new OrderLineSelectionPayload(
            (string) Str::uuid(),
            (string) $otherMenuSku->id,
            1,
            [new OrderToppingSelectionPayload($itemId, $toppingSkuId, 1)],
        )],
        deviceId: (string) $this->device->id,
    );

    $result = $this->facade->replayOffline(signedReplay([
        'selection' => $selection,
        'catalog_revision' => $revision->revision,
    ]));

    // ¥1,000 + ¥150 (HQ base) + 10% = ¥1,265.
    $order = CustomerOrder::findOrFail($result->orderId);
    expect((float) $order->subtotal)->toBe(1150.0)
        ->and((float) $order->total_amount)->toBe(1265.0);
});

it('refuses a topping that was not sellable at the claimed revision', function () {
    [$itemId, $toppingSkuId] = attachToppingTo((string) $this->sku->product_id, 150.0);
    $revision = app(CatalogRevisionService::class)->currentFor($this->branch->id);

    // A topping id nobody ever recorded.
    try {
        $this->facade->replayOffline(signedReplay([
            'selection' => selectionWithToppings([
                new OrderToppingSelectionPayload((string) Str::uuid(), $toppingSkuId, 1),
            ]),
            'catalog_revision' => $revision->revision,
        ]));
        $this->fail('an unrecorded topping was accepted');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('offline_topping_absent_from_revision');
    }

    // A recorded topping, but paired with a SKU that has no recorded price.
    try {
        $this->facade->replayOffline(signedReplay([
            'selection' => selectionWithToppings([
                new OrderToppingSelectionPayload($itemId, (string) $this->sku->id, 1),
            ]),
            'catalog_revision' => $revision->revision,
        ]));
        $this->fail('a topping with no recorded price was accepted');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('offline_topping_price_unknown')
            ->and($e->getMessage())->toContain('assuming zero');
    }

    expect(CustomerOrder::count())->toBe(0);
});

it('refuses toppings against a LEGACY v1 revision — silence about toppings must never read as free', function () {
    [$itemId, $toppingSkuId] = attachToppingTo((string) $this->sku->product_id, 150.0);
    $revision = app(CatalogRevisionService::class)->currentFor($this->branch->id);

    // Rewrite the stored snapshot into the old v1 (lines-only, flat) shape and
    // re-hash it so only the SHAPE is old, not the integrity.
    $v1 = (array) $revision->snapshot;
    $lines = $v1['lines'];
    CatalogRevision::whereKey($revision->id)->update([
        'snapshot' => json_encode($lines),
        'snapshot_hash' => app(CatalogRevisionService::class)->hashSnapshot($lines),
    ]);

    try {
        $this->facade->replayOffline(signedReplay([
            'selection' => selectionWithToppings([
                new OrderToppingSelectionPayload($itemId, $toppingSkuId, 1),
            ]),
            'catalog_revision' => $revision->revision,
        ]));
        $this->fail('toppings were priced against a v1 revision');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('offline_toppings_unsupported')
            ->and($e->getMessage())->toContain('legacy path');
    }
});

it('still prices a topping-FREE line against a legacy v1 revision (back-compat)', function () {
    $revision = app(CatalogRevisionService::class)->currentFor($this->branch->id);
    $lines = ((array) $revision->snapshot)['lines'];
    CatalogRevision::whereKey($revision->id)->update([
        'snapshot' => json_encode($lines),
        'snapshot_hash' => app(CatalogRevisionService::class)->hashSnapshot($lines),
    ]);

    $result = $this->facade->replayOffline(signedReplay(['catalog_revision' => $revision->revision]));

    expect((float) CustomerOrder::findOrFail($result->orderId)->total_amount)->toBe(2200.0);
});

// =========================================================================
//  #1091 — an offline sale is dated by WHEN IT HAPPENED
// =========================================================================

it('dates an offline order by the SIGNED sale instant, not by when the sync arrived', function () {
    // The sale happened 14 hours ago (shop was offline overnight); the sync is
    // only landing now.
    $soldAt = now()->subHours(14)->utc()->format('Y-m-d\TH:i:s\Z');

    $result = $this->facade->replayOffline(signedReplay([
        'issued_at' => $soldAt,
        'expires_at' => now()->addHours(40)->utc()->format('Y-m-d\TH:i:s\Z'),
    ]));

    $order = CustomerOrder::findOrFail($result->orderId);

    // Pre-#1091 opened_at was stamped with Cloud's receipt time, dropping the
    // tamper-proof instant the device had already signed and dating the sale
    // into the wrong business day.
    expect($order->opened_at->utc()->format('Y-m-d\TH:i:s\Z'))->toBe($soldAt)
        ->and($order->opened_at->lessThan(now()->subHours(13)))->toBeTrue();
});

it('cannot be backdated without breaking the signature — the sale instant is signed', function () {
    $signed = signedReplay();

    // Same signature, but the envelope now claims the sale happened a day ago
    // (a device trying to move revenue into yesterday's business day).
    $backdated = new ReplayOfflineOrderCommand(
        new MutationContext($this->orgId, null, (string) Str::uuid(), (string) Str::uuid()),
        (string) Str::uuid(),
        (string) $this->branch->id,
        $signed->payload,
        $signed->selectionFingerprint,
        new OfflineOrderEvidence(
            $signed->evidence->deviceId,
            $signed->evidence->issuerId,
            $signed->evidence->catalogRevision,
            // Moved only 2 hours so the 72h window guard stays satisfied and
            // the SIGNATURE is what catches the backdating.
            now()->subHours(2)->utc()->format('Y-m-d\TH:i:s\Z'),
            $signed->evidence->expiresAt,
            $signed->evidence->keyId,
            $signed->evidence->signature,
        ),
    );

    try {
        $this->facade->replayOffline($backdated);
        $this->fail('a backdated sale instant was accepted');
    } catch (OfflineEvidenceRejected $e) {
        expect($e->reasonCode)->toBe('signature_invalid');
    }

    expect(CustomerOrder::count())->toBe(0);
});

it('still dates an ONLINE order at the moment of sale (no regression for the normal path)', function () {
    $order = app(CustomerOrderService::class)->create([
        'order_type' => 'dine_in',
        'branch_id' => (string) $this->branch->id,
        'brand_id' => (string) $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    expect($order->opened_at->diffInSeconds(now(), absolute: true))->toBeLessThan(5);
});

//  #1114 step 4 — online/offline parity: same topping basket, same yen
// =========================================================================

it('bills a topping basket IDENTICALLY online and via offline replay (#1114 parity)', function () {
    [$itemId, $toppingSkuId] = attachToppingTo((string) $this->sku->product_id, 150.0);
    $revision = app(CatalogRevisionService::class)->currentFor($this->branch->id);

    // Online — the typed create prices through the LIVE ToppingSelectionPricer.
    $onlineSel = selectionWithToppings([
        new OrderToppingSelectionPayload($itemId, $toppingSkuId, 1),
    ], quantity: 2);
    $onlineId = $this->facade->create(new CreateOrderCommand(
        new MutationContext($this->orgId, null, (string) Str::uuid(), (string) Str::uuid(), 1),
        (string) Str::uuid(),
        (string) $this->branch->id,
        $onlineSel,
        $onlineSel->fingerprint(),
    ))->orderId;

    // Offline — the verifier prices the SAME basket from the revision snapshot.
    $offlineId = $this->facade->replayOffline(signedReplay([
        'selection' => selectionWithToppings([
            new OrderToppingSelectionPayload($itemId, $toppingSkuId, 1),
        ], quantity: 2),
        'catalog_revision' => $revision->revision,
    ]))->orderId;

    $online = CustomerOrder::findOrFail($onlineId);
    $offline = CustomerOrder::findOrFail($offlineId);

    foreach (['subtotal', 'discount_amount', 'service_charge', 'tax_amount', 'total_amount'] as $column) {
        expect((float) $offline->{$column})->toBe(
            (float) $online->{$column},
            "offline {$column} diverged from the identical online basket",
        );
    }

    $onlineItem = $online->items->first();
    $offlineItem = $offline->items->first();
    expect((float) $offlineItem->unit_price)->toBe((float) $onlineItem->unit_price)
        ->and((float) $offlineItem->topping_subtotal)->toBe((float) $onlineItem->topping_subtotal)
        ->and((float) $offlineItem->tax_amount)->toBe((float) $onlineItem->tax_amount)
        ->and((float) $offlineItem->orderItemToppings->first()->unit_price)
        ->toBe((float) $onlineItem->orderItemToppings->first()->unit_price);
});
