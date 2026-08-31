<?php

namespace Tests\Unit\Services\Payment;

use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentConnectionHealthEnum;
use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentOptionRailEnum;
use App\Omnify\Enums\PaymentPolicyPreferenceEnum;
use App\Services\Payment\Gateway\Enums\CapabilityFact;
use App\Services\Payment\Gateway\Enums\CapabilityOperator;
use App\Services\Payment\Gateway\Enums\CapabilitySupport;
use App\Services\Payment\Gateway\Enums\GatewayCapability;
use App\Services\Payment\Gateway\ValueObjects\CapabilityCondition;
use App\Services\Payment\Gateway\ValueObjects\CapabilityPredicate;
use App\Services\Payment\Gateway\ValueObjects\CapabilityRule;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\OperationCapability;
use App\Services\Payment\Policy\Contracts\BranchManagementProjectionSource;
use App\Services\Payment\Policy\Enums\BranchManagementModel;
use App\Services\Payment\Policy\Enums\ConnectionCapabilityVerification;
use App\Services\Payment\Policy\Enums\DevicePolicyPreference;
use App\Services\Payment\Policy\Enums\PolicyLayer;
use App\Services\Payment\Policy\Enums\PolicyReasonCode;
use App\Services\Payment\Policy\Enums\UpstreamPolicyState;
use App\Services\Payment\Policy\PaymentPolicyResolver;
use App\Services\Payment\Policy\ValueObjects\BranchManagementLookup;
use App\Services\Payment\Policy\ValueObjects\BranchManagementProjection;
use App\Services\Payment\Policy\ValueObjects\ConnectionApprovedCapability;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicyCandidate;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicyRequest;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\Payment\PaymentGatewayFixtures;

final class PaymentPolicyResolverTest extends TestCase
{
    private const ORGANIZATION_ID = '00000000-0000-4000-8000-000000000501';

    private const BRAND_ID = '00000000-0000-4000-8000-000000000201';

    private const BRANCH_ID = '00000000-0000-4000-8000-000000000101';

    private const IDENTITY_BRAND_ID = '00000000-0000-4000-8000-000000000202';

    private const BRANCH_ORG_UNIT_ID = '00000000-0000-4000-8000-000000000301';

    private const BRAND_OWNER_ORG_UNIT_ID = '00000000-0000-4000-8000-000000000401';

    private const FRANCHISE_OPERATOR_ORG_UNIT_ID = '00000000-0000-4000-8000-000000000402';

    private const OPTION_ID = '00000000-0000-4000-8000-000000000601';

    private const CONNECTION_ID = '00000000-0000-4000-8000-000000000701';

    private const CONNECTION_OPTION_ID = '00000000-0000-4000-8000-000000000801';

    private const SHOP_OPTION_ID = '00000000-0000-4000-8000-000000000901';

    private const DEVICE_ID = '00000000-0000-4000-8000-000000001001';

    private const OWNERSHIP_REVISION = 'ownership-revision-00010';

    public function test_hq_managed_option_resolves_every_layer_and_preserves_opaque_revision(): void
    {
        $result = $this->resolver($this->hqOwnership())->resolve(
            $this->request(),
            [$this->candidate()],
        );

        self::assertTrue($result->effective);
        self::assertSame(self::CONNECTION_ID, $result->connectionId);
        self::assertSame(PaymentConnectionOwnerScopeEnum::Hq, $result->ownerScope);
        self::assertSame(self::OWNERSHIP_REVISION, $result->ownershipRevision);
        self::assertSame(self::OWNERSHIP_REVISION, $result->jsonSerialize()['ownership_revision']);
        self::assertSame('policy:test:correlation', $result->correlationId);
        self::assertSame([
            'ownership',
            'provider',
            'connection',
            'capability',
            'owner_policy',
            'shop',
            'device',
            'runtime',
        ], array_column($result->jsonSerialize()['trace'], 'layer'));
        self::assertSame(PolicyReasonCode::Effective, $result->reason);
        self::assertNull($result->reason->publicErrorCode());
    }

