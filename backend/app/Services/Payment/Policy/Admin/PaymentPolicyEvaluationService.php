<?php

namespace App\Services\Payment\Policy\Admin;

use App\Models\Branch;
use App\Models\DevicePaymentOption;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentPolicyRevision;
use App\Models\ShopPaymentOption;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentPolicyPreferenceEnum;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Policy\Admin\Exceptions\PaymentGatewayMutationForbiddenException;
use App\Services\Payment\Policy\Admin\Exceptions\PaymentPolicyCannotWidenException;
use App\Services\Payment\Policy\Contracts\BranchManagementProjectionSource;
use App\Services\Payment\Policy\Contracts\PaymentOwnerOptionPolicySource;
use App\Services\Payment\Policy\Enums\BranchManagementModel;
use App\Services\Payment\Policy\Enums\DevicePolicyPreference;
use App\Services\Payment\Policy\Enums\PaymentPolicyPublicationSource;
use App\Services\Payment\Policy\Enums\UpstreamPolicyState;
use App\Services\Payment\Policy\PaymentPolicyResolver;
use App\Services\Payment\Policy\PaymentPolicyRevisionPublisher;
use App\Services\Payment\Policy\Persistence\EloquentPaymentPolicyCandidateLoader;
use App\Services\Payment\Policy\ValueObjects\BranchManagementProjection;
use App\Services\Payment\Policy\ValueObjects\EffectivePaymentOption;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicyCandidate;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicyRequest;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicySnapshotInput;
use App\Services\Payment\Policy\ValueObjects\PublishedPaymentPolicyRevision;
use DateTimeImmutable;
use Illuminate\Support\Str;

final class PaymentPolicyEvaluationService
{
    public function __construct(
        private readonly BranchManagementProjectionSource $ownershipSource,
        private readonly PaymentPolicyResolver $resolver,
        private readonly EloquentPaymentPolicyCandidateLoader $candidateLoader,
        private readonly PaymentPolicyRevisionPublisher $revisionPublisher,
        private readonly EffectivePaymentOptionsPresenter $presenter,
        private readonly PaymentOwnerOptionPolicySource $ownerPolicySource,
        private readonly PosEffectivePaymentOptionEnricher $internalTenders,
    ) {}

    /** @return array<string, mixed> */
    public function shopConfiguration(Branch $shop): array
    {
        $ownership = $this->ownership($shop);
        // ADMIN view — the branch settings screen must list what the branch can
        // actually take money with, and that includes internal tenders (cash,
        // standalone card terminal). They have no gateway connection, so the
        // fail-closed candidate loader can never surface them: the screen showed
        // 2 rows where HQ showed 4, and cash simply could not be seen or managed
        // per branch (#F10). `effectiveOptions()` below is left alone on purpose
        // — that one feeds the published policy snapshot the payment validator
        // reads, and internal tenders are outside gateway policy by design.
        $evaluation = $this->internalTenders->enrichEvaluation($this->evaluate($shop), $shop);
        $connections = $this->safeConnections($shop, $ownership);

        return [
            'ownership' => $this->presenter->ownership($ownership),
            'connections' => $connections,
            'options' => $evaluation['options'],
            'revision' => $evaluation['revision'],
            'snapshot_hash' => $evaluation['snapshot_hash'],
            'setup_required' => $connections === [] && $ownership->managementModel === BranchManagementModel::FranchiseOwned,
            'connection_mutable' => $ownership->managementModel === BranchManagementModel::FranchiseOwned,
        ];
    }

    /** @return array<string, mixed> */
    public function effectiveOptions(Branch $shop, ?string $deviceId = null, PaymentChannelEnum $channel = PaymentChannelEnum::Pos): array
    {
        return $this->evaluate($shop, $deviceId, $channel);
    }

