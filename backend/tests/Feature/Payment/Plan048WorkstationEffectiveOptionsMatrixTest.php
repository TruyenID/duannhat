<?php

/**
 * #1080 — device×option matrix for the workstation LAN mirror.
 *
 * The flat mirror dropped device restrictions and channel resolution; the
 * matrix endpoint returns per-terminal sets so the LAN read can achieve
 * parity with direct-to-Cloud reads.
 */

use App\Models\PaymentMethod;
use Database\Seeders\PaymentGatewayCatalogSeeder;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

uses()->group('payment', 'workstation');

beforeEach(function () {
    // #2318 — cầu nối `internal_catalog` đọc catalog internal, trước đây do một
    // data migration seed nên RefreshDatabase là đủ. Migration đã bị xoá; nguồn
    // duy nhất bây giờ là seeder.
    app(PaymentGatewayCatalogSeeder::class)->seedInternal();

    $this->fixtures = new PaymentPolicyApiFixtures;
    $this->fixtures->bind();

    $this->fixtures->seedConnection();

    $this->posDevice = $this->fixtures->seedDevice('pos');
    $this->kioskDevice = $this->fixtures->seedDevice('kiosk');
    $this->workstation = $this->fixtures->seedDevice('workstation');

    $this->matrix = fn () => $this->withHeaders([
        'Authorization' => 'Bearer '.$this->workstation->device_token,
    ])->getJson('/api/v1/workstation/effective-payment-options/matrix')
        ->assertOk()
        ->json('data');
});

it('returns one evaluation per active pos/kiosk terminal plus branch fallbacks per channel', function () {
    $data = ($this->matrix)();

    expect($data['branch'])->toHaveKeys(['pos', 'kiosk'])
        ->and(collect($data['devices'])->pluck('device_id'))
        ->toContain((string) $this->posDevice->id)
        ->toContain((string) $this->kioskDevice->id)
        // The workstation itself is not a payment terminal.
        ->not->toContain((string) $this->workstation->id);

    $byId = collect($data['devices'])->keyBy('device_id');
    expect($byId[(string) $this->posDevice->id]['channel'])->toBe('pos')
        ->and($byId[(string) $this->kioskDevice->id]['channel'])->toBe('kiosk');
});

it('preserves a kiosk device disable in the matrix while the pos terminal keeps the option', function () {
    $this->actingAs($this->fixtures->manager)
        ->patchJson("{$this->fixtures->shopBase()}/devices/{$this->kioskDevice->id}/payment-options", [
            'option_id' => $this->fixtures->option->id,
            'preference' => 'disabled',
        ])->assertOk();

    $data = ($this->matrix)();
    $byId = collect($data['devices'])->keyBy('device_id');

    $kioskOption = collect($byId[(string) $this->kioskDevice->id]['evaluation']['options'])
        ->firstWhere('id', (string) $this->fixtures->option->id);
    $posOption = collect($byId[(string) $this->posDevice->id]['evaluation']['options'])
        ->firstWhere('id', (string) $this->fixtures->option->id);

    expect($kioskOption['effective'])->toBeFalse()
        ->and($posOption['effective'])->toBeTrue();
});

it('resolves pos rows with the pos channel enrichment (parity with the direct-Cloud POS endpoint)', function () {
    PaymentMethod::factory()->create([
        'organization_id' => $this->fixtures->organization->id,
        'branch_id' => null,
        'code' => 'cash',
        'type' => 'cash',
        'is_active' => true,
        'is_auto_confirm' => true,
        'requires_tendered' => true,
    ]);

    $data = ($this->matrix)();
    $byId = collect($data['devices'])->keyBy('device_id');

    $posOptions = collect($byId[(string) $this->posDevice->id]['evaluation']['options']);
    $kioskOptions = collect($byId[(string) $this->kioskDevice->id]['evaluation']['options']);

    // POS parity: enriched client caps + internal-catalog cash bridge present.
    expect($posOptions->first())->toHaveKey('client')
        ->and($posOptions->contains(fn (array $o): bool => ($o['source'] ?? null) === 'internal_catalog'))->toBeTrue()
        // Kiosk parity: plain evaluation, same as the direct kiosk endpoint.
        ->and($kioskOptions->first())->not->toHaveKey('client');
});