    public function test_franchise_selects_only_its_exact_owner_and_never_falls_back_to_hq(): void
    {
        $franchise = $this->candidate([
            'connectionId' => '00000000-0000-4000-8000-000000000702',
            'connectionOptionId' => '00000000-0000-4000-8000-000000000802',
            'ownerScope' => PaymentConnectionOwnerScopeEnum::Franchise,
            'ownerBranchId' => self::BRANCH_ID,
            'operatorOrgUnitId' => self::FRANCHISE_OPERATOR_ORG_UNIT_ID,
            'selectedForShop' => true,
        ]);
        $hq = $this->candidate();

        $result = $this->resolver($this->franchiseOwnership())->resolve(
            $this->request(),
            [$hq, $franchise],
        );

        self::assertTrue($result->effective);
        self::assertSame($franchise->connectionId, $result->connectionId);
        self::assertSame(PaymentConnectionOwnerScopeEnum::Franchise, $result->ownerScope);

        $withoutFranchise = $this->resolver($this->franchiseOwnership())->resolve(
            $this->request(),
            [$hq],
        );

        self::assertFalse($withoutFranchise->effective);
        self::assertSame(PolicyReasonCode::ConnectionRequired, $withoutFranchise->reason);
        self::assertNull($withoutFranchise->connectionId);

        $invalidSelection = $this->resolver($this->franchiseOwnership())->resolve(
            $this->request(),
            [$this->candidate(['selectedForShop' => true]), $this->candidate([
                'connectionId' => $franchise->connectionId,
                'connectionOptionId' => $franchise->connectionOptionId,
                'ownerScope' => PaymentConnectionOwnerScopeEnum::Franchise,
                'ownerBranchId' => self::BRANCH_ID,
                'operatorOrgUnitId' => self::FRANCHISE_OPERATOR_ORG_UNIT_ID,
            ])],
        );
        self::assertFalse($invalidSelection->effective);
        self::assertSame(PolicyReasonCode::ConnectionRequired, $invalidSelection->reason);
        self::assertNull($invalidSelection->connectionId);
    }

    public function test_unresolved_mismatched_or_failed_ownership_source_stops_before_candidate_selection(): void
    {
        $lookup = $this->request()->ownershipLookup();
        $unresolved = BranchManagementProjection::unresolved($lookup, 'multiple_active_franchise_grants');
        $mismatched = new BranchManagementProjection(
            self::ORGANIZATION_ID,
            '00000000-0000-4000-8000-000000000399',
            self::IDENTITY_BRAND_ID,
            BranchManagementModel::HqManaged,
            self::BRAND_OWNER_ORG_UNIT_ID,
            self::BRAND_OWNER_ORG_UNIT_ID,
            self::OWNERSHIP_REVISION,
            'resolved',
        );

        foreach ([
            [$this->resolver($unresolved), PolicyReasonCode::OwnershipUnresolved],
            [$this->resolver($mismatched), PolicyReasonCode::OwnershipScopeMismatch],
            [new PaymentPolicyResolver(new ThrowingOwnershipSource), PolicyReasonCode::OwnershipSourceUnavailable],
        ] as [$resolver, $expected]) {
            $result = $resolver->resolve($this->request(), [$this->candidate()]);

            self::assertFalse($result->effective);
            self::assertSame($expected, $result->reason);
            self::assertSame('PAYMENT_OWNERSHIP_UNRESOLVED', $result->reason->publicErrorCode());
            self::assertNull($result->connectionId);
            self::assertCount(1, $result->trace);
            self::assertSame(PolicyLayer::Ownership, $result->trace[0]->layer);
        }
    }

    public function test_ownership_projection_from_another_organization_is_rejected_before_candidate_selection(): void
    {
        $wrongTenant = new BranchManagementProjection(
            '00000000-0000-4000-8000-000000000599',
            self::BRANCH_ORG_UNIT_ID,
            self::IDENTITY_BRAND_ID,
            BranchManagementModel::HqManaged,
            self::BRAND_OWNER_ORG_UNIT_ID,
            self::BRAND_OWNER_ORG_UNIT_ID,
            self::OWNERSHIP_REVISION,
            'resolved',
        );

        $result = $this->resolver($wrongTenant)->resolve($this->request(), [$this->candidate()]);

        self::assertFalse($result->effective);
        self::assertSame(PolicyReasonCode::OwnershipScopeMismatch, $result->reason);
        self::assertNull($result->connectionId);
        self::assertCount(1, $result->trace);
        self::assertSame(PolicyLayer::Ownership, $result->trace[0]->layer);
    }