    /**
     * plan-055 T2.1 (#1821) — publish a branch's first policy revision.
     *
     * A branch with no published revision fails checkout outright the moment
     * enforcement becomes mandatory ("No effective payment options are
     * available for checkout"), so Gate 2 of plan-055 has to backfill every
     * active branch before Gate 6 can flip the flag.
     *
     * This delegates to the same `publishIfChanged()` every other mutation on
     * this service uses, and that is the whole point: the snapshot is derived
     * from CONFIGURATION (`candidateLoader` + `resolveAllEffective`), never
     * from a previous snapshot — so a branch at zero revisions is publishable,
     * and what lands is what the shop ACTUALLY accepts. Hand-building a
     * snapshot in the backfill command would be the straight road to "enable
     * everything to be safe", which is the one outcome plan-055 forbids: it
     * makes the policy lie, and the later tightening then tightens onto a
     * false baseline.
     *
     * Idempotent by construction — `publishAtomically()` returns the existing
     * revision untouched when the snapshot hash is unchanged.
     */
    public function publishInitialRevision(Branch $shop): PublishedPaymentPolicyRevision
    {
        return $this->publishIfChanged($shop, PaymentPolicyPublicationSource::Initial);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function onboardFranchiseConnection(Branch $shop, array $payload): array
    {
        $this->assertFranchiseMutable($shop);

        $connection = PaymentGatewayConnection::query()->create([
            'provider_id' => $payload['provider_id'],
            'organization_id' => $this->tenantScope($shop)['organization_id'],
            'brand_id' => $this->tenantScope($shop)['brand_id'],
            'owner_branch_id' => $shop->id,
            'identity_brand_id' => (string) ($shop->brand?->console_brand_id ?: $shop->brand?->id),
            'owner_scope' => 'franchise',
            'brand_owner_org_unit_id' => $payload['brand_owner_org_unit_id'],
            'operator_org_unit_id' => $payload['operator_org_unit_id'],
            'ownership_revision' => $payload['ownership_revision'],
            'environment' => $payload['environment'] ?? PaymentGatewayEnvironmentEnum::Test->value,
            'merchant_account_id' => $payload['merchant_account_id'],
            'merchant_display_name' => $payload['merchant_display_name'] ?? null,
            'charge_model' => $payload['charge_model'] ?? 'direct',
            'health' => 'pending_verification',
            'is_active' => true,
        ]);

        $this->publishIfChanged($shop, PaymentPolicyPublicationSource::ConnectionChanged);

        return $this->presenter->connection($connection->fresh('provider'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateFranchiseConnection(Branch $shop, PaymentGatewayConnection $connection, array $payload): array
    {
        $this->assertFranchiseMutable($shop);
        $this->assertConnectionInShopScope($shop, $connection);

        $allowed = [
            'merchant_display_name',
            'merchant_store_id',
            'merchant_terminal_id',
            'charge_model',
        ];
        $connection->update(collect($payload)->only($allowed)->all());
        $this->publishIfChanged($shop, PaymentPolicyPublicationSource::ConnectionChanged);

        return $this->presenter->connection($connection->fresh('provider'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateShopOption(Branch $shop, PaymentGatewayOption $option, array $payload): array
    {
        $preference = PaymentPolicyPreferenceEnum::from((string) $payload['preference']);
        if ($preference === PaymentPolicyPreferenceEnum::Blocked || $preference === PaymentPolicyPreferenceEnum::Enabled) {
            throw new PaymentPolicyCannotWidenException('Shop preference cannot enable or set blocked upstream policy.');
        }

        $ownerPolicy = $this->ownerPolicySource->resolve($this->tenantScope($shop)['brand_id'], (string) $option->id);
        if ($ownerPolicy === UpstreamPolicyState::Denied && $preference !== PaymentPolicyPreferenceEnum::Disabled) {
            throw new PaymentPolicyCannotWidenException('HQ-blocked options can only remain disabled or inherit.');
        }

        if ($preference === PaymentPolicyPreferenceEnum::Inherit) {
            ShopPaymentOption::query()
                ->where('branch_id', $shop->id)
                ->where('option_id', $option->id)
                ->delete();
        } else {
            ShopPaymentOption::query()->updateOrCreate(
                [
                    'branch_id' => $shop->id,
                    'option_id' => $option->id,
                ],
                [
                    'organization_id' => $this->tenantScope($shop)['organization_id'],
                    'brand_id' => $this->tenantScope($shop)['brand_id'],
                    'connection_id' => $payload['connection_id'] ?? null,
                    'preference' => $preference->value,
                    'change_reason' => $payload['change_reason'] ?? null,
                ],
            );
        }

        $published = $this->publishIfChanged($shop, PaymentPolicyPublicationSource::ShopPolicyChanged);
        // Same enrichment as shopConfiguration(): without it, saving a
        // preference for an internal tender answered `200 {"option": null}` —
        // the write landed, the caller got back nothing to render, and any
        // client trusting `data.option` read a null (#F10).
        $evaluation = $this->internalTenders->enrichEvaluation($this->evaluate($shop), $shop);

        return [
            'option' => collect($evaluation['options'])->firstWhere('id', $option->id),
            'revision' => $published->revision,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateDeviceOption(
        Branch $shop,
        string $deviceId,
        PaymentGatewayOption $option,
        array $payload,
    ): array {
        $rawPreference = (string) $payload['preference'];
        if (! in_array($rawPreference, [DevicePolicyPreference::Inherit->value, DevicePolicyPreference::Disabled->value], true)) {
            throw new PaymentPolicyCannotWidenException('Device policy may only inherit or disable.');
        }

        $preference = DevicePolicyPreference::from($rawPreference);
        $shopOption = $this->resolveShopOption($shop, $option);

        $shopEffective = $this->evaluate($shop)['options'];
        $target = collect($shopEffective)->firstWhere('id', $option->id);
        if ($preference === DevicePolicyPreference::Inherit) {
            DevicePaymentOption::query()
                ->where('device_id', $deviceId)
                ->where('shop_payment_option_id', $shopOption->id)
                ->delete();
        } else {
            if (($target['effective'] ?? false) !== true) {
                throw new PaymentPolicyCannotWidenException('Device cannot enable a shop-disabled or upstream-blocked option.');
            }

            $tenant = $this->tenantScope($shop);
            DevicePaymentOption::query()->updateOrCreate(
                [
                    'device_id' => $deviceId,
                    'shop_payment_option_id' => $shopOption->id,
                ],
                [
                    'organization_id' => $tenant['organization_id'],
                    'branch_id' => $shop->id,
                    'preference' => $preference->value,
                    'change_reason' => $payload['change_reason'] ?? null,
                ],
            );
        }

        $published = $this->publishIfChanged($shop, PaymentPolicyPublicationSource::DevicePolicyChanged);
        $evaluation = $this->evaluate($shop, $deviceId);

        return [
            'option' => collect($evaluation['options'])->firstWhere('id', $option->id),
            'revision' => $published->revision,
        ];
    }

    private function resolveShopOption(Branch $shop, PaymentGatewayOption $option): ShopPaymentOption
    {
        $tenant = $this->tenantScope($shop);

        return ShopPaymentOption::query()->firstOrCreate(
            [
                'branch_id' => $shop->id,
                'option_id' => $option->id,
            ],
            [
                'organization_id' => $tenant['organization_id'],
                'brand_id' => $tenant['brand_id'],
                'connection_id' => null,
                'preference' => PaymentPolicyPreferenceEnum::Inherit->value,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function evaluate(
        Branch $shop,
        ?string $deviceId = null,
        PaymentChannelEnum $channel = PaymentChannelEnum::Pos,
    ): array {
        $shop->loadMissing('brand');
        $ownership = $this->ownership($shop);
        $candidates = $this->candidateLoader->loadForBranch($shop, $deviceId);
        $latestRevision = PaymentPolicyRevision::query()
            ->where('branch_id', $shop->id)
            ->orderByDesc('revision')
            ->first();

        $grouped = [];
        foreach ($candidates as $candidate) {
            $grouped[$candidate->optionId][] = $candidate;
        }

        $options = [];
        foreach ($grouped as $optionId => $optionCandidates) {
            $request = $this->requestForOption($shop, $optionId, $optionCandidates[0], $deviceId, $channel);
            $resolved = $this->resolver->resolve($request, $optionCandidates);
            $options[] = $this->presenter->option(
                $resolved,
                displayName: (string) ($optionCandidates[0]->catalogCapability->methodType ?? 'payment'),
                provider: $optionCandidates[0]->connectionProvider->value,
                rail: $optionCandidates[0]->catalogCapability->rail->value,
                shopPreference: $optionCandidates[0]->shopPreference->value,
                devicePreference: $optionCandidates[0]->devicePreference->value,
                methodType: $optionCandidates[0]->catalogCapability->methodType,
            );
        }

        usort($options, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return [
            'revision' => $latestRevision?->revision ?? 0,
            'snapshot_hash' => $latestRevision?->snapshot_hash,
            'ownership_revision' => $ownership->ownershipRevision,
            'published_at' => $latestRevision?->published_at?->toIso8601String(),
            'options' => $options,
        ];
    }

    private function publishIfChanged(Branch $shop, PaymentPolicyPublicationSource $source): PublishedPaymentPolicyRevision
    {
        $ownership = $this->ownership($shop);
        $candidates = $this->candidateLoader->loadForBranch($shop);

        return $this->revisionPublisher->publish(
            new PaymentPolicySnapshotInput(
                $this->tenantScope($shop)['organization_id'],
                $this->tenantScope($shop)['brand_id'],
                (string) $shop->id,
                (string) ($ownership->ownershipRevision ?? 'unresolved'),
                $this->configurationHash($shop),
                $this->resolveAllEffective($shop, $candidates),
            ),
            $source,
        );
    }

    /** @param list<PaymentPolicyCandidate> $candidates
     * @return list<EffectivePaymentOption>
     */
    private function resolveAllEffective(Branch $shop, array $candidates): array
    {
        $grouped = [];
        foreach ($candidates as $candidate) {
            $grouped[$candidate->optionId][] = $candidate;
        }

        $resolved = [];
        foreach ($grouped as $optionId => $optionCandidates) {
            $request = $this->requestForOption($shop, $optionId, $optionCandidates[0]);
            $resolved[] = $this->resolver->resolve($request, $optionCandidates);
        }

        return $resolved;
    }

    private function configurationHash(Branch $shop, ?string $deviceId = null): string
    {
        $shopRows = ShopPaymentOption::query()
            ->where('branch_id', $shop->id)
            ->orderBy('option_id')
            ->get(['option_id', 'connection_id', 'preference', 'version'])
            ->map(fn ($row): array => [
                'option_id' => (string) $row->option_id,
                'connection_id' => $row->connection_id,
                'preference' => $row->preference instanceof PaymentPolicyPreferenceEnum
                    ? $row->preference->value
                    : (string) $row->preference,
                'version' => (int) $row->version,
            ])->all();

        $deviceRows = $deviceId === null
            ? []
            : DevicePaymentOption::query()
                ->where('branch_id', $shop->id)
                ->where('device_id', $deviceId)
                ->orderBy('shop_payment_option_id')
                ->get(['shop_payment_option_id', 'preference', 'version'])
                ->map(static fn ($row): array => [
                    'shop_payment_option_id' => (string) $row->shop_payment_option_id,
                    'preference' => (string) $row->preference,
                    'version' => (int) $row->version,
                ])->all();

        return hash('sha256', json_encode([
            'shop' => $shopRows,
            'device_id' => $deviceId,
            'device' => $deviceRows,
        ], JSON_THROW_ON_ERROR));
    }

    private function requestForOption(
        Branch $shop,
        string $optionId,
        PaymentPolicyCandidate $candidate,
        ?string $deviceId = null,
        PaymentChannelEnum $channel = PaymentChannelEnum::Pos,
    ): PaymentPolicyRequest {
        $shop->loadMissing('brand');

        return new PaymentPolicyRequest(
            $this->tenantScope($shop)['organization_id'],
            $this->tenantScope($shop)['brand_id'],
            (string) $shop->id,
            (string) $candidate->identityBrandId,
            (string) ($shop->console_branch_id ?: $shop->id),
            $optionId,
            $candidate->paymentBrand,
            $deviceId,
            $channel,
            'browser',
            'JPY',
            $candidate->environment,
            GatewayCapability::Create,
            new DateTimeImmutable('now'),
            (string) Str::uuid(),
        );
    }

    private function ownership(Branch $shop): BranchManagementProjection
    {
        $shop->loadMissing('brand');
        $tenant = $this->tenantScope($shop);
        $lookup = (new PaymentPolicyRequest(
            $tenant['organization_id'],
            $tenant['brand_id'],
            (string) $shop->id,
            (string) ($shop->brand?->console_brand_id ?: $tenant['brand_id']),
            (string) ($shop->console_branch_id ?: $shop->id),
            (string) Str::uuid(),
            null,
            null,
            PaymentChannelEnum::Pos,
            'browser',
            'JPY',
            PaymentGatewayEnvironmentEnum::Test,
            GatewayCapability::Create,
            new DateTimeImmutable('now'),
            'ownership-lookup',
        ))->ownershipLookup();

        return $this->ownershipSource->resolve($lookup);
    }

    /** @return list<array<string, mixed>> */
    private function safeConnections(Branch $shop, BranchManagementProjection $ownership): array
    {
        $ownerScope = $ownership->ownerScope();
        if ($ownerScope === null) {
            return [];
        }

        $tenant = $this->tenantScope($shop);
        $query = PaymentGatewayConnection::query()
            ->with('provider')
            ->where('organization_id', $tenant['organization_id'])
            ->where('brand_id', $tenant['brand_id'])
            ->where('owner_scope', $ownerScope->value)
            ->where('is_active', true);

        if ($ownerScope === PaymentConnectionOwnerScopeEnum::Franchise) {
            $query->where('owner_branch_id', $shop->id);
        }

        return $query->get()
            ->map(fn (PaymentGatewayConnection $connection): array => $this->presenter->connection($connection))
            ->values()
            ->all();
    }

    private function assertFranchiseMutable(Branch $shop): void
    {
        if ($this->ownership($shop)->managementModel !== BranchManagementModel::FranchiseOwned) {
            throw new PaymentGatewayMutationForbiddenException;
        }
    }

    private function assertConnectionInShopScope(Branch $shop, PaymentGatewayConnection $connection): void
    {
        $tenant = $this->tenantScope($shop);
        if ((string) $connection->organization_id !== $tenant['organization_id']
            || (string) $connection->brand_id !== $tenant['brand_id']
            || (string) $connection->owner_branch_id !== (string) $shop->id) {
            abort(404);
        }
    }

    /** @return array{organization_id: string, brand_id: string} */
    private function tenantScope(Branch $shop): array
    {
        $shop->loadMissing('brand');
        $organization = Organization::query()
            ->where('console_organization_id', $shop->console_organization_id)
            ->first();

        abort_if($organization === null || $shop->brand === null, 404, 'Shop tenant scope is invalid.');

        return [
            'organization_id' => (string) $organization->id,
            'brand_id' => (string) $shop->brand->id,
        ];
    }
}
