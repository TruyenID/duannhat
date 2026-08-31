<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PeripheralDevice;
use App\Models\TillTenderType;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * #1156 — `metadata.accepts` on payment terminals / coin changers.
 *
 * The accepts list is the per-device subset of the org's tender vocabulary
 * (till_tender_types, org-level rows): every key must reference an existing
 * ACTIVE org-wide tender. When a payment_terminal is registered WITHOUT
 * accepts but WITH a metadata.model matching a vendor template
 * (config/tender_templates.php), the template prefills accepts — intersected
 * with the org vocabulary so the invariant holds either way.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'accepts-shop',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/shops/{$this->shop->slug}/peripheral-devices";
});

/**
 * Seed a slice of the org-wide tender vocabulary (branch_id NULL).
 */
function seedAcceptsVocabulary(array $keys, bool $isActive = true): void
{
    foreach ($keys as $key) {
        TillTenderType::factory()->create([
            'organization_id' => test()->orgId,
            'branch_id' => null,
            'tender_key' => $key,
            'name' => ucfirst($key),
            'category' => 'qr',
            'is_active' => $isActive,
        ]);
    }
}

function terminalPayload(array $metadata, string $type = 'payment_terminal', string $name = 'Register terminal'): array
{
    return [
        'name' => $name,
        'type' => $type,
        'metadata' => array_merge(['host' => '192.168.1.50'], $metadata),
    ];
}

// =========================================================================
//  metadata.accepts validation
// =========================================================================

it('accepts a payment_terminal with a valid accepts list and persists it', function () {
    seedAcceptsVocabulary(['credit', 'paypay', 'id']);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['accepts' => ['credit', 'paypay']]))
        ->assertCreated();

    $device = PeripheralDevice::query()->latest('created_at')->first();
    expect($device->metadata['accepts'])->toBe(['credit', 'paypay']);
});

it('rejects an accepts key that does not exist in the org vocabulary', function () {
    seedAcceptsVocabulary(['credit']);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['accepts' => ['credit', 'not_a_tender']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metadata.accepts.1']);

    expect(PeripheralDevice::count())->toBe(0);
});

it('rejects an accepts key whose vocabulary row is inactive', function () {
    seedAcceptsVocabulary(['credit']);
    seedAcceptsVocabulary(['ghost_pay'], isActive: false);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['accepts' => ['ghost_pay']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metadata.accepts.0']);
});

it('rejects an accepts key that only exists as a branch-scoped row (accepts is org-vocabulary only)', function () {
    TillTenderType::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'tender_key' => 'shop_voucher',
        'category' => 'qr',
        'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['accepts' => ['shop_voucher']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metadata.accepts.0']);
});

it('rejects an accepts key belonging to another organization', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    TillTenderType::factory()->create([
        'organization_id' => $otherOrgId,
        'branch_id' => null,
        'tender_key' => 'foreign_pay',
        'category' => 'qr',
        'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['accepts' => ['foreign_pay']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metadata.accepts.0']);
});

it('rejects duplicate keys in accepts', function () {
    seedAcceptsVocabulary(['credit']);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['accepts' => ['credit', 'credit']]))
        ->assertUnprocessable();
});

it('validates accepts on coin_changer registrations too', function () {
    seedAcceptsVocabulary(['cash']);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['accepts' => ['unknown']], type: 'coin_changer', name: 'Glory changer'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metadata.accepts.0']);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['accepts' => ['cash']], type: 'coin_changer', name: 'Glory changer'))
        ->assertCreated();
});

it('still accepts a payment_terminal without any accepts or model (legacy shape unchanged)', function () {
    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload([]))
        ->assertCreated();

    $device = PeripheralDevice::query()->latest('created_at')->first();
    expect($device->metadata)->not->toHaveKey('accepts');
});