    public function test_cross_scope_or_stale_ownership_candidates_fail_closed_without_exposing_identity(): void
    {
        foreach ([
            ['organizationId' => '00000000-0000-4000-8000-000000000599'],
            ['brandId' => '00000000-0000-4000-8000-000000000299'],
            ['branchId' => '00000000-0000-4000-8000-000000000199'],
            ['identityBrandId' => '00000000-0000-4000-8000-000000000298'],
            ['ownershipRevision' => 'ownership-revision-stale'],
            ['operatorOrgUnitId' => '00000000-0000-4000-8000-000000000499'],
        ] as $override) {
            $result = $this->resolver($this->hqOwnership())->resolve(
                $this->request(),
                [$this->candidate($override)],
            );

            self::assertFalse($result->effective);
            self::assertSame(PolicyReasonCode::ConnectionRequired, $result->reason);
            self::assertNull($result->connectionId);
            self::assertNull($result->operatorOrgUnitId);
        }
    }

    public function test_ambiguous_connections_fail_closed_but_one_explicit_shop_selection_is_deterministic(): void
    {
        $first = $this->candidate();
        $second = $this->candidate([
            'connectionId' => '00000000-0000-4000-8000-000000000702',
            'connectionOptionId' => '00000000-0000-4000-8000-000000000802',
        ]);

        $ambiguous = $this->resolver($this->hqOwnership())->resolve($this->request(), [$second, $first]);
        self::assertFalse($ambiguous->effective);
        self::assertSame(PolicyReasonCode::ConnectionAmbiguous, $ambiguous->reason);
        self::assertNull($ambiguous->connectionId);

        $selected = $this->candidate([
            'connectionId' => $second->connectionId,
            'connectionOptionId' => $second->connectionOptionId,
            'selectedForShop' => true,
        ]);
        $resolver = $this->resolver($this->hqOwnership());
        $forward = $resolver->resolve($this->request(), [$first, $selected]);
        $reverse = $resolver->resolve($this->request(), [$selected, $first]);

        self::assertTrue($forward->effective);
        self::assertSame($selected->connectionId, $forward->connectionId);
        self::assertSame($forward->jsonSerialize(), $reverse->jsonSerialize());
    }

    public function test_policy_lattice_fails_at_the_exact_non_widening_layer(): void
    {
        $cases = [
            'provider inactive' => [[], ['providerActive' => false], PolicyLayer::Provider, PolicyReasonCode::ProviderInactive],
            'connection inactive' => [[], ['connectionActive' => false], PolicyLayer::Connection, PolicyReasonCode::ConnectionInactive],
            'connection degraded' => [[], ['connectionHealth' => PaymentConnectionHealthEnum::Degraded], PolicyLayer::Connection, PolicyReasonCode::ConnectionDegraded],
            'catalog inactive' => [[], ['optionActive' => false], PolicyLayer::Capability, PolicyReasonCode::CapabilityInactive],
            'provider capability mismatch' => [[], ['connectionProvider' => PaymentGatewayProviderCodeEnum::Paypay], PolicyLayer::Capability, PolicyReasonCode::CapabilityUnverified],
            'merchant capability unverified' => [[], ['connectionVerification' => ConnectionCapabilityVerification::ContractRequired], PolicyLayer::Capability, PolicyReasonCode::CapabilityUnverified],
            'merchant evidence missing' => [[], ['capabilityEvidenceReference' => null], PolicyLayer::Capability, PolicyReasonCode::CapabilityUnverified],
            'currency unsupported' => [['currency' => 'USD'], [], PolicyLayer::Capability, PolicyReasonCode::CurrencyUnsupported],
            'channel unsupported' => [['channel' => PaymentChannelEnum::Kiosk], [], PolicyLayer::Capability, PolicyReasonCode::ChannelUnsupported],
            'device class unsupported' => [['deviceClass' => 'reader'], [], PolicyLayer::Capability, PolicyReasonCode::DeviceClassUnsupported],
            'operation not approved' => [[], ['approvedOperations' => $this->operations([GatewayCapability::Capture])], PolicyLayer::Capability, PolicyReasonCode::OperationUnsupported],
            'owner deny beats shop enable' => [[], ['ownerPolicy' => UpstreamPolicyState::Denied, 'shopPreference' => PaymentPolicyPreferenceEnum::Enabled], PolicyLayer::OwnerPolicy, PolicyReasonCode::OwnerPolicyBlocked],
            'owner unresolved beats shop enable' => [[], ['ownerPolicy' => UpstreamPolicyState::Unresolved, 'shopPreference' => PaymentPolicyPreferenceEnum::Enabled], PolicyLayer::OwnerPolicy, PolicyReasonCode::OwnerPolicyUnresolved],
            'shop disabled' => [[], ['shopPreference' => PaymentPolicyPreferenceEnum::Disabled], PolicyLayer::Shop, PolicyReasonCode::ShopDisabled],
            'device disabled' => [['deviceId' => self::DEVICE_ID], ['deviceId' => self::DEVICE_ID, 'devicePreference' => DevicePolicyPreference::Disabled], PolicyLayer::Device, PolicyReasonCode::DeviceDisabled],
            'runtime unavailable' => [[], ['runtimeAvailable' => false], PolicyLayer::Runtime, PolicyReasonCode::RuntimeUnavailable],
        ];

        foreach ($cases as $name => [$requestOverride, $candidateOverride, $layer, $reason]) {
            $result = $this->resolver($this->hqOwnership())->resolve(
                $this->request($requestOverride),
                [$this->candidate($candidateOverride)],
            );

            self::assertFalse($result->effective, $name);
            self::assertSame($reason, $result->reason, $name);
            self::assertSame($layer, $result->trace[array_key_last($result->trace)]->layer, $name);
        }
    }

