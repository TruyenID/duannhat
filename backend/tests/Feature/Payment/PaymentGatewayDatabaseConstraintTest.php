<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->organization = Organization::factory()->create([
        'id' => (string) Str::uuid(),
        'console_organization_id' => (string) Str::uuid(),
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->id,
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->id,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->order = CustomerOrder::factory()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->providerId = (string) Str::uuid();
    DB::table('payment_gateway_providers')->insert([
        'id' => $this->providerId,
        'code' => 'stripe',
        'name' => 'Stripe',
        'is_active' => true,
        'sort_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->optionId = (string) Str::uuid();
    DB::table('payment_gateway_options')->insert([
        'id' => $this->optionId,
        'provider_id' => $this->providerId,
        'code' => 'card_visa',
        'integration_product' => 'payments',
        'api_version' => '2026-07-01',
        'rail' => 'card',
        'brands' => json_encode(['visa'], JSON_THROW_ON_ERROR),
        'channels' => json_encode(['pos'], JSON_THROW_ON_ERROR),
        'device_classes' => json_encode(['terminal'], JSON_THROW_ON_ERROR),
        'currencies' => json_encode(['JPY'], JSON_THROW_ON_ERROR),
        'workflows' => json_encode(['sale'], JSON_THROW_ON_ERROR),
        'operations' => json_encode(['sale', 'refund'], JSON_THROW_ON_ERROR),
        'revision' => 1,
        'effective_from' => now(),
        'is_active' => true,
        'sort_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->connectionId = (string) Str::uuid();
    $this->insertConnection = function (array $overrides = []): string {
        $id = $overrides['id'] ?? (string) Str::uuid();
        DB::table('payment_gateway_connections')->insert(array_merge([
            'id' => $id,
            'provider_id' => $this->providerId,
            'organization_id' => $this->organization->id,
            'brand_id' => $this->brand->id,
            'identity_brand_id' => (string) Str::uuid(),
            'owner_scope' => 'hq',
            'brand_owner_org_unit_id' => (string) Str::uuid(),
            'operator_org_unit_id' => (string) Str::uuid(),
            'ownership_revision' => '1',
            'environment' => 'live',
            'merchant_account_id' => 'acct_primary',
            'charge_model' => 'direct',
            'health' => 'ready',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    };
    ($this->insertConnection)(['id' => $this->connectionId]);

    $this->connectionOptionId = (string) Str::uuid();
    DB::table('payment_gateway_connection_options')->insert([
        'id' => $this->connectionOptionId,
        'connection_id' => $this->connectionId,
        'option_id' => $this->optionId,
        'verification_state' => 'verified',
        'approved_currencies' => json_encode(['JPY'], JSON_THROW_ON_ERROR),
        'approved_channels' => json_encode(['pos'], JSON_THROW_ON_ERROR),
        'approved_operations' => json_encode(['sale', 'refund'], JSON_THROW_ON_ERROR),
        'capability_revision' => 1,
        'effective_from' => now(),
        'is_enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->policyRevisionId = (string) Str::uuid();
    DB::table('payment_policy_revisions')->insert([
        'id' => $this->policyRevisionId,
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'revision' => 1,
        'ownership_revision' => '1',
        'snapshot_hash' => str_repeat('a', 64),
        'snapshot' => '{}',
        'effective_option_count' => 1,
        'source' => 'test',
        'published_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->insertAttempt = function (array $overrides = []): string {
        $id = $overrides['id'] ?? (string) Str::uuid();
        DB::table('payment_attempts')->insert(array_merge([
            'id' => $id,
            'organization_id' => $this->organization->id,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'customer_order_id' => $this->order->id,
            'connection_id' => $this->connectionId,
            'connection_option_id' => $this->connectionOptionId,
            'policy_revision_id' => $this->policyRevisionId,
            'operation' => 'sale',
            'state' => 'prepared',
            'provider' => 'stripe',
            'environment' => 'live',
            'owner_scope' => 'hq',
            'operator_org_unit_id' => (string) Str::uuid(),
            'ownership_revision' => '1',
            'channel' => 'pos',
            'currency' => 'JPY',
            'amount_minor' => 1000,
            'idempotency_key' => 'idem-'.Str::random(16),
            'request_fingerprint' => hash('sha256', Str::random(32)),
            'provider_request_key' => 'provider-'.Str::random(16),
            'version' => 1,
            'prepared_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    };
});

it('scopes provider option codes to their provider while keeping provider codes global', function () {
    expect(fn () => DB::table('payment_gateway_providers')->insert([
        'id' => (string) Str::uuid(),
        'code' => 'stripe',
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(fn () => DB::table('payment_gateway_options')->insert([
        'id' => (string) Str::uuid(),
        'provider_id' => $this->providerId,
        'code' => 'card_visa',
        'integration_product' => 'payments',
        'api_version' => '2026-07-01',
        'rail' => 'card',
        'brands' => '[]',
        'channels' => '[]',
        'device_classes' => '[]',
        'currencies' => '[]',
        'workflows' => '[]',
        'operations' => '[]',
        'effective_from' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('isolates merchant identity by provider environment', function () {
    expect(fn () => ($this->insertConnection)([
        'merchant_account_id' => 'acct_primary',
    ]))->toThrow(UniqueConstraintViolationException::class);

    $sandbox = ($this->insertConnection)([
        'environment' => 'sandbox',
        'merchant_account_id' => 'acct_primary',
    ]);

    expect(DB::table('payment_gateway_connections')->where('id', $sandbox)->exists())->toBeTrue();
});

it('allows one verified option row per connection and catalog option', function () {
    expect(fn () => DB::table('payment_gateway_connection_options')->insert([
        'id' => (string) Str::uuid(),
        'connection_id' => $this->connectionId,
        'option_id' => $this->optionId,
        'approved_currencies' => '[]',
        'approved_channels' => '[]',
        'approved_operations' => '[]',
        'effective_from' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('deduplicates attempts by connection operation and caller idempotency key', function () {
    ($this->insertAttempt)(['idempotency_key' => 'checkout-42']);

    expect(fn () => ($this->insertAttempt)([
        'idempotency_key' => 'checkout-42',
    ]))->toThrow(UniqueConstraintViolationException::class);

    $capture = ($this->insertAttempt)([
        'operation' => 'capture',
        'idempotency_key' => 'checkout-42',
    ]);
    expect(DB::table('payment_attempts')->where('id', $capture)->exists())->toBeTrue();
});

it('isolates provider object identity by connection environment', function () {
    ($this->insertAttempt)(['provider_object_id' => 'pi_shared']);

    expect(fn () => ($this->insertAttempt)([
        'provider_object_id' => 'pi_shared',
    ]))->toThrow(UniqueConstraintViolationException::class);

    $sandbox = ($this->insertAttempt)([
        'environment' => 'sandbox',
        'provider_object_id' => 'pi_shared',
    ]);
    expect(DB::table('payment_attempts')->where('id', $sandbox)->exists())->toBeTrue();
});

it('deduplicates refund commands and provider refund identity', function () {
    $attemptId = ($this->insertAttempt)(['state' => 'succeeded']);
    $insertRefund = function (array $overrides = []) use ($attemptId): string {
        $id = $overrides['id'] ?? (string) Str::uuid();
        DB::table('payment_refunds')->insert(array_merge([
            'id' => $id,
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'payment_attempt_id' => $attemptId,
            'connection_id' => $this->connectionId,
            'state' => 'prepared',
            'provider' => 'stripe',
            'environment' => 'live',
            'currency' => 'JPY',
            'amount_minor' => 100,
            'idempotency_key' => 'refund-'.Str::random(8),
            'request_fingerprint' => hash('sha256', Str::random(32)),
            'provider_request_key' => 'refund-provider-'.Str::random(8),
            'provider_refund_id' => null,
            'version' => 1,
            'prepared_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    };

    $insertRefund(['idempotency_key' => 'refund-command', 'provider_refund_id' => 're_shared']);

    expect(fn () => $insertRefund([
        'idempotency_key' => 'refund-command',
    ]))->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => $insertRefund([
        'provider_refund_id' => 're_shared',
    ]))->toThrow(UniqueConstraintViolationException::class);

    $sandbox = $insertRefund([
        'environment' => 'sandbox',
        'provider_refund_id' => 're_shared',
    ]);
    expect(DB::table('payment_refunds')->where('id', $sandbox)->exists())->toBeTrue();
});

it('deduplicates provider events inside the connection environment namespace', function () {
    $insertEvent = function (string $environment): void {
        DB::table('payment_provider_events')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->organization->id,
            'connection_id' => $this->connectionId,
            'provider' => 'stripe',
            'environment' => $environment,
            'state' => 'received_verified',
            'provider_event_id' => 'evt_shared',
            'event_type' => 'payment.succeeded',
            'payload_hash' => hash('sha256', '{}'),
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $insertEvent('live');
    expect(fn () => $insertEvent('live'))->toThrow(UniqueConstraintViolationException::class);
    $insertEvent('sandbox');

    expect(DB::table('payment_provider_events')->where('provider_event_id', 'evt_shared')->count())->toBe(2);
});

it('declares restrictive tenant and financial parent foreign keys', function () {
    $connectionForeignKeys = collect(Schema::getForeignKeys('payment_gateway_connections'));
    $attemptForeignKeys = collect(Schema::getForeignKeys('payment_attempts'));
    $refundForeignKeys = collect(Schema::getForeignKeys('payment_refunds'));

    $hasRestrictiveReference = fn ($keys, string $column, string $table): bool => $keys->contains(
        fn (array $key): bool => $key['columns'] === [$column]
            && $key['foreign_table'] === $table
            && strtolower($key['on_delete']) === 'restrict'
    );

    expect($hasRestrictiveReference($connectionForeignKeys, 'organization_id', 'organizations'))->toBeTrue()
        ->and($hasRestrictiveReference($connectionForeignKeys, 'brand_id', 'brands'))->toBeTrue()
        ->and($hasRestrictiveReference($connectionForeignKeys, 'owner_branch_id', 'branches'))->toBeTrue()
        ->and($hasRestrictiveReference($attemptForeignKeys, 'connection_id', 'payment_gateway_connections'))->toBeTrue()
        ->and($hasRestrictiveReference($attemptForeignKeys, 'customer_order_id', 'customer_orders'))->toBeTrue()
        ->and($hasRestrictiveReference($refundForeignKeys, 'payment_attempt_id', 'payment_attempts'))->toBeTrue()
        ->and($hasRestrictiveReference($refundForeignKeys, 'connection_id', 'payment_gateway_connections'))->toBeTrue();
});
