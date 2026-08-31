<?php

/**
 * Regression cover for the payment-gateway settings QA sweep
 * (docs/qa/payment-gateway-settings-test-plan.md).
 *
 * Each `it()` below pins one defect from that report. They are grouped in one
 * file on purpose: every one of them was a case of the API answering
 * SUCCESSFULLY with something untrue — a filter that filtered nothing, a null
 * where a label belongs, brand policy written into a shop, a fixture rendered
 * as live data. None of them would have been caught by a status-code assertion,
 * so each test here asserts on the CONTENT of a 200.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayConnectionOption;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentGatewayProvider;
use App\Models\ShopPaymentOption;
use App\Models\User;
use App\Omnify\Enums\PaymentConnectionHealthEnum;
use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentPolicyPreferenceEnum;
use App\Services\Payment\Configuration\Exceptions\PaymentConfigurationException;
use App\Services\Payment\Configuration\Internal\EloquentPaymentGatewayConfigurationPersistence;
use App\Services\Payment\Policy\Contracts\PaymentOwnerOptionPolicySource;
use App\Services\Payment\Policy\Enums\UpstreamPolicyState;
use Illuminate\Support\Str;
use Tests\Support\Payment\PaymentGatewayFixtures;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'defects-pay',
        'is_active' => true,
    ]);

    $this->hqBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'zz-headquarters',
        'is_headquarters' => true,
        'is_active' => true,
    ]);

    // Alphabetically BEFORE the headquarters slug. The removed fallback ordered
    // by slug, so this is the branch it used to elect as the brand policy store.
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'aa-shop',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);
    $this->actingAs($this->user);

    $this->provider = PaymentGatewayProvider::factory()->create([
        'code' => PaymentGatewayProviderCodeEnum::Stripe,
        'is_active' => true,
    ]);

    $this->option = PaymentGatewayOption::factory()->create([
        'provider_id' => $this->provider->id,
        'code' => PaymentGatewayFixtures::fullCapability()->id,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->base = "/api/v1/hq/{$this->brand->slug}";

    // #3074 — mỗi lượt tạo rơi vào một `environment` khác, trừ khi bài tự khai.
    //
    // `payment_gateway_connections` nay UNIQUE trên khoá tự nhiên
    // (provider · environment · organization · brand · owner_scope ·
    // owner_branch_key), nên hai connection cùng brand + cùng provider + cùng
    // môi trường là điều KHÔNG THỂ — đó chính là tính chất #3070 cần.
    //
    // Các bài ở đây đếm SỐ HÀNG trên màn HQ chứ không phụ thuộc môi trường nào,
    // và một brand có đồng thời test/sandbox/live cho cùng một cổng là chuyện
    // bình thường. Nên xoay môi trường giữ nguyên ý bài mà không phải nới khoá.
    $environments = ['test', 'sandbox', 'live', 'local'];
    $made = 0;

    $this->makeConnection = function (array $overrides = []) use ($environments, &$made): PaymentGatewayConnection {
        $rotated = ['environment' => $environments[$made++ % count($environments)]];

        return PaymentGatewayConnection::factory()->create(array_merge([
            'provider_id' => $this->provider->id,
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'owner_branch_id' => null,
            'owner_scope' => PaymentConnectionOwnerScopeEnum::Hq,
            'health' => PaymentConnectionHealthEnum::Ready,
            'is_active' => true,
        ], $rotated, $overrides));
    };
});

describe('F1 — brand overview reports real counts', function () {
    it('serves payment-readiness instead of 404, and counts rows that actually exist', function () {
        ($this->makeConnection)(['merchant_account_id' => 'acct_ready']);
        ($this->makeConnection)([
            'merchant_account_id' => 'acct_pending',
            'health' => PaymentConnectionHealthEnum::PendingVerification,
            'is_active' => false,
        ]);

        $response = $this->getJson("{$this->base}/payment-readiness")->assertOk();

        // The fixture this endpoint used to be masked by said 1/2 connections,
        // 3/5 shops, 4/6 options for every brand on earth.
        expect($response->json('data.connections_total'))->toBe(2)
            ->and($response->json('data.connections_ready'))->toBe(1)
            ->and($response->json('data.shops_total'))->toBe(2)
            ->and($response->json('data.options_total'))->toBe(PaymentGatewayOption::query()->where('is_active', true)->count());

        // …and it names the connection that is actually holding the brand up.
        expect(collect($response->json('data.blockers'))->pluck('code'))
            ->toContain('GATEWAY_SETUP_REQUIRED');
    });
});

describe('F3 — brand policy never lands in a real shop', function () {
    it('stores brand policy on the headquarters branch, not the alphabetically-first shop', function () {
        $optionId = $this->getJson("{$this->base}/payment-options")->assertOk()->json('data.0.option_id');

        $this->patchJson("{$this->base}/payment-options/{$optionId}", [
            'preference' => 'enabled',
        ])->assertOk();

        expect(ShopPaymentOption::query()->where('branch_id', $this->hqBranch->id)->count())->toBe(1)
            ->and(ShopPaymentOption::query()->where('branch_id', $this->shop->id)->count())->toBe(0);
    });

    it('refuses to resolve a policy branch when the headquarters branch is soft-deleted', function () {
        $this->hqBranch->delete();

        $persistence = app(EloquentPaymentGatewayConfigurationPersistence::class);

        expect(fn () => $persistence->resolvePolicyBranch($this->orgId, (string) $this->brand->id))
            ->toThrow(PaymentConfigurationException::class);

        // The old behaviour: silently return `aa-shop` and let HQ write there.
        try {
            $persistence->resolvePolicyBranch($this->orgId, (string) $this->brand->id);
        } catch (PaymentConfigurationException $exception) {
            expect($exception->errorCode)->toBe('PAYMENT_POLICY_BRANCH_UNRESOLVED')
                ->and($exception->status)->toBe(409);
        }
    });

    it('keeps the coverage screen readable even with no headquarters branch', function () {
        ($this->makeConnection)(['merchant_account_id' => 'acct_cov']);
        $this->hqBranch->delete();

        $this->getJson("{$this->base}/payment-coverage")->assertOk();
    });
});

describe('F5 — coverage rows carry every column the table renders', function () {
    it('returns readiness, connection health/display and option counts', function () {
        $connection = ($this->makeConnection)(['merchant_account_id' => 'acct_cov_display']);
        PaymentGatewayConnectionOption::factory()->create([
            'connection_id' => $connection->id,
            'option_id' => $this->option->id,
            'is_enabled' => true,
        ]);

        $row = collect($this->getJson("{$this->base}/payment-coverage")->assertOk()->json('data'))
            ->firstWhere('shop_slug', $this->shop->slug);

        // `undefined` here is what produced `hq.payments.shops.management.undefined`
        // on every row of the live screen.
        expect($row)->toHaveKeys([
            'readiness', 'connection_health', 'connection_display', 'options_effective', 'options_total',
        ])
            ->and($row['readiness'])->toBe('ready')
            ->and($row['connection_display'])->toContain('acct_cov_display')
            ->and($row['options_total'])->toBe(1)
            ->and($row['options_effective'])->toBe(1);
    });

    it('drops options_effective when the brand blocks the option', function () {
        $connection = ($this->makeConnection)(['merchant_account_id' => 'acct_cov_blocked']);
        PaymentGatewayConnectionOption::factory()->create([
            'connection_id' => $connection->id,
            'option_id' => $this->option->id,
            'is_enabled' => true,
        ]);

        ShopPaymentOption::query()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->hqBranch->id,
            'option_id' => $this->option->id,
            'preference' => PaymentPolicyPreferenceEnum::Blocked->value,
            'version' => 1,
        ]);

        $row = collect($this->getJson("{$this->base}/payment-coverage")->assertOk()->json('data'))
            ->firstWhere('shop_slug', $this->shop->slug);

        expect($row['options_total'])->toBe(1)
            ->and($row['options_effective'])->toBe(0);
    });
});

describe('F6 — the connection list actually filters and paginates', function () {
    beforeEach(function () {
        ($this->makeConnection)(['merchant_account_id' => 'acct_one', 'environment' => 'test']);
        ($this->makeConnection)(['merchant_account_id' => 'acct_two', 'environment' => 'sandbox']);

        // #3074 — hàng thứ ba phải mang CỔNG khác, không phải môi trường khác.
        //
        // Bài này đo bộ lọc `?environment=sandbox` nên nó cần hai hàng cùng
        // `sandbox`; mà khoá tự nhiên mới cấm hai connection trùng cả cổng lẫn
        // môi trường dưới một chủ sở hữu. Một brand nối đồng thời Stripe và
        // PayPay trong cùng môi trường là hình dạng thật — và nó giữ nguyên
        // phép đo, vì bộ lọc không nhìn cổng.
        $secondProvider = PaymentGatewayProvider::factory()->create([
            'code' => PaymentGatewayProviderCodeEnum::Paypay,
            'is_active' => true,
        ]);

        ($this->makeConnection)([
            'merchant_account_id' => 'acct_three',
            'provider_id' => $secondProvider->id,
            'environment' => 'sandbox',
            'health' => PaymentConnectionHealthEnum::Restricted,
        ]);
    });

    it('narrows by environment', function () {
        expect($this->getJson("{$this->base}/payment-gateways?environment=sandbox")->assertOk()->json('data'))
            ->toHaveCount(2);
    });

    it('narrows by health', function () {
        expect($this->getJson("{$this->base}/payment-gateways?health=restricted")->assertOk()->json('data'))
            ->toHaveCount(1);
    });

    it('returns nothing for a search term that matches nothing', function () {
        expect($this->getJson("{$this->base}/payment-gateways?search=zzzz-no-such-merchant")->assertOk()->json('data'))
            ->toHaveCount(0);
    });

    it('finds a connection by merchant account id', function () {
        expect($this->getJson("{$this->base}/payment-gateways?search=acct_two")->assertOk()->json('data'))
            ->toHaveCount(1);
    });

    it('paginates and reports meta the admin pager reads', function () {
        $response = $this->getJson("{$this->base}/payment-gateways?per_page=1&page=1")->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('meta.total'))->toBe(3)
            ->and($response->json('meta.per_page'))->toBe(1)
            ->and($response->json('meta.current_page'))->toBe(1);
    });

    it('rejects an unknown filter value rather than silently widening the result', function () {
        $this->getJson("{$this->base}/payment-gateways?health=not-a-health")->assertStatus(422);
    });
});

describe('F7 — a provider always renders a name', function () {
    it('falls back to another locale rather than returning null', function () {
        $paypay = PaymentGatewayProvider::factory()->create([
            'code' => PaymentGatewayProviderCodeEnum::Paypay,
            'is_active' => true,
        ]);
        // Reload before writing: the factory seeds every locale, and saving a
        // model whose `translations` relation is still cached re-inserts the
        // rows just deleted.
        $paypay->translations()->delete();
        $paypay = $paypay->fresh();
        $paypay->translateOrNew('en')->name = 'PayPay';
        $paypay->save();

        ($this->makeConnection)(['provider_id' => $paypay->id, 'merchant_account_id' => 'acct_paypay']);

        $row = collect($this->getJson("{$this->base}/payment-gateways", ['Accept-Language' => 'vi'])
            ->assertOk()->json('data'))
            ->firstWhere('merchant_account_id', 'acct_paypay');

        expect($row['provider']['name'])->toBe('PayPay');
    });

    it('falls back to the provider code when no translation exists at all', function () {
        $bare = PaymentGatewayProvider::factory()->create([
            'code' => PaymentGatewayProviderCodeEnum::Sbps,
            'is_active' => true,
        ]);
        $bare->translations()->delete();
        $bare = $bare->fresh();

        ($this->makeConnection)(['provider_id' => $bare->id, 'merchant_account_id' => 'acct_bare']);

        $row = collect($this->getJson("{$this->base}/payment-gateways")->assertOk()->json('data'))
            ->firstWhere('merchant_account_id', 'acct_bare');

        expect($row['provider']['name'])->not->toBeNull()
            ->and($row['provider']['name'])->toBe(PaymentGatewayProviderCodeEnum::Sbps->value);
    });
});

describe('F9 — credentials are refused at connection create', function () {
    it('rejects a raw api_secret with 422 instead of creating the connection', function () {
        $before = PaymentGatewayConnection::query()->count();

        $this->postJson("{$this->base}/payment-gateways", [
            'provider_code' => 'stripe',
            'environment' => 'test',
            'merchant_account_id' => 'acct_with_secret',
            'charge_model' => 'direct',
            'identity_brand_id' => (string) Str::uuid(),
            'brand_owner_org_unit_id' => (string) Str::uuid(),
            'operator_org_unit_id' => (string) Str::uuid(),
            'ownership_revision' => '10001',
            'api_secret' => 'sk_live_super_secret_value',
        ])->assertStatus(422)->assertJsonValidationErrors(['api_secret']);

        expect(PaymentGatewayConnection::query()->count())->toBe($before);
    });

    it('still accepts a clean create payload', function () {
        $this->postJson("{$this->base}/payment-gateways", [
            'provider_code' => 'stripe',
            'environment' => 'test',
            'merchant_account_id' => 'acct_clean',
            'charge_model' => 'direct',
            'identity_brand_id' => (string) Str::uuid(),
            'brand_owner_org_unit_id' => (string) Str::uuid(),
            'operator_org_unit_id' => (string) Str::uuid(),
            'ownership_revision' => '10001',
        ])->assertCreated();
    });
});

describe('F3 — brand policy is actually read downstream', function () {
    it('denies an option for every shop once the brand blocks it', function () {
        $source = app(PaymentOwnerOptionPolicySource::class);
        $brandId = (string) $this->brand->id;
        $optionId = (string) $this->option->id;

        // The bound implementation used to be a placeholder that returned
        // Allowed unconditionally, so the HQ policy screen wrote rows nothing
        // downstream ever read.
        expect($source->resolve($brandId, $optionId))->toBe(UpstreamPolicyState::Allowed);

        $this->patchJson("{$this->base}/payment-options/{$optionId}", [
            'preference' => 'blocked',
            'change_reason' => 'brand-wide stop',
        ])->assertOk();

        // Fresh instance = fresh request. The source caches a brand's blocked
        // set for the container scope, so a read-after-write inside one request
        // deliberately still sees the pre-write set.
        app()->forgetScopedInstances();

        expect(app(PaymentOwnerOptionPolicySource::class)->resolve($brandId, $optionId))
            ->toBe(UpstreamPolicyState::Denied);
    });

    it('treats a brand-level disabled as default-off, not as a denial', function () {
        $optionId = (string) $this->option->id;

        $this->patchJson("{$this->base}/payment-options/{$optionId}", [
            'preference' => 'disabled',
        ])->assertOk();

        app()->forgetScopedInstances();

        expect(app(PaymentOwnerOptionPolicySource::class)->resolve((string) $this->brand->id, $optionId))
            ->toBe(UpstreamPolicyState::Allowed);
    });

    it('reads as allowed when the brand has no headquarters branch at all', function () {
        $this->hqBranch->delete();

        // Writing brand policy without a policy store is a 409; READING must
        // not be, or one bad admin row takes POS/kiosk/workstation down with it.
        expect(app(PaymentOwnerOptionPolicySource::class)->resolve((string) $this->brand->id, (string) $this->option->id))
            ->toBe(UpstreamPolicyState::Allowed);
    });
});