    public function test_environment_mismatch_is_distinct_and_does_not_select_another_environment(): void
    {
        $result = $this->resolver($this->hqOwnership())->resolve(
            $this->request(['environment' => PaymentGatewayEnvironmentEnum::Live]),
            [$this->candidate()],
        );

        self::assertFalse($result->effective);
        self::assertSame(PolicyReasonCode::EnvironmentMismatch, $result->reason);
        self::assertSame('PAYMENT_ENVIRONMENT_MISMATCH', $result->reason->publicErrorCode());
        self::assertNull($result->connectionId);
    }

    public function test_connection_approval_must_match_every_catalog_identity_and_account_dimension(): void
    {
        $cases = [
            'capability id' => ['capabilityId' => 'contract.fake.card.other'],
            'catalog revision' => ['catalogRevision' => 4],
            'capability hash' => ['capabilityHash' => str_repeat('a', 64)],
            'integration product' => ['integrationProduct' => 'other_product'],
            'api version' => ['apiVersion' => '2026-07-01'],
            'rail' => ['rail' => PaymentOptionRailEnum::Wallet],
            'method type' => ['methodType' => 'wallet'],
            'approved brands' => ['approvedBrands' => []],
            'approved device classes' => ['approvedDeviceClasses' => ['reader']],
            'required merchant identity' => ['configuredMerchantIdentities' => ['other_identity']],
        ];

        foreach ($cases as $name => $connectionCapabilityOverride) {
            $result = $this->resolver($this->hqOwnership())->resolve(
                $this->request(),
                [$this->candidate(['connectionCapabilityOverride' => $connectionCapabilityOverride])],
            );

            self::assertFalse($result->effective, $name);
            self::assertSame(PolicyReasonCode::CapabilityUnverified, $result->reason, $name);
            self::assertSame(PolicyLayer::Capability, $result->trace[array_key_last($result->trace)]->layer, $name);
        }
    }

