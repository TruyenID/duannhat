<?php

namespace App\Services\Payment\Configuration\Internal;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\DevicePaymentOption;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayConnectionOption;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentGatewayProvider;
use App\Models\ShopPaymentOption;
use App\Omnify\Enums\PaymentConnectionHealthEnum;
use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentPolicyPreferenceEnum;
use App\Services\Device\Contracts\DeviceDirectory;
use App\Services\Payment\Configuration\Exceptions\PaymentConfigurationException;
use App\Services\Payment\Gateway\Enums\CapabilityVerificationState;
use App\Services\Payment\Gateway\PaymentGatewayRegistry;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\EphemeralSecret;
use App\Services\Payment\Policy\Enums\UpstreamPolicyState;
use App\Services\Payment\ProviderEvent\GatewayConnectionDataFactory;
use App\Services\Payment\Secret\Exceptions\GatewaySecretResolutionFailed;
use App\Services\Payment\Secret\GatewayConnectionSecretResolver;
use App\Services\Payment\Secret\ValueObjects\GatewaySecretAccessContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Sole mutation boundary for HQ payment gateway configuration rows. */
final class EloquentPaymentGatewayConfigurationPersistence
{
    public function __construct(
        private readonly PaymentGatewayRegistry $registry,
        private readonly GatewayConnectionSecretResolver $secretResolver,
        private readonly DeviceDirectory $devices,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PaymentGatewayConnection>
     */
    public function listHqConnections(string $organizationId, string $brandId, array $filters = []): LengthAwarePaginator
    {
        $query = PaymentGatewayConnection::query()
            ->with(['provider', 'paymentGatewayConnectionOptions.option'])
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->where('owner_scope', PaymentConnectionOwnerScopeEnum::Hq);

        $environment = $filters['environment'] ?? null;
        if (is_string($environment) && $environment !== '') {
            $query->where('environment', $environment);
        }

        $health = $filters['health'] ?? null;
        if (is_string($health) && $health !== '') {
            $query->where('health', $health);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            // Escape the LIKE metacharacters AND declare the escape character.
            // Without the explicit `ESCAPE`, MySQL assumes backslash but SQLite
            // assumes nothing — so `acct_two` searched as the literal string
            // `acct\_two` and matched zero rows on SQLite while working fine
            // against the dev MySQL. Merchant account ids are full of
            // underscores, so that is the common case, not an edge one.
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($search)).'%';
            $like = static fn (string $column): string => "{$column} LIKE ? ESCAPE '\\'";

            $query->where(function ($scoped) use ($term, $like): void {
                $scoped->whereRaw($like('merchant_account_id'), [$term])
                    ->orWhereRaw($like('merchant_display_name'), [$term])
                    ->orWhereRaw($like('merchant_store_id'), [$term])
                    ->orWhereHas('provider', function ($provider) use ($term, $like): void {
                        $provider->whereRaw($like('code'), [$term])
                            ->orWhereHas('translations', fn ($translation) => $translation->whereRaw($like('name'), [$term]));
                    });
            });
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = max(1, min($perPage, 100));

        return $query->orderBy('created_at')->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{connection: PaymentGatewayConnection, created: bool}
     */
    public function createHqConnection(string $organizationId, string $brandId, array $data): array
    {
        $provider = PaymentGatewayProvider::query()
            ->where('code', $data['provider_code'])
            ->where('is_active', true)
            ->firstOrFail();

        $existing = PaymentGatewayConnection::query()
            ->with(['provider', 'paymentGatewayConnectionOptions.option'])
            ->where('provider_id', $provider->id)
            ->where('environment', $data['environment'])
            ->where('merchant_account_id', $data['merchant_account_id'])
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->where('owner_scope', PaymentConnectionOwnerScopeEnum::Hq)
            ->first();

        if ($existing instanceof PaymentGatewayConnection) {
            return ['connection' => $existing, 'created' => false];
        }

        $connection = PaymentGatewayConnection::query()->create([
            'provider_id' => $provider->id,
            'organization_id' => $organizationId,
            'brand_id' => $brandId,
            'owner_branch_id' => null,
            'identity_brand_id' => $data['identity_brand_id'],
            'owner_scope' => PaymentConnectionOwnerScopeEnum::Hq,
            'brand_owner_org_unit_id' => $data['brand_owner_org_unit_id'],
            'operator_org_unit_id' => $data['operator_org_unit_id'],
            'ownership_revision' => $data['ownership_revision'],
            'environment' => $data['environment'],
            'merchant_account_id' => $data['merchant_account_id'],
            'merchant_store_id' => $data['merchant_store_id'] ?? null,
            'merchant_terminal_id' => $data['merchant_terminal_id'] ?? null,
            'merchant_display_name' => $data['merchant_display_name'] ?? null,
            'charge_model' => $data['charge_model'],
            'health' => PaymentConnectionHealthEnum::PendingVerification,
            'health_reason_code' => 'onboarding_initiated',
            'is_active' => false,
        ]);

        return [
            'connection' => $connection->load(['provider', 'paymentGatewayConnectionOptions.option']),
            'created' => true,
        ];
    }

    public function findHqConnection(string $organizationId, string $brandId, string $connectionId): PaymentGatewayConnection
    {
        return PaymentGatewayConnection::query()
            ->with(['provider', 'paymentGatewayConnectionOptions.option'])
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->where('owner_scope', PaymentConnectionOwnerScopeEnum::Hq)
            ->findOrFail($connectionId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateHqConnection(PaymentGatewayConnection $connection, array $data): PaymentGatewayConnection
    {
        $connection->fill([
            'merchant_store_id' => $data['merchant_store_id'] ?? $connection->merchant_store_id,
            'merchant_terminal_id' => $data['merchant_terminal_id'] ?? $connection->merchant_terminal_id,
            'merchant_display_name' => $data['merchant_display_name'] ?? $connection->merchant_display_name,
            'charge_model' => $data['charge_model'] ?? $connection->charge_model,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $connection->is_active,
        ]);
        $connection->save();

        return $connection->fresh(['provider', 'paymentGatewayConnectionOptions.option']);
    }

    public function validateConnection(
        PaymentGatewayConnection $connection,
        GatewaySecretAccessContext $secretContext,
        string $correlationId,
    ): PaymentGatewayConnection {
        if ($connection->health === PaymentConnectionHealthEnum::Revoked) {
            throw new PaymentConfigurationException(
                'This connection has been revoked and cannot be validated.',
                'PAYMENT_CONNECTION_RESTRICTED',
                422,
                false,
                'complete_provider_action',
                ['health' => $connection->health->value],
                $correlationId,
            );
        }

        try {
            $this->secretResolver->api($secretContext);
        } catch (GatewaySecretResolutionFailed) {
            throw new PaymentConfigurationException(
                'The connection secret could not be resolved.',
                'PAYMENT_SECRET_RESOLUTION_FAILED',
                503,
                false,
                'rotate_or_restore_secret',
                [],
                $correlationId,
            );
        }

        $connectionData = GatewayConnectionDataFactory::fromModel($connection);
        $gateway = $this->registry->forConnection($connectionData, $correlationId);
        $capabilities = $gateway->capabilities($connectionData);

        return DB::transaction(function () use ($connection, $capabilities): PaymentGatewayConnection {
            $health = $this->mapHealthFromCapabilities($capabilities);
            $connection->health = $health;
            $connection->health_reason_code = $health === PaymentConnectionHealthEnum::Ready
                ? 'validation_succeeded'
                : $capabilities->verification->state->value;
            $connection->last_validated_at = now();
            $connection->is_active = $health === PaymentConnectionHealthEnum::Ready;
            $connection->save();

            $this->syncConnectionOptions($connection, $capabilities);

            return $connection->fresh(['provider', 'paymentGatewayConnectionOptions.option']);
        });
    }

    /**
     * @return array{fingerprint: string, secret_version: string|null}
     */
    public function rotateApiSecret(
        PaymentGatewayConnection $connection,
        GatewaySecretAccessContext $secretContext,
        EphemeralSecret $secret,
        string $correlationId,
    ): array {
        try {
            $rotation = $this->secretResolver->rotateApi($secretContext, $secret);
        } catch (GatewaySecretResolutionFailed) {
            throw new PaymentConfigurationException(
                'The connection secret could not be rotated.',
                'PAYMENT_SECRET_RESOLUTION_FAILED',
                503,
                false,
                'rotate_or_restore_secret',
                [],
                $correlationId,
            );
        }

        $connection->refresh();

        return [
            'fingerprint' => $rotation->fingerprint,
            'secret_version' => (string) $connection->secret_version,
        ];
    }

    /**
     * @return array{
     *     shop_count: int,
     *     device_count: int,
     *     shop_payment_option_count: int,
     *     shops: list<array{id: string, slug: string, name: string|null}>,
     *     devices: list<array{id: string, name: string|null}>
     * }
     */
    public function disconnectImpact(PaymentGatewayConnection $connection): array
    {
        $shopOptions = ShopPaymentOption::query()
            ->with('branch')
            ->where('connection_id', $connection->id)
            ->get();

        $deviceOptions = DevicePaymentOption::query()
            ->with(['shopPaymentOption'])
            ->whereHas('shopPaymentOption', fn ($query) => $query->where('connection_id', $connection->id))
            ->get();

        $shops = $shopOptions
            ->pluck('branch')
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn (Branch $branch): array => [
                'id' => (string) $branch->id,
                'slug' => (string) $branch->slug,
                'name' => $branch->name,
            ])
            ->all();

        // #1666 — `device_id` là cột của Payments (bảng `device_payment_options`),
        // còn TÊN thiết bị thuộc PlatformIntegration. Nên chỗ này gom id ở bên
        // mình rồi hỏi tên qua cổng, thay vì đi theo quan hệ `device` để với sang
        // model của module khác. Thiết bị đã xoá mềm rơi ra ở cổng, đúng như
        // `->filter()` cũ làm với quan hệ trả `null`.
        $devices = $this->devices->identitiesByIds(
            $deviceOptions
                ->pluck('device_id')
                ->filter()
                ->map(static fn ($id): string => (string) $id)
                ->unique()
                ->values()
                ->all()
        );

        return [
            'shop_count' => count($shops),
            'device_count' => count($devices),
            'shop_payment_option_count' => $shopOptions->count(),
            'shops' => $shops,
            'devices' => $devices,
        ];
    }

    public function disconnectConnection(PaymentGatewayConnection $connection): void
    {
        DB::transaction(function () use ($connection): void {
            ShopPaymentOption::query()
                ->where('connection_id', $connection->id)
                ->update([
                    'connection_id' => null,
                    'preference' => PaymentPolicyPreferenceEnum::Inherit,
                ]);

            $connection->is_active = false;
            $connection->health = PaymentConnectionHealthEnum::Revoked;
            $connection->health_reason_code = 'disconnected_by_operator';
            $connection->save();
            $connection->delete();
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listHqOptionPolicies(string $organizationId, string $brandId, Branch $policyBranch): Collection
    {
        $options = PaymentGatewayOption::query()
            ->with(['provider', 'translations'])
            ->whereHas('provider', fn ($query) => $query->where('is_active', true))
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $policies = ShopPaymentOption::query()
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->where('branch_id', $policyBranch->id)
            ->get()
            ->keyBy('option_id');

        return $options->map(function (PaymentGatewayOption $option) use ($policies): array {
            /** @var ShopPaymentOption|null $policy */
            $policy = $policies->get($option->id);

            $preference = $policy?->preference ?? PaymentPolicyPreferenceEnum::Inherit;
            $ownerPolicy = $this->mapOwnerPolicy($preference);

            return [
                'option' => $option,
                'shop_payment_option_id' => $policy?->id,
                'preference' => $preference,
                'owner_policy' => $ownerPolicy,
                'effective_preview' => $this->effectivePreviewLabel($preference, $ownerPolicy),
                'version' => $policy?->version,
            ];
        });
    }

    public function upsertHqOptionPolicy(
        string $organizationId,
        string $brandId,
        Branch $policyBranch,
        PaymentGatewayOption $option,
        PaymentPolicyPreferenceEnum $preference,
        ?string $changeReason,
        ?int $expectedVersion,
    ): ShopPaymentOption {
        if (! in_array($preference, [
            PaymentPolicyPreferenceEnum::Enabled,
            PaymentPolicyPreferenceEnum::Disabled,
            PaymentPolicyPreferenceEnum::Blocked,
        ], true)) {
            throw new PaymentConfigurationException(
                'HQ policy must be enabled, disabled, or blocked.',
                'PAYMENT_POLICY_CANNOT_WIDEN',
                409,
                false,
                'refresh_payment_options',
            );
        }

        return DB::transaction(function () use (
            $organizationId,
            $brandId,
            $policyBranch,
            $option,
            $preference,
            $changeReason,
            $expectedVersion,
        ): ShopPaymentOption {
            $existing = ShopPaymentOption::query()
                ->where('branch_id', $policyBranch->id)
                ->where('option_id', $option->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $expectedVersion !== null && (int) $existing->version !== $expectedVersion) {
                throw new PaymentConfigurationException(
                    'The payment option policy changed since you loaded it.',
                    'PAYMENT_POLICY_STALE',
                    409,
                    false,
                    'refresh_payment_options',
                    ['current_version' => (int) $existing->version],
                );
            }

            if ($existing === null) {
                return ShopPaymentOption::query()->create([
                    'organization_id' => $organizationId,
                    'brand_id' => $brandId,
                    'branch_id' => $policyBranch->id,
                    'option_id' => $option->id,
                    'connection_id' => null,
                    'preference' => $preference,
                    'change_reason' => $changeReason,
                    'version' => 1,
                ]);
            }

            $existing->preference = $preference;
            $existing->change_reason = $changeReason;
            $existing->version = (int) $existing->version + 1;
            $existing->save();

            return $existing->fresh(['option', 'connection']);
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listCoverage(string $organizationId, string $brandId): Collection
    {
        $brand = Brand::query()->findOrFail($brandId);

        $branches = Branch::query()
            ->where('console_organization_id', $brand->console_organization_id)
            ->where('console_brand_id', $brand->console_brand_id)
            ->where('is_active', true)
            ->orderBy('slug')
            ->get();

        $hqConnection = PaymentGatewayConnection::query()
            ->with('provider')
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->where('owner_scope', PaymentConnectionOwnerScopeEnum::Hq)
            ->where('is_active', true)
            ->where('health', PaymentConnectionHealthEnum::Ready)
            ->orderBy('created_at')
            ->first();

        // HQ policy rows live on the headquarters branch. A brand without one is
        // a fault (see resolvePolicyBranch) — but coverage is a READ screen, so
        // an unresolvable policy branch degrades to "no brand policy" instead of
        // taking the whole page down with a 409.
        try {
            $policyBranchId = $this->resolvePolicyBranch($organizationId, $brandId)->id;
        } catch (PaymentConfigurationException) {
            $policyBranchId = null;
        }

        $hqSuppressed = $policyBranchId === null
            ? []
            : ShopPaymentOption::query()
                ->where('branch_id', $policyBranchId)
                ->whereIn('preference', [
                    PaymentPolicyPreferenceEnum::Blocked->value,
                    PaymentPolicyPreferenceEnum::Disabled->value,
                ])
                ->pluck('option_id')
                ->all();

        return $branches->map(function (Branch $branch) use ($hqConnection, $hqSuppressed): array {
            $franchiseConnection = PaymentGatewayConnection::query()
                ->with('provider')
                ->where('owner_branch_id', $branch->id)
                ->where('owner_scope', PaymentConnectionOwnerScopeEnum::Franchise)
                ->where('is_active', true)
                ->where('health', PaymentConnectionHealthEnum::Ready)
                ->orderBy('created_at')
                ->first();

            $servingConnection = $franchiseConnection ?? $hqConnection;
            $ready = $servingConnection !== null;
            $counts = $this->coverageOptionCounts($branch, $servingConnection, $hqSuppressed);

            return [
                'branch' => $branch,
                'management_model' => $franchiseConnection !== null ? 'franchise_owned' : 'hq_managed',
                'connection_ready' => $ready,
                'setup_required' => ! $ready,
                'reason_code' => $ready ? 'connection_ready' : 'connection_required',
                // Added for the admin coverage table, which declared these
                // columns and had nothing to render them from (#F5).
                'connection' => $servingConnection,
                'options_effective' => $counts['effective'],
                'options_total' => $counts['total'],
            ];
        });
    }

    /**
     * Option counts for one row of the coverage table.
     *
     * Deliberately a CONFIGURATION count, not a policy evaluation: "of the
     * options this shop's connection exposes, how many are not switched off —
     * at HQ or at the shop". It reads the same three tables the policy resolver
     * reads, so it can never report an option the shop has no connection for,
     * which is the failure the coverage screen exists to surface.
     *
     * @param  list<string>  $hqSuppressed  option ids blocked/disabled by brand policy
     * @return array{effective: int, total: int}
     */
    private function coverageOptionCounts(
        Branch $branch,
        ?PaymentGatewayConnection $connection,
        array $hqSuppressed,
    ): array {
        if ($connection === null) {
            return ['effective' => 0, 'total' => 0];
        }

        $optionIds = PaymentGatewayConnectionOption::query()
            ->where('connection_id', $connection->id)
            ->whereHas('option', fn ($query) => $query->where('is_active', true))
            ->pluck('option_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->all();

        if ($optionIds === []) {
            return ['effective' => 0, 'total' => 0];
        }

        $shopSuppressed = ShopPaymentOption::query()
            ->where('branch_id', $branch->id)
            ->whereIn('option_id', $optionIds)
            ->whereIn('preference', [
                PaymentPolicyPreferenceEnum::Blocked->value,
                PaymentPolicyPreferenceEnum::Disabled->value,
            ])
            ->pluck('option_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $suppressed = array_unique(array_merge($hqSuppressed, $shopSuppressed));

        return [
            'effective' => count(array_diff($optionIds, $suppressed)),
            'total' => count($optionIds),
        ];
    }

    /**
     * The branch row that stores BRAND-WIDE payment policy.
     *
     * Brand policy has no table of its own — it is persisted as
     * `shop_payment_options` rows owned by the brand's headquarters branch.
     * That only holds while a headquarters branch actually exists.
     *
     * There used to be a fallback here: "no headquarters? take the first active
     * branch ordered by slug". It fired silently and the consequence was not
     * silent at all — the brand's policy store became a REAL SHOP. On this
     * repo's own data the headquarters branch (`hq`) was soft-deleted, so the
     * fallback elected `hongo`, and from then on HQ and that one shop shared a
     * single row: HQ set "cash = enabled", the shop set "cash = disabled", and
     * the HQ screen read back `disabled` with the shop's change_reason. Every
     * other shop inherited nothing. No error, no warning, at any layer (#F3).
     *
     * So the fallback is gone. A brand with no headquarters branch is a
     * configuration fault, and it now says so instead of quietly writing brand
     * policy into a shop that sells food.
     *
     * @throws PaymentConfigurationException when the brand has no active headquarters branch
     */
    public function resolvePolicyBranch(string $organizationId, string $brandId): Branch
    {
        $brand = Brand::query()->findOrFail($brandId);

        $hqBranch = Branch::query()
            ->where('console_organization_id', $brand->console_organization_id)
            ->where('console_brand_id', $brand->console_brand_id)
            ->where('is_headquarters', true)
            ->where('is_active', true)
            ->first();

        if ($hqBranch !== null) {
            return $hqBranch;
        }

        throw new PaymentConfigurationException(
            'This brand has no active headquarters branch, so brand-wide payment policy cannot be stored.',
            'PAYMENT_POLICY_BRANCH_UNRESOLVED',
            409,
            false,
            'restore_headquarters_branch',
            ['brand_id' => $brandId],
        );
    }

    public function secretContext(
        PaymentGatewayConnection $connection,
        string $actorId,
        string $correlationId,
    ): GatewaySecretAccessContext {
        $connection->loadMissing('provider');

        return new GatewaySecretAccessContext(
            (string) $connection->organization_id,
            (string) $connection->id,
            $this->providerCode($connection),
            $connection->environment instanceof PaymentGatewayEnvironmentEnum
                ? $connection->environment
                : PaymentGatewayEnvironmentEnum::from((string) $connection->environment),
            $actorId,
            $correlationId,
        );
    }

    private function providerCode(PaymentGatewayConnection $connection): PaymentGatewayProviderCodeEnum
    {
        $code = $connection->provider->code;

        return $code instanceof PaymentGatewayProviderCodeEnum
            ? $code
            : PaymentGatewayProviderCodeEnum::from((string) $code);
    }

    private function syncConnectionOptions(PaymentGatewayConnection $connection, CapabilitySet $capabilities): void
    {
        $options = PaymentGatewayOption::query()
            ->where('provider_id', $connection->provider_id)
            ->where('code', $capabilities->id)
            ->get();

        foreach ($options as $option) {
            PaymentGatewayConnectionOption::query()->updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'option_id' => $option->id,
                ],
                [
                    'verification_state' => $capabilities->verification->state === CapabilityVerificationState::Verified
                        ? 'verified'
                        : 'contract_required',
                    'approved_currencies' => array_map(
                        static fn ($currency): array => ['code' => $currency->code, 'minor_unit' => $currency->minorUnit],
                        $capabilities->currencies,
                    ),
                    'approved_channels' => array_map(
                        static fn ($channel) => $channel->value,
                        $capabilities->channels,
                    ),
                    'approved_operations' => array_map(
                        static fn ($operation) => $operation->operation->value,
                        $capabilities->operations,
                    ),
                    'approved_limits' => null,
                    'merchant_configuration' => ['capability_contract' => $capabilities->id],
                    'evidence_ref' => $capabilities->verification->evidence[0]->contractOrConfigurationReference ?? 'validation:hq-admin',
                    'is_enabled' => $capabilities->verification->state === CapabilityVerificationState::Verified,
                    'effective_from' => $capabilities->effectiveFrom,
                    'effective_to' => $capabilities->effectiveTo,
                ],
            );
        }
    }

    private function mapHealthFromCapabilities(CapabilitySet $capabilities): PaymentConnectionHealthEnum
    {
        return match ($capabilities->verification->state) {
            CapabilityVerificationState::Verified => PaymentConnectionHealthEnum::Ready,
            CapabilityVerificationState::ContractRequired,
            CapabilityVerificationState::CertificationRequired => PaymentConnectionHealthEnum::PendingVerification,
            default => PaymentConnectionHealthEnum::Restricted,
        };
    }

    private function mapOwnerPolicy(PaymentPolicyPreferenceEnum $preference): UpstreamPolicyState
    {
        return match ($preference) {
            PaymentPolicyPreferenceEnum::Blocked => UpstreamPolicyState::Denied,
            PaymentPolicyPreferenceEnum::Inherit,
            PaymentPolicyPreferenceEnum::Enabled,
            PaymentPolicyPreferenceEnum::Disabled => UpstreamPolicyState::Allowed,
        };
    }

    private function effectivePreviewLabel(
        PaymentPolicyPreferenceEnum $preference,
        UpstreamPolicyState $ownerPolicy,
    ): string {
        return match (true) {
            $ownerPolicy === UpstreamPolicyState::Denied => 'blocked_upstream',
            $preference === PaymentPolicyPreferenceEnum::Enabled => 'default_on',
            $preference === PaymentPolicyPreferenceEnum::Disabled => 'default_off',
            default => 'inherit_provider_default',
        };
    }
}
