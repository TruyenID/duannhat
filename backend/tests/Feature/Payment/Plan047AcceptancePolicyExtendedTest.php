<?php

/**
 * Plan 047 acceptance — policy resolution scenarios B1, B2, B7, B8, B9, B10, B11, B12.
 */

use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Models\PaymentPolicyRevision;
use App\Omnify\Enums\PaymentConnectionHealthEnum;
use App\Services\Payment\Policy\Admin\PaymentPolicyEvaluationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

beforeEach(function () {
    $this->fixtures = new PaymentPolicyApiFixtures;
    $this->fixtures->bind();
    $this->actingAs($this->fixtures->manager);
    grantOrgAccess($this->fixtures->manager, (string) $this->fixtures->organization->id);

    $this->connection = $this->fixtures->seedConnection();
    $this->fixtures->publishInitialPolicyRevision();
    $this->policyIdentity = $this->fixtures->currentEffectiveIdentity();
    $this->cash = $this->fixtures->seedCashPaymentMethod();
});

describe('B1 full policy lattice enables checkout', function () {
    it('B1 resolves enabled when capability connection HQ shop and device all allow', function () {
        $effective = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data.options.0');

        expect($effective['effective'])->toBeTrue()
            ->and($effective['connection_id'])->toBe($this->policyIdentity['connection_id'])
            ->and($effective['id'])->toBe($this->policyIdentity['option_id'])
            ->and($effective['trace'])->not->toBeEmpty();

        $layers = collect($effective['trace'])->pluck('layer')->all();
        expect($layers)->toContain('capability')
            ->and($layers)->toContain('connection');
    });
});

describe('B2 B7 connection health affects effective state', function () {
    it('B2 marks option ineffective when connection health is restricted', function () {
        DB::table('payment_gateway_connections')
            ->where('id', $this->connection->id)
            ->update([
                'health' => PaymentConnectionHealthEnum::Restricted->value,
                'health_reason_code' => 'provider_restricted',
            ]);

        app(PaymentPolicyEvaluationService::class)->updateShopOption(
            $this->fixtures->shop,
            $this->fixtures->option,
            ['preference' => 'inherit', 'change_reason' => 'refresh after health change'],
        );

        $effective = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data.options.0');

        expect($effective['effective'])->toBeFalse()
            ->and($effective['source'])->toBe('connection')
            ->and($effective['reason'])->not->toBeEmpty();
    });

    it('B7 preserves shop preference rows when connection degrades then recovers', function () {
        $beforeShopRows = DB::table('shop_payment_options')
            ->where('branch_id', $this->fixtures->shop->id)
            ->count();

        DB::table('payment_gateway_connections')
            ->where('id', $this->connection->id)
            ->update(['health' => PaymentConnectionHealthEnum::Degraded->value]);

        app(PaymentPolicyEvaluationService::class)->updateShopOption(
            $this->fixtures->shop,
            $this->fixtures->option,
            ['preference' => 'inherit', 'change_reason' => 'health degraded'],
        );

        $degraded = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data.options.0');
        expect($degraded['effective'])->toBeFalse();

        DB::table('payment_gateway_connections')
            ->where('id', $this->connection->id)
            ->update(['health' => PaymentConnectionHealthEnum::Ready->value]);

        app(PaymentPolicyEvaluationService::class)->updateShopOption(
            $this->fixtures->shop,
            $this->fixtures->option,
            ['preference' => 'inherit', 'change_reason' => 'health recovered'],
        );

        $recovered = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data.options.0');

        expect($recovered['effective'])->toBeTrue()
            ->and(DB::table('shop_payment_options')->where('branch_id', $this->fixtures->shop->id)->count())
            ->toBe($beforeShopRows);
    });
});

describe('B8 capability filters', function () {
    it('B8 rejects USD channel on a JPY-only approved connection option', function () {
        DB::table('payment_gateway_connection_options')
            ->where('connection_id', $this->connection->id)
            ->update(['approved_currencies' => json_encode(['USD'], JSON_THROW_ON_ERROR)]);

        app(PaymentPolicyEvaluationService::class)->updateShopOption(
            $this->fixtures->shop,
            $this->fixtures->option,
            ['preference' => 'inherit', 'change_reason' => 'currency filter test'],
        );

        $effective = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data.options.0');

        expect($effective['effective'])->toBeFalse()
            ->and($effective['source'])->toBe('capability');
    });
});

describe('B9 deterministic resolver output', function () {
    it('B9 returns identical effective JSON on repeated reads', function () {
        $first = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data');

        $second = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data');

        expect($second)->toBe($first)
            ->and($second['revision'])->toBe($first['revision'])
            ->and($second['snapshot_hash'])->toBe($first['snapshot_hash']);
    });
});

describe('B10 revision publication idempotency', function () {
    it('B10 does not increment revision when effective policy is unchanged', function () {
        $revisionBefore = PaymentPolicyRevision::query()
            ->where('branch_id', $this->fixtures->shop->id)
            ->max('revision');

        app(PaymentPolicyEvaluationService::class)->updateShopOption(
            $this->fixtures->shop,
            $this->fixtures->option,
            ['preference' => 'inherit', 'change_reason' => 'no-op republish'],
        );

        $revisionAfter = PaymentPolicyRevision::query()
            ->where('branch_id', $this->fixtures->shop->id)
            ->max('revision');

        expect($revisionAfter)->toBe($revisionBefore);
    });
});

describe('B11 disabled option UUID rejected server-side', function () {
    it('B11 rejects payment when shop disabled the submitted gateway option', function () {
        $order = $this->fixtures->seedCheckoutOrder();

        $this->patchJson("{$this->fixtures->shopBase()}/payment-options/{$this->fixtures->option->id}", [
            'preference' => 'disabled',
            'change_reason' => 'lane closed',
        ])->assertOk();

        $response = $this->postJson("{$this->fixtures->shopBase()}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cash->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'gateway_option_id' => $this->policyIdentity['option_id'],
            'gateway_connection_id' => $this->policyIdentity['connection_id'],
            'policy_revision' => $this->fixtures->currentEffectiveIdentity()['revision'],
        ])->assertStatus(422);

        expect($response->json('code'))->toBeIn(['PAYMENT_OPTION_DISABLED', 'PAYMENT_POLICY_STALE'])
            ->and(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(0);
    });

    it('B11 rejects inactive legacy payment method even when UUID is in scope', function () {
        $this->cash->update(['is_active' => false]);
        $order = $this->fixtures->seedCheckoutOrder();

        $this->postJson("{$this->fixtures->shopBase()}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cash->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
        ])->assertStatus(422);

        expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(0);
    });
});

describe('B12 scoped payment method uniqueness', function () {
    it('B12 rejects duplicate organization-global payment method codes', function () {
        PaymentMethod::factory()->create([
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => null,
            'code' => 'global_dup_probe',
        ]);

        expect(fn () => PaymentMethod::factory()->create([
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => null,
            'code' => 'global_dup_probe',
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});