    public function test_requested_payment_brand_must_be_real_account_evidence_and_match_fixed_catalogs(): void
    {
        $fixedVisa = $this->catalogWithBrands(['visa']);
        $cases = [
            'fixed catalog empty approval' => [
                ['catalogCapability' => $fixedVisa, 'connectionCapabilityOverride' => ['approvedBrands' => []]],
                [],
                false,
            ],
            'account configured bogus approval' => [
                ['connectionCapabilityOverride' => ['approvedBrands' => ['bogus_network']]],
                [],
                false,
            ],
            'account configured sentinel approval' => [
                ['connectionCapabilityOverride' => ['approvedBrands' => ['account_configured']]],
                [],
                false,
            ],
            'requested visa absent from approval' => [
                ['connectionCapabilityOverride' => ['approvedBrands' => ['mastercard']]],
                ['paymentBrand' => 'VISA'],
                false,
            ],
            'normalized requested visa approved' => [
                [
                    'catalogCapability' => $fixedVisa,
                    'paymentBrand' => ' visa ',
                    'connectionCapabilityOverride' => ['approvedBrands' => ['VISA']],
                ],
                ['paymentBrand' => 'VISA'],
                true,
            ],
        ];

        foreach ($cases as $name => [$candidateOverride, $requestOverride, $effective]) {
            $result = $this->resolver($this->hqOwnership())->resolve(
                $this->request($requestOverride),
                [$this->candidate($candidateOverride)],
            );

            self::assertSame($effective, $result->effective, $name);
            self::assertSame(
                $effective ? PolicyReasonCode::Effective : PolicyReasonCode::CapabilityUnverified,
                $result->reason,
                $name,
            );
        }
    }

    public function test_brandless_catalog_requires_explicit_null_request_and_empty_account_approval(): void
    {
        $brandless = $this->catalogWithBrands([]);
        $valid = $this->resolver($this->hqOwnership())->resolve(
            $this->request(['paymentBrand' => null]),
            [$this->candidate([
                'catalogCapability' => $brandless,
                'paymentBrand' => null,
                'connectionCapabilityOverride' => ['approvedBrands' => []],
            ])],
        );
        self::assertTrue($valid->effective);

        $requestedBrand = $this->resolver($this->hqOwnership())->resolve(
            $this->request(['paymentBrand' => 'visa']),
            [$this->candidate([
                'catalogCapability' => $brandless,
                'paymentBrand' => 'visa',
                'connectionCapabilityOverride' => ['approvedBrands' => []],
            ])],
        );
        self::assertFalse($requestedBrand->effective);
        self::assertSame(PolicyReasonCode::CapabilityUnverified, $requestedBrand->reason);

        $fixedWithoutRequest = $this->resolver($this->hqOwnership())->resolve(
            $this->request(['paymentBrand' => null]),
            [$this->candidate([
                'catalogCapability' => $this->catalogWithBrands(['visa']),
                'paymentBrand' => null,
                'connectionCapabilityOverride' => ['approvedBrands' => []],
            ])],
        );
        self::assertFalse($fixedWithoutRequest->effective);
        self::assertSame(PolicyReasonCode::CapabilityUnverified, $fixedWithoutRequest->reason);
    }

    public function test_ownership_revision_is_an_opaque_equality_token_without_numeric_coercion(): void
    {
        $ownership = new BranchManagementProjection(
            self::ORGANIZATION_ID,
            self::BRANCH_ORG_UNIT_ID,
            self::IDENTITY_BRAND_ID,
            BranchManagementModel::HqManaged,
            self::BRAND_OWNER_ORG_UNIT_ID,
            self::BRAND_OWNER_ORG_UNIT_ID,
            '00010',
            'resolved',
        );

        $coerced = $this->resolver($ownership)->resolve(
            $this->request(),
            [$this->candidate(['ownershipRevision' => '10'])],
        );
        self::assertFalse($coerced->effective);
        self::assertSame(PolicyReasonCode::ConnectionRequired, $coerced->reason);

        $exact = $this->resolver($ownership)->resolve(
            $this->request(),
            [$this->candidate(['ownershipRevision' => '00010'])],
        );
        self::assertTrue($exact->effective);
        self::assertSame('00010', $exact->ownershipRevision);
    }

