<?php

namespace Tests\Feature\Payment;

use App\Http\Resources\PaymentGatewayConnectionResource;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentPolicyRevision;
use App\Models\Role;
use App\Models\ShopPaymentOption;
use App\Models\User;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Modules\PaymentGatewayProvider\Policies\PaymentGatewayProviderPolicyBase;
use App\Policies\PaymentPolicyRevisionPolicy;
use App\Services\Payment\Admin\PaymentAdminAuthorizationService;
use App\Services\Payment\Policy\Contracts\BranchManagementProjectionSource;
use App\Services\Payment\Policy\Enums\BranchManagementModel;
use App\Services\Payment\Policy\ValueObjects\BranchManagementLookup;
use App\Services\Payment\Policy\ValueObjects\BranchManagementProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PaymentAdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private Brand $brandA;

    private Branch $hqShop;

    private BranchManagementProjection $hqOwnership;

    private BranchManagementProjection $franchiseOwnership;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create([
            'id' => (string) Str::uuid(),
            'console_organization_id' => (string) Str::uuid(),
        ]);
        $this->orgB = Organization::factory()->create([
            'id' => (string) Str::uuid(),
            'console_organization_id' => (string) Str::uuid(),
        ]);

        $this->brandA = Brand::factory()->create([
            'console_organization_id' => $this->orgA->console_organization_id,
        ]);

        $this->hqShop = Branch::factory()->create([
            'console_organization_id' => $this->orgA->console_organization_id,
            'console_brand_id' => $this->brandA->console_brand_id,
            'slug' => 'payment-auth-hq-shop',
        ]);

        Role::firstOrCreate(['slug' => 'org-admin'], ['name' => 'Org Admin', 'level' => 100]);
        Role::firstOrCreate(['slug' => 'shop-manager'], ['name' => 'Shop Manager', 'level' => 60]);
        Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff', 'level' => 30]);

        $brandOwner = (string) Str::uuid();
        $franchiseOperator = (string) Str::uuid();

        $this->hqOwnership = new BranchManagementProjection(
            $this->orgA->id,
            (string) $this->hqShop->console_branch_id,
            (string) Str::uuid(),
            BranchManagementModel::HqManaged,
            $brandOwner,
            $brandOwner,
            '42',
            'resolved',
        );

        $this->franchiseOwnership = new BranchManagementProjection(
            $this->orgA->id,
            (string) $this->hqShop->console_branch_id,
            (string) Str::uuid(),
            BranchManagementModel::FranchiseOwned,
            $brandOwner,
            $franchiseOperator,
            '43',
            'resolved',
        );

        $this->app->instance(
            BranchManagementProjectionSource::class,
            new FixedPaymentAdminOwnershipSource($this->hqOwnership, $this->franchiseOwnership),
        );

        request()->attributes->set('organization_id', $this->orgA->id);
        request()->attributes->set('shop_id', $this->hqShop->id);
        request()->attributes->set('identity_brand_id', $this->hqOwnership->identityBrandId);
    }

    public function test_a5_cross_tenant_connection_is_concealed_and_not_authorizable(): void
    {
        $foreign = $this->makeConnection($this->orgB);
        $service = app(PaymentAdminAuthorizationService::class);
        $viewer = $this->makeUser('org-admin', $this->orgA->id);

        self::assertNull($service->concealUnlessOrganization($foreign, $this->orgA->id));
        self::assertFalse(Gate::forUser($viewer)->allows('view', $foreign));
        self::assertFalse(Gate::forUser($viewer)->allows('rotateCredentials', $foreign));
    }

    public function test_a6_same_branch_ownership_projection_is_identical_across_roles(): void
    {
        $service = app(PaymentAdminAuthorizationService::class);
        $admin = $this->makeUser('org-admin', $this->orgA->id);
        $manager = $this->makeUser('shop-manager', $this->orgA->id, $this->hqShop->id);
        $cashier = $this->makeUser('staff', $this->orgA->id, $this->hqShop->id);

        $adminProjection = $service->resolveOwnership($this->hqShop, $this->hqOwnership->identityBrandId);
        request()->attributes->set('shop_id', $this->hqShop->id);
        $managerProjection = $service->resolveOwnership($this->hqShop, $this->hqOwnership->identityBrandId);
        $cashierProjection = $service->resolveOwnership($this->hqShop, $this->hqOwnership->identityBrandId);

        self::assertSame($adminProjection->managementModel, $managerProjection->managementModel);
        self::assertSame($adminProjection->operatorOrgUnitId, $cashierProjection->operatorOrgUnitId);
        self::assertSame($adminProjection->ownershipRevision, $managerProjection->ownershipRevision);

        $connection = $this->makeConnection($this->orgA, hq: true);

        self::assertTrue(Gate::forUser($admin)->allows('view', $connection));
        self::assertTrue(Gate::forUser($manager)->allows('view', $connection));
        self::assertTrue(Gate::forUser($cashier)->allows('view', $connection));
        self::assertTrue(Gate::forUser($admin)->allows('update', $connection));
        self::assertFalse(Gate::forUser($manager)->allows('update', $connection));
        self::assertFalse(Gate::forUser($cashier)->allows('update', $connection));
    }

    public function test_a7_credential_mutations_are_denied_for_shop_manager_cashier_and_device(): void
    {
        $connection = $this->makeConnection($this->orgA, hq: true);
        $manager = $this->makeUser('shop-manager', $this->orgA->id, $this->hqShop->id);
        $cashier = $this->makeUser('staff', $this->orgA->id, $this->hqShop->id);
        $service = app(PaymentAdminAuthorizationService::class);
        $device = Device::factory()->create([
            'organization_id' => $this->orgA->id,
            'branch_id' => $this->hqShop->id,
            'status' => 'active',
        ]);

        self::assertFalse(Gate::forUser($manager)->allows('rotateCredentials', $connection));
        self::assertFalse(Gate::forUser($manager)->allows('validateConnection', $connection));
        self::assertFalse(Gate::forUser($cashier)->allows('rotateCredentials', $connection));
        // #1666 — luật "thiết bị không bao giờ sửa chính sách" không đọc thiết bị
        // nào, nên chữ ký bỏ tham số. Vẫn dựng `$device` để bài test nói đúng cảnh
        // huống: có một thiết bị đang hoạt động ở chi nhánh này, và nó vẫn bị chặn.
        self::assertTrue($device->exists);
        self::assertFalse($service->deviceCanMutatePaymentPolicy());
    }

    public function test_a7_connection_list_payload_exposes_display_identity_without_secret_fields(): void
    {
        $connection = $this->makeConnection($this->orgA, hq: true);
        $payload = (new PaymentGatewayConnectionResource($connection))->resolve();

        self::assertSame($connection->merchant_display_name, $payload['merchant_display_name']);
        self::assertArrayNotHasKey('secret_ref', $payload);
        self::assertArrayNotHasKey('webhook_secret_ref', $payload);
        self::assertArrayNotHasKey('secret_version', $payload);
        self::assertArrayNotHasKey('key_fingerprint', $payload);
        self::assertStringNotContainsString((string) $connection->secret_ref, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_g7_hq_managed_shop_manager_has_read_only_connection_controls(): void
    {
        $connection = $this->makeConnection($this->orgA, hq: true);
        $manager = $this->makeUser('shop-manager', $this->orgA->id, $this->hqShop->id);
        $shopOption = ShopPaymentOption::factory()->create([
            'organization_id' => $this->orgA->id,
            'brand_id' => $this->brandA->id,
            'branch_id' => $this->hqShop->id,
        ]);

        self::assertTrue(Gate::forUser($manager)->allows('view', $connection));
        self::assertFalse(Gate::forUser($manager)->allows('rotateCredentials', $connection));
        self::assertFalse(Gate::forUser($manager)->allows('disconnect', $connection));
        self::assertTrue(Gate::forUser($manager)->allows('update', $shopOption));
    }

    public function test_franchise_shop_manager_can_mutate_own_franchise_connection_only(): void
    {
        request()->attributes->set('identity_brand_id', $this->franchiseOwnership->identityBrandId);

        $connection = PaymentGatewayConnection::factory()->create([
            'organization_id' => $this->orgA->id,
            'brand_id' => $this->brandA->id,
            'owner_scope' => 'franchise',
            'owner_branch_id' => $this->hqShop->id,
            'identity_brand_id' => $this->franchiseOwnership->identityBrandId,
            'environment' => PaymentGatewayEnvironmentEnum::Test,
            'merchant_account_id' => 'acct_franchise_'.Str::lower(Str::random(6)),
        ]);

        $manager = $this->makeUser('shop-manager', $this->orgA->id, $this->hqShop->id);

        self::assertTrue(Gate::forUser($manager)->allows('update', $connection));
        self::assertTrue(Gate::forUser($manager)->allows('rotateCredentials', $connection));
    }

    public function test_yaml_catalog_and_revision_policies_fail_closed_on_mutations(): void
    {
        $providerPolicy = new PaymentGatewayProviderPolicyBase;
        $admin = $this->makeUser('org-admin', $this->orgA->id);
        $provider = PaymentGatewayProvider::factory()->create([
            'code' => PaymentGatewayProviderCodeEnum::Stripe,
            'is_active' => true,
        ]);
        $revision = PaymentPolicyRevision::factory()->create([
            'organization_id' => $this->orgA->id,
            'brand_id' => $this->brandA->id,
            'branch_id' => $this->hqShop->id,
        ]);

        self::assertFalse($providerPolicy->create($admin));
        self::assertFalse($providerPolicy->update($admin, $provider));
        self::assertFalse((new PaymentPolicyRevisionPolicy(app(PaymentAdminAuthorizationService::class)))->update($admin, $revision));
        self::assertFalse((new PaymentPolicyRevisionPolicy(app(PaymentAdminAuthorizationService::class)))->delete($admin, $revision));
    }

    private function makeConnection(Organization $organization, bool $hq = false): PaymentGatewayConnection
    {
        $provider = PaymentGatewayProvider::factory()->create([
            'code' => PaymentGatewayProviderCodeEnum::Stripe,
            'is_active' => true,
        ]);

        return PaymentGatewayConnection::factory()->create([
            'provider_id' => $provider->id,
            'organization_id' => $organization->id,
            'brand_id' => $this->brandA->id,
            'owner_scope' => $hq ? 'hq' : 'franchise',
            'owner_branch_id' => $hq ? null : $this->hqShop->id,
            'identity_brand_id' => $this->hqOwnership->identityBrandId,
            'environment' => PaymentGatewayEnvironmentEnum::Test,
            'merchant_account_id' => 'acct_'.Str::lower(Str::random(8)),
            'merchant_display_name' => 'Safe Merchant Display',
            'secret_ref' => (string) Str::uuid(),
            'webhook_secret_ref' => (string) Str::uuid(),
            'secret_version' => '1',
            'key_fingerprint' => hash('sha256', 'secret-never-exposed'),
        ]);
    }

    private function makeUser(string $role, string $organizationId, ?string $branchId = null): User
    {
        $user = User::factory()->create([
            'console_organization_id' => Organization::query()->findOrFail($organizationId)->console_organization_id,
        ]);
        $user->assignRole($role, $organizationId, $branchId);

        return $user;
    }
}

final readonly class FixedPaymentAdminOwnershipSource implements BranchManagementProjectionSource
{
    public function __construct(
        private BranchManagementProjection $hqOwnership,
        private BranchManagementProjection $franchiseOwnership,
    ) {}

    public function resolve(BranchManagementLookup $lookup): BranchManagementProjection
    {
        if ($lookup->identityBrandId === $this->franchiseOwnership->identityBrandId) {
            return $this->franchiseOwnership;
        }

        return $this->hqOwnership;
    }
}
