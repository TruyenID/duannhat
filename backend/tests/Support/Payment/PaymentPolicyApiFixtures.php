<?php

namespace Tests\Support\Payment;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentMethod;
use App\Models\PaymentPolicyRevision;
use App\Models\User;
use App\Omnify\Enums\PaymentConnectionHealthEnum;
use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Policy\Admin\PaymentPolicyEvaluationService;
use App\Services\Payment\Policy\Contracts\BranchManagementProjectionSource;
use App\Services\Payment\Policy\Contracts\PaymentOwnerOptionPolicySource;
use App\Services\Payment\Policy\Enums\BranchManagementModel;
use App\Services\Payment\Policy\InMemoryBranchManagementProjectionSource;
use App\Services\Payment\Policy\InMemoryPaymentOwnerOptionPolicySource;
use App\Services\Payment\Policy\ValueObjects\BranchManagementLookup;
use App\Services\Payment\Policy\ValueObjects\BranchManagementProjection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PaymentPolicyApiFixtures
{
    public Organization $organization;

    public Brand $brand;

    public Branch $shop;

    public PaymentGatewayProvider $provider;

    public PaymentGatewayOption $option;

    public User $manager;

    public InMemoryBranchManagementProjectionSource $ownershipSource;

    public InMemoryPaymentOwnerOptionPolicySource $ownerPolicySource;

    /** @var array<string, mixed> */
    public array $identity;

    public function __construct(
        BranchManagementModel $managementModel = BranchManagementModel::HqManaged,
        ?string $shopSlug = null,
    ) {
        $orgId = (string) Str::uuid();
        $this->organization = Organization::factory()->create([
            'id' => $orgId,
            'console_organization_id' => $orgId,
        ]);

        $this->brand = Brand::factory()->create([
            'console_organization_id' => $orgId,
            'slug' => 'policy-brand-'.Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        $branchOrgUnitId = (string) Str::uuid();
        $this->shop = Branch::factory()->create([
            'console_organization_id' => $orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'console_branch_id' => $branchOrgUnitId,
            'slug' => $shopSlug ?? 'policy-shop-'.Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        $brandOwner = (string) Str::uuid();
        $operator = $managementModel === BranchManagementModel::FranchiseOwned
            ? (string) Str::uuid()
            : $brandOwner;

        $this->identity = [
            'identity_brand_id' => (string) $this->brand->console_brand_id,
            'brand_owner_org_unit_id' => $brandOwner,
            'operator_org_unit_id' => $operator,
            'ownership_revision' => 'ownership-revision-00010',
        ];

        $this->ownershipSource = new InMemoryBranchManagementProjectionSource([
            $branchOrgUnitId => new BranchManagementProjection(
                $this->organization->id,
                $branchOrgUnitId,
                $this->identity['identity_brand_id'],
                $managementModel,
                $brandOwner,
                $operator,
                $this->identity['ownership_revision'],
                'resolved',
            ),
        ]);

        $this->ownerPolicySource = new InMemoryPaymentOwnerOptionPolicySource;

        $this->provider = PaymentGatewayProvider::query()->firstOrCreate(
            ['code' => PaymentGatewayProviderCodeEnum::Stripe->value],
            ['is_active' => true, 'sort_order' => 1],
        );

        $catalog = PaymentGatewayFixtures::fullCapability();
        $this->option = PaymentGatewayOption::query()->firstOrCreate(
            [
                'provider_id' => $this->provider->id,
                'code' => $catalog->id,
            ],
            [
                'integration_product' => $catalog->integrationProduct,
                'api_version' => $catalog->apiVersion,
                'rail' => $catalog->rail->value,
                'method_type' => $catalog->methodType,
                'brands' => $catalog->brands,
                'channels' => ['pos', 'workstation', 'customer_web'],
                'device_classes' => ['browser', 'terminal', 'workstation'],
                'currencies' => array_map(static fn ($currency) => strtoupper($currency->code), $catalog->currencies),
                'workflows' => ['sale'],
                'operations' => ['sale', 'refund', 'retrieve_payment', 'retrieve_refund', 'webhook_verification'],
                'limits' => [
                    'partial_capture' => false,
                    'partial_refund' => false,
                    'multiple_refunds' => false,
                ],
                'recovery' => [
                    'retrieve_payment' => true,
                    'retrieve_refund' => true,
                    'webhook_verification' => true,
                    'strategy' => 'daily_reconciliation',
                ],
                'merchant_identity_requirements' => ['connected_account'],
                'revision' => $catalog->revision,
                'effective_from' => now()->subDay(),
                'is_active' => true,
                'sort_order' => 1,
            ],
        );

        $this->manager = User::factory()->create([
            'console_organization_id' => $orgId,
        ]);
        grantOrgAccess($this->manager, $orgId);
    }

    public function bind(): void
    {
        app()->instance(BranchManagementProjectionSource::class, $this->ownershipSource);
        app()->instance(PaymentOwnerOptionPolicySource::class, $this->ownerPolicySource);
    }

    public function shopBase(): string
    {
        return "/api/v1/shops/{$this->shop->slug}";
    }

    /** @param array<string, mixed> $overrides */
    public function seedConnection(array $overrides = []): PaymentGatewayConnection
    {
        $ownerScope = $overrides['owner_scope'] ?? PaymentConnectionOwnerScopeEnum::Hq->value;
        $connectionId = $overrides['id'] ?? (string) Str::uuid();

        DB::table('payment_gateway_connections')->insert(array_merge([
            'id' => $connectionId,
            'provider_id' => $this->provider->id,
            'organization_id' => $this->organization->id,
            'brand_id' => $this->brand->id,
            'owner_branch_id' => null,
            'identity_brand_id' => $this->identity['identity_brand_id'],
            'owner_scope' => $ownerScope,
            'brand_owner_org_unit_id' => $this->identity['brand_owner_org_unit_id'],
            'operator_org_unit_id' => $this->identity['operator_org_unit_id'],
            'ownership_revision' => $this->identity['ownership_revision'],
            'environment' => PaymentGatewayEnvironmentEnum::Test->value,
            'merchant_account_id' => $overrides['merchant_account_id'] ?? 'acct_policy_test',
            'merchant_display_name' => $overrides['merchant_display_name'] ?? 'Policy Test Merchant',
            'charge_model' => 'direct',
            'health' => PaymentConnectionHealthEnum::Ready->value,
            'health_reason_code' => 'ready',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides, [
            'provider_id' => $this->provider->id,
            'organization_id' => $this->organization->id,
            'brand_id' => $this->brand->id,
        ]));

        if ($ownerScope === PaymentConnectionOwnerScopeEnum::Franchise->value) {
            DB::table('payment_gateway_connections')
                ->where('id', $connectionId)
                ->update(['owner_branch_id' => $this->shop->id]);
        }

        DB::table('payment_gateway_connection_options')->insert([
            'id' => (string) Str::uuid(),
            'connection_id' => $connectionId,
            'option_id' => $this->option->id,
            'verification_state' => 'verified',
            'approved_currencies' => json_encode(['JPY'], JSON_THROW_ON_ERROR),
            'approved_channels' => json_encode(['pos', 'workstation'], JSON_THROW_ON_ERROR),
            'approved_operations' => json_encode(['sale', 'refund', 'retrieve_payment', 'retrieve_refund', 'webhook_verification'], JSON_THROW_ON_ERROR),
            'capability_revision' => 1,
            'effective_from' => now()->subDay(),
            'evidence_ref' => 'contract:payment-policy-test',
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PaymentGatewayConnection::query()->findOrFail($connectionId);
    }

    public function seedDevice(string $type = 'pos'): Device
    {
        return Device::factory()->create([
            'type' => $type,
            'status' => 'active',
            'device_token' => Str::random(64),
            'organization_id' => $this->organization->id,
            'branch_id' => $this->shop->id,
        ]);
    }

    public function registerUnresolvedOwnership(string $reason = 'multiple_active_franchise_grants'): void
    {
        $branchOrgUnitId = (string) $this->shop->console_branch_id;
        $this->ownershipSource->register(
            $branchOrgUnitId,
            BranchManagementProjection::unresolved(
                new BranchManagementLookup(
                    $this->organization->id,
                    $this->identity['identity_brand_id'],
                    $branchOrgUnitId,
                    new \DateTimeImmutable('now'),
                ),
                $reason,
            ),
        );
    }

    public function switchManagementModel(BranchManagementModel $managementModel): void
    {
        $branchOrgUnitId = (string) $this->shop->console_branch_id;
        $brandOwner = $this->identity['brand_owner_org_unit_id'];
        $operator = $managementModel === BranchManagementModel::FranchiseOwned
            ? (string) Str::uuid()
            : $brandOwner;

        if ($managementModel === BranchManagementModel::FranchiseOwned) {
            $this->identity['operator_org_unit_id'] = $operator;
        }

        $this->ownershipSource->register(
            $branchOrgUnitId,
            new BranchManagementProjection(
                $this->organization->id,
                $branchOrgUnitId,
                $this->identity['identity_brand_id'],
                $managementModel,
                $brandOwner,
                $operator,
                $this->identity['ownership_revision'],
                'resolved',
            ),
        );
    }

    public function publishInitialPolicyRevision(): PaymentPolicyRevision
    {
        $connection = PaymentGatewayConnection::query()
            ->where('organization_id', $this->organization->id)
            ->where('brand_id', $this->brand->id)
            ->first();

        if ($connection === null) {
            $this->seedConnection();
        }

        $existing = PaymentPolicyRevision::query()
            ->where('branch_id', $this->shop->id)
            ->orderByDesc('revision')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        app(PaymentPolicyEvaluationService::class)->updateShopOption(
            $this->shop,
            $this->option,
            ['preference' => 'inherit'],
        );

        return PaymentPolicyRevision::query()
            ->where('branch_id', $this->shop->id)
            ->orderByDesc('revision')
            ->firstOrFail();
    }

    /** @return array{revision: int, option_id: string, connection_id: string} */
    public function currentEffectiveIdentity(): array
    {
        $payload = app(PaymentPolicyEvaluationService::class)->effectiveOptions($this->shop);

        $option = $payload['options'][0] ?? null;
        if (! is_array($option)) {
            throw new \RuntimeException('Expected at least one effective payment option.');
        }

        return [
            'revision' => (int) $payload['revision'],
            'option_id' => (string) $option['id'],
            'connection_id' => (string) $option['connection_id'],
        ];
    }

    public function seedCashPaymentMethod(): PaymentMethod
    {
        return PaymentMethod::factory()->cash()->create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->shop->id,
            'code' => 'cash',
            'is_active' => true,
        ]);
    }

    public function seedCheckoutOrder(float $total = 1000.0): CustomerOrder
    {
        return CustomerOrder::factory()->create([
            'organization_id' => $this->organization->id,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->shop->id,
            'status' => 'checkout',
            'total_amount' => $total,
            'paid_amount' => 0,
        ]);
    }

    public function seedKioskDevice(): Device
    {
        return $this->seedDevice('kiosk');
    }

    public function seedWorkstationDevice(): Device
    {
        return $this->seedDevice('workstation');
    }
}