it('validates accepts on update when metadata is part of the request', function () {
    seedAcceptsVocabulary(['credit']);

    $device = PeripheralDevice::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'name' => 'Existing terminal',
        'type' => 'payment_terminal',
        'metadata' => ['host' => '192.168.1.50'],
    ]);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$device->id}", [
            'metadata' => ['host' => '192.168.1.50', 'accepts' => ['bogus']],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metadata.accepts.0']);

    $this->actingAs($this->user)
        ->putJson("{$this->base}/{$device->id}", [
            'metadata' => ['host' => '192.168.1.50', 'accepts' => ['credit']],
        ])
        ->assertOk();

    expect($device->fresh()->metadata['accepts'])->toBe(['credit']);
});

// =========================================================================
//  Template prefill (metadata.model)
// =========================================================================

it('prefills accepts from the stera template when model matches and accepts is absent', function () {
    seedAcceptsVocabulary([
        'credit', 'paypay', 'rakuten_pay', 'd_barai', 'au_pay', 'merpay',
        'id', 'ic', 'edy', 'waon', 'nanaco', 'quicpay',
    ]);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['model' => 'Stera']))
        ->assertCreated();

    $device = PeripheralDevice::query()->latest('created_at')->first();
    expect($device->metadata['accepts'])->toBe([
        'credit', 'paypay', 'rakuten_pay', 'd_barai', 'au_pay', 'merpay',
        'id', 'ic', 'edy', 'waon', 'nanaco', 'quicpay',
    ]);
});

it('prefills accepts from the starpay template', function () {
    seedAcceptsVocabulary(['credit', 'paypay', 'au_pay', 'wechat_pay', 'alipay', 'unionpay']);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['model' => 'StarPay']))
        ->assertCreated();

    $device = PeripheralDevice::query()->latest('created_at')->first();
    expect($device->metadata['accepts'])
        ->toBe(['credit', 'paypay', 'au_pay', 'wechat_pay', 'alipay', 'unionpay']);
});

it('matches a template when the slug is contained in a longer model string', function () {
    seedAcceptsVocabulary(['credit', 'paypay']);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['model' => 'stera terminal (SMBC GMO)']))
        ->assertCreated();

    $device = PeripheralDevice::query()->latest('created_at')->first();
    expect($device->metadata['accepts'])->toBe(['credit', 'paypay']);
});

it('intersects the template with the org vocabulary — missing or inactive keys are dropped', function () {
    // Org only carries 3 of stera's 12 keys, one of them inactive.
    seedAcceptsVocabulary(['credit', 'paypay']);
    seedAcceptsVocabulary(['quicpay'], isActive: false);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['model' => 'stera']))
        ->assertCreated();

    $device = PeripheralDevice::query()->latest('created_at')->first();
    expect($device->metadata['accepts'])->toBe(['credit', 'paypay']);
});

it('does not prefill when accepts is supplied explicitly (template never overrides)', function () {
    seedAcceptsVocabulary(['credit', 'paypay']);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['model' => 'stera', 'accepts' => ['paypay']]))
        ->assertCreated();

    $device = PeripheralDevice::query()->latest('created_at')->first();
    expect($device->metadata['accepts'])->toBe(['paypay']);
});

it('respects an explicit EMPTY accepts list (no template prefill over it)', function () {
    seedAcceptsVocabulary(['credit', 'paypay']);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['model' => 'stera', 'accepts' => []]))
        ->assertCreated();

    $device = PeripheralDevice::query()->latest('created_at')->first();
    expect($device->metadata['accepts'])->toBe([]);
});

it('does not prefill for an unknown model', function () {
    seedAcceptsVocabulary(['credit', 'paypay']);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['model' => 'Verifone P400']))
        ->assertCreated();

    $device = PeripheralDevice::query()->latest('created_at')->first();
    expect($device->metadata)->not->toHaveKey('accepts');
});

it('does not prefill for non payment_terminal types', function () {
    seedAcceptsVocabulary(['cash']);

    $this->actingAs($this->user)
        ->postJson($this->base, terminalPayload(['model' => 'stera'], type: 'coin_changer', name: 'Glory'))
        ->assertCreated();

    $device = PeripheralDevice::query()->latest('created_at')->first();
    expect($device->metadata)->not->toHaveKey('accepts');
});
