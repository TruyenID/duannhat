<?php

namespace App\Services\Payment\Policy\Persistence;

use App\Models\Branch;
use App\Models\DevicePaymentOption;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayConnectionOption;
use App\Models\PaymentGatewayOption;
use App\Models\ShopPaymentOption;
use App\Omnify\Enums\PaymentConnectionHealthEnum;
use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentPolicyPreferenceEnum;
use App\Services\Payment\Policy\Contracts\PaymentOwnerOptionPolicySource;
use App\Services\Payment\Policy\Enums\DevicePolicyPreference;
use App\Services\Payment\Policy\Enums\UpstreamPolicyState;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicyCandidate;
use Symfony\Component\Uid\Uuid;

/** Secret-free candidate assembly confined to the persistence adapter boundary. */
final class EloquentPaymentPolicyCandidateLoader
{
    public function __construct(
        private readonly PaymentGatewayCapabilityMapper $mapper,
        private readonly PaymentOwnerOptionPolicySource $ownerPolicySource,
    ) {}

    /**
     * @return list<PaymentPolicyCandidate>
     */
    public function loadForBranch(Branch $shop, ?string $deviceId = null): array
    {
        $shop->loadMissing('brand');
        $brand = $shop->brand;
        if ($brand === null) {
            return [];
        }

        $organization = Organization::query()
            ->where('console_organization_id', $shop->console_organization_id)
            ->first();
        if ($organization === null) {
            return [];
        }

        $organizationId = (string) $organization->id;
        $brandId = (string) $brand->id;
        $branchId = (string) $shop->id;
        $identityBrandId = (string) ($brand->console_brand_id ?: $brand->id);
        $environment = $this->resolveEnvironment();

        $shopOptions = ShopPaymentOption::query()
            ->where('branch_id', $branchId)
            ->get()
            ->keyBy('option_id');

        $deviceOptions = $deviceId === null
            ? collect()
            : DevicePaymentOption::query()
                ->where('device_id', $deviceId)
                ->where('branch_id', $branchId)
                ->get()
                ->keyBy('shop_payment_option_id');

        $rows = PaymentGatewayConnectionOption::query()
            ->with([
                'connection.provider',
                'option.provider',
            ])
            ->whereHas('connection', function ($query) use ($organizationId, $brandId): void {
                $query->where('organization_id', $organizationId)
                    ->where('brand_id', $brandId)
                    ->where('is_active', true);
            })
            ->whereHas('option', fn ($query) => $query->where('is_active', true))
            ->get();

        $candidates = [];

        /** @var PaymentGatewayConnectionOption $row */
        foreach ($rows as $row) {
            $connection = $row->connection;
            $option = $row->option;
            $provider = $option?->provider;

            if (! $connection instanceof PaymentGatewayConnection
                || ! $option instanceof PaymentGatewayOption
                || $provider === null) {
                continue;
            }

            $shopOption = $shopOptions->get($option->id);
            $shopOptionId = $shopOption?->id ?? $this->deterministicShopOptionId($branchId, (string) $option->id);
            $devicePreference = DevicePolicyPreference::Inherit;

            if ($shopOption !== null && $deviceOptions->has($shopOption->id)) {
                $devicePreference = DevicePolicyPreference::from(
                    (string) $deviceOptions->get($shopOption->id)->preference,
                );
            }

            $catalog = $this->mapper->catalogFromOption(
                $option,
                $provider,
                $connection->environment instanceof PaymentGatewayEnvironmentEnum
                    ? $connection->environment
                    : PaymentGatewayEnvironmentEnum::from((string) $connection->environment),
            );

            $ownerPolicy = $this->ownerPolicySource->resolve($brandId, (string) $option->id);
            $shopPreference = $shopOption?->preference instanceof PaymentPolicyPreferenceEnum
                ? $shopOption->preference
                : PaymentPolicyPreferenceEnum::Inherit;

            if ($ownerPolicy === UpstreamPolicyState::Denied) {
                $shopPreference = PaymentPolicyPreferenceEnum::Blocked;
            }

            $candidates[] = new PaymentPolicyCandidate(
                (string) $option->id,
                $this->defaultPaymentBrand($catalog->brands),
                (string) $connection->id,
                (string) $row->id,
                $shopOptionId,
                $organizationId,
                $brandId,
                $branchId,
                (string) $connection->identity_brand_id,
                $connection->owner_scope instanceof PaymentConnectionOwnerScopeEnum
                    ? $connection->owner_scope
                    : PaymentConnectionOwnerScopeEnum::from((string) $connection->owner_scope),
                $connection->owner_branch_id === null ? null : (string) $connection->owner_branch_id,
                (string) $connection->brand_owner_org_unit_id,
                (string) $connection->operator_org_unit_id,
                (string) $connection->ownership_revision,
                $catalog->provider,
                $catalog->environment,
                (bool) $provider->is_active,
                (bool) $option->is_active,
                (bool) $connection->is_active,
                $connection->health instanceof PaymentConnectionHealthEnum
                    ? $connection->health
                    : PaymentConnectionHealthEnum::from((string) $connection->health),
                $catalog,
                $this->mapper->connectionCapabilityFromRow($row, $option, $catalog),
                $ownerPolicy,
                $shopPreference,
                $deviceId,
                $devicePreference,
                true,
                $shopOption !== null
                    && $shopOption->connection_id !== null
                    && (string) $shopOption->connection_id === (string) $connection->id,
            );
        }

        return $candidates;
    }

    private function deterministicShopOptionId(string $branchId, string $optionId): string
    {
        return (string) Uuid::v5(
            Uuid::fromString($branchId),
            'shop-payment-option:'.$optionId,
        );
    }

    /** @param list<string> $brands */
    private function defaultPaymentBrand(array $brands): ?string
    {
        if ($brands === []) {
            return null;
        }

        if ($brands === ['account_configured']) {
            return 'visa';
        }

        return $brands[0];
    }

    private function resolveEnvironment(): PaymentGatewayEnvironmentEnum
    {
        return app()->environment('production')
            ? PaymentGatewayEnvironmentEnum::Live
            : PaymentGatewayEnvironmentEnum::Test;
    }
}