    public function test_ownership_revision_preserves_whitespace_punctuation_and_case_as_exact_bytes(): void
    {
        $token = ' Rev:Ab /!? ';
        $ownership = new BranchManagementProjection(
            self::ORGANIZATION_ID,
            self::BRANCH_ORG_UNIT_ID,
            self::IDENTITY_BRAND_ID,
            BranchManagementModel::HqManaged,
            self::BRAND_OWNER_ORG_UNIT_ID,
            self::BRAND_OWNER_ORG_UNIT_ID,
            $token,
            'resolved',
        );

        $exact = $this->resolver($ownership)->resolve(
            $this->request(),
            [$this->candidate(['ownershipRevision' => $token])],
        );
        self::assertTrue($exact->effective);
        self::assertSame($token, $exact->ownershipRevision);

        foreach (['Rev:Ab /!?', ' Rev:Ab /? ', ' Rev:ab /!? '] as $variant) {
            $result = $this->resolver($ownership)->resolve(
                $this->request(),
                [$this->candidate(['ownershipRevision' => $variant])],
            );
            self::assertFalse($result->effective, $variant);
            self::assertSame(PolicyReasonCode::ConnectionRequired, $result->reason, $variant);
        }
    }

    public function test_ownership_revision_rejects_control_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->candidate(['ownershipRevision' => "revision\n2"]);
    }

    public function test_account_conditional_operation_requires_an_exact_machine_fact(): void
    {
        $conditionalCreate = new OperationCapability(
            GatewayCapability::Create,
            new CapabilityRule(CapabilitySupport::Conditional, new CapabilityCondition([
                new CapabilityPredicate(
                    CapabilityFact::MerchantCapabilityEnabled,
                    CapabilityOperator::IsTrue,
                ),
            ])),
        );
        $candidate = $this->candidate(['approvedOperations' => [$conditionalCreate]]);

        $unknown = $this->resolver($this->hqOwnership())->resolve($this->request(), [$candidate]);
        self::assertFalse($unknown->effective);
        self::assertSame(PolicyReasonCode::OperationUnsupported, $unknown->reason);

        $approved = $this->resolver($this->hqOwnership())->resolve($this->request([
            'capabilityFacts' => [CapabilityFact::MerchantCapabilityEnabled->value => true],
        ]), [$candidate]);
        self::assertTrue($approved->effective);
        self::assertSame(PolicyReasonCode::Effective, $approved->reason);
    }

    public function test_policy_boundary_has_no_eloquent_provider_sdk_or_local_branch_flag_dependency(): void
    {
        $directory = dirname(__DIR__, 4).'/app/Services/Payment/Policy';
        $files = [];

        // The purity guard protects the provider-neutral policy CORE (the
        // resolver, its request/candidate/effective-option value objects, trace
        // and reason codes) — those must never touch Eloquent/Stripe/DB. The
        // Eloquent-bound ADAPTER + APPLICATION layers are excluded by design:
        //   - Persistence/  : Eloquent candidate/revision loaders + capability mapper
        //   - Admin/        : admin-facing enricher/presenter/evaluation services
        //   - PaymentPolicySubmissionValidator : DB-backed revision validation
        //   - PaymentPolicySubmission          : input carrier built from a Branch
        // (audit 2026-07-23, issue #1028). Follow-up: relocate the last two out of
        // the core namespace / make PaymentPolicySubmission a pure VO.
        $excludedDirs = [DIRECTORY_SEPARATOR.'Persistence'.DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR.'Admin'.DIRECTORY_SEPARATOR];
        $excludedFiles = ['PaymentPolicySubmissionValidator.php', 'PaymentPolicySubmission.php'];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            foreach ($excludedDirs as $dir) {
                if (str_contains($path, $dir)) {
                    continue 2;
                }
            }
            if (in_array($file->getFilename(), $excludedFiles, true)) {
                continue;
            }
            $files[] = $path;
        }

        self::assertGreaterThanOrEqual(10, count($files));

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            self::assertIsString($contents);
            self::assertStringNotContainsString('App\\Models\\', $contents, $file);
            self::assertStringNotContainsString('Stripe\\', $contents, $file);
            self::assertStringNotContainsString('DB::', $contents, $file);
            self::assertStringNotContainsString('is_headquarters', $contents, $file);
            self::assertStringNotContainsString('is_standalone', $contents, $file);
        }
    }

    /** @param array<string, mixed> $override */
    private function request(array $override = []): PaymentPolicyRequest
    {
        return new PaymentPolicyRequest(
            $override['organizationId'] ?? self::ORGANIZATION_ID,
            $override['brandId'] ?? self::BRAND_ID,
            $override['branchId'] ?? self::BRANCH_ID,
            $override['identityBrandId'] ?? self::IDENTITY_BRAND_ID,
            $override['branchOrgUnitId'] ?? self::BRANCH_ORG_UNIT_ID,
            $override['optionId'] ?? self::OPTION_ID,
            array_key_exists('paymentBrand', $override) ? $override['paymentBrand'] : 'visa',
            $override['deviceId'] ?? null,
            $override['channel'] ?? PaymentChannelEnum::Pos,
            $override['deviceClass'] ?? 'browser',
            $override['currency'] ?? 'JPY',
            $override['environment'] ?? PaymentGatewayEnvironmentEnum::Test,
            $override['operation'] ?? GatewayCapability::Create,
            $override['operationStartedAt'] ?? new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
            'policy:test:correlation',
            $override['capabilityFacts'] ?? [],
        );
    }

    /** @param array<string, mixed> $override */
    private function candidate(array $override = []): PaymentPolicyCandidate
    {
        $catalogCapability = $override['catalogCapability'] ?? PaymentGatewayFixtures::fullCapability();

        return new PaymentPolicyCandidate(
            $override['optionId'] ?? self::OPTION_ID,
            array_key_exists('paymentBrand', $override) ? $override['paymentBrand'] : 'visa',
            $override['connectionId'] ?? self::CONNECTION_ID,
            $override['connectionOptionId'] ?? self::CONNECTION_OPTION_ID,
            $override['shopOptionId'] ?? self::SHOP_OPTION_ID,
            $override['organizationId'] ?? self::ORGANIZATION_ID,
            $override['brandId'] ?? self::BRAND_ID,
            $override['branchId'] ?? self::BRANCH_ID,
            $override['identityBrandId'] ?? self::IDENTITY_BRAND_ID,
            $override['ownerScope'] ?? PaymentConnectionOwnerScopeEnum::Hq,
            $override['ownerBranchId'] ?? null,
            $override['brandOwnerOrgUnitId'] ?? self::BRAND_OWNER_ORG_UNIT_ID,
            $override['operatorOrgUnitId'] ?? self::BRAND_OWNER_ORG_UNIT_ID,
            $override['ownershipRevision'] ?? self::OWNERSHIP_REVISION,
            $override['connectionProvider'] ?? PaymentGatewayProviderCodeEnum::Stripe,
            $override['environment'] ?? PaymentGatewayEnvironmentEnum::Test,
            $override['providerActive'] ?? true,
            $override['optionActive'] ?? true,
            $override['connectionActive'] ?? true,
            $override['connectionHealth'] ?? PaymentConnectionHealthEnum::Ready,
            $catalogCapability,
            $override['connectionCapability'] ?? $this->connectionCapability(
                $catalogCapability,
                array_merge($override['connectionCapabilityOverride'] ?? [], [
                    'verification' => $override['connectionVerification'] ?? ConnectionCapabilityVerification::Verified,
                    'enabled' => $override['connectionOptionEnabled'] ?? true,
                    'approvedCurrencies' => $override['approvedCurrencies'] ?? ['JPY'],
                    'approvedChannels' => $override['approvedChannels'] ?? [PaymentChannelEnum::CustomerWeb, PaymentChannelEnum::Pos],
                    'approvedOperations' => $override['approvedOperations'] ?? $this->operations(GatewayCapability::cases()),
                    'effectiveFrom' => $override['connectionCapabilityEffectiveFrom'] ?? new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                    'effectiveTo' => $override['connectionCapabilityEffectiveTo'] ?? new DateTimeImmutable('2027-01-01T00:00:00+00:00'),
                    'evidenceReference' => array_key_exists('capabilityEvidenceReference', $override)
                        ? $override['capabilityEvidenceReference']
                        : 'contract:payment-policy-test',
                ]),
            ),
            $override['ownerPolicy'] ?? UpstreamPolicyState::Allowed,
            $override['shopPreference'] ?? PaymentPolicyPreferenceEnum::Inherit,
            $override['deviceId'] ?? null,
            $override['devicePreference'] ?? DevicePolicyPreference::Inherit,
            $override['runtimeAvailable'] ?? true,
            $override['selectedForShop'] ?? false,
        );
    }

    private function hqOwnership(): BranchManagementProjection
    {
        return new BranchManagementProjection(
            self::ORGANIZATION_ID,
            self::BRANCH_ORG_UNIT_ID,
            self::IDENTITY_BRAND_ID,
            BranchManagementModel::HqManaged,
            self::BRAND_OWNER_ORG_UNIT_ID,
            self::BRAND_OWNER_ORG_UNIT_ID,
            self::OWNERSHIP_REVISION,
            'resolved',
        );
    }

    private function franchiseOwnership(): BranchManagementProjection
    {
        return new BranchManagementProjection(
            self::ORGANIZATION_ID,
            self::BRANCH_ORG_UNIT_ID,
            self::IDENTITY_BRAND_ID,
            BranchManagementModel::FranchiseOwned,
            self::BRAND_OWNER_ORG_UNIT_ID,
            self::FRANCHISE_OPERATOR_ORG_UNIT_ID,
            self::OWNERSHIP_REVISION,
            'resolved',
        );
    }

    /** @param list<GatewayCapability> $operations
     * @return list<OperationCapability>
     */
    private function operations(array $operations): array
    {
        $supported = new CapabilityRule(CapabilitySupport::Supported);

        return array_map(
            static fn (GatewayCapability $operation): OperationCapability => new OperationCapability($operation, $supported),
            $operations,
        );
    }

    /** @param array<string, mixed> $override */
    private function connectionCapability(CapabilitySet $catalog, array $override = []): ConnectionApprovedCapability
    {
        return new ConnectionApprovedCapability(
            $override['capabilityId'] ?? $catalog->id,
            $override['catalogRevision'] ?? $catalog->revision,
            $override['capabilityHash'] ?? ConnectionApprovedCapability::fingerprint($catalog),
            $override['integrationProduct'] ?? $catalog->integrationProduct,
            $override['apiVersion'] ?? $catalog->apiVersion,
            $override['rail'] ?? $catalog->rail,
            $override['methodType'] ?? $catalog->methodType,
            $override['approvedBrands'] ?? ['visa'],
            $override['approvedDeviceClasses'] ?? ['browser'],
            $override['configuredMerchantIdentities'] ?? ['connected_account'],
            $override['verification'] ?? ConnectionCapabilityVerification::Verified,
            $override['enabled'] ?? true,
            $override['approvedCurrencies'] ?? ['JPY'],
            $override['approvedChannels'] ?? [PaymentChannelEnum::CustomerWeb, PaymentChannelEnum::Pos],
            $override['approvedOperations'] ?? $this->operations(GatewayCapability::cases()),
            $override['effectiveFrom'] ?? new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            $override['effectiveTo'] ?? new DateTimeImmutable('2027-01-01T00:00:00+00:00'),
            array_key_exists('evidenceReference', $override)
                ? $override['evidenceReference']
                : 'contract:payment-policy-test',
        );
    }

    /** @param list<string> $brands */
    private function catalogWithBrands(array $brands): CapabilitySet
    {
        $catalog = PaymentGatewayFixtures::fullCapability();

        return new CapabilitySet(
            $catalog->id,
            $catalog->revision,
            $catalog->provider,
            $catalog->integrationProduct,
            $catalog->apiVersion,
            $catalog->rail,
            $catalog->methodType,
            $brands,
            $catalog->channels,
            $catalog->deviceClasses,
            $catalog->currencies,
            $catalog->environment,
            $catalog->workflows,
            $catalog->operations,
            $catalog->limits,
            $catalog->recovery,
            $catalog->merchantIdentityRequirements,
            $catalog->effectiveFrom,
            $catalog->effectiveTo,
            $catalog->verification,
        );
    }

    private function resolver(BranchManagementProjection $projection): PaymentPolicyResolver
    {
        return new PaymentPolicyResolver(new FixedOwnershipSource($projection));
    }
}

final readonly class FixedOwnershipSource implements BranchManagementProjectionSource
{
    public function __construct(private BranchManagementProjection $projection) {}

    public function resolve(BranchManagementLookup $lookup): BranchManagementProjection
    {
        return $this->projection;
    }
}

final class ThrowingOwnershipSource implements BranchManagementProjectionSource
{
    public function resolve(BranchManagementLookup $lookup): BranchManagementProjection
    {
        throw new RuntimeException('Upstream response must not escape the payment boundary.');
    }
}
