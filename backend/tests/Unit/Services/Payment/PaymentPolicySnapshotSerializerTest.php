<?php

namespace Tests\Unit\Services\Payment;

use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Services\Payment\Policy\Enums\PolicyDecision;
use App\Services\Payment\Policy\Enums\PolicyLayer;
use App\Services\Payment\Policy\Enums\PolicyReasonCode;
use App\Services\Payment\Policy\PaymentPolicySnapshotSerializer;
use App\Services\Payment\Policy\ValueObjects\EffectivePaymentOption;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicySnapshotInput;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicyTraceEntry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PaymentPolicySnapshotSerializerTest extends TestCase
{
    private const ORGANIZATION_ID = '00000000-0000-4000-8000-000000000501';

    private const BRAND_ID = '00000000-0000-4000-8000-000000000201';

    private const BRANCH_ID = '00000000-0000-4000-8000-000000000101';

    private const OWNERSHIP_REVISION = ' Rev:Ab /!? ';

    public function test_serialization_is_canonical_order_independent_hash_verifiable_and_secret_free(): void
    {
        $serializer = new PaymentPolicySnapshotSerializer;
        $first = $serializer->serialize($this->input([
            $this->deniedOption('00000000-0000-4000-8000-000000000602'),
            $this->effectiveOption('00000000-0000-4000-8000-000000000601', 'sk_live_must_never_persist'),
        ]));
        $reordered = $serializer->serialize($this->input([
            $this->effectiveOption('00000000-0000-4000-8000-000000000601', 'different-correlation'),
            $this->deniedOption('00000000-0000-4000-8000-000000000602'),
        ]));

        self::assertSame($first->canonicalJson, $reordered->canonicalJson);
        self::assertSame($first->hash, $reordered->hash);
        self::assertTrue($first->verifies());
        self::assertTrue($serializer->verify($first->payload, $first->hash));
        self::assertSame(1, $first->effectiveOptionCount);
        self::assertSame([
            '00000000-0000-4000-8000-000000000601',
            '00000000-0000-4000-8000-000000000602',
        ], array_column($first->payload['options'], 'option_id'));
        self::assertStringNotContainsString('sk_live_must_never_persist', $first->canonicalJson);
        self::assertStringNotContainsString('correlation', $first->canonicalJson);
        self::assertArrayNotHasKey('published_at', $first->payload);
        self::assertArrayNotHasKey('revision', $first->payload);
        self::assertArrayNotHasKey('source', $first->payload);
    }

    public function test_hash_covers_scope_ownership_configuration_and_full_safe_option_semantics(): void
    {
        $serializer = new PaymentPolicySnapshotSerializer;
        $snapshot = $serializer->serialize($this->input([$this->effectiveOption()]));
        $option = $snapshot->payload['options'][0];

        self::assertSame(self::ORGANIZATION_ID, $snapshot->payload['scope']['organization_id']);
        self::assertSame(self::BRAND_ID, $snapshot->payload['scope']['brand_id']);
        self::assertSame(self::BRANCH_ID, $snapshot->payload['scope']['branch_id']);
        self::assertSame(self::OWNERSHIP_REVISION, $snapshot->payload['ownership_revision']);
        self::assertSame(hash('sha256', 'configuration-a'), $snapshot->payload['configuration_hash']);
        self::assertSame(PolicyReasonCode::Effective->value, $option['reason']);
        self::assertNull($option['error_code']);
        self::assertSame(PaymentConnectionOwnerScopeEnum::Hq->value, $option['owner_scope']);
        self::assertSame([
            'decision' => PolicyDecision::Allowed->value,
            'layer' => PolicyLayer::Ownership->value,
            'reason' => PolicyReasonCode::OwnershipResolvedHq->value,
        ], $option['trace'][0]);

        $changedConfiguration = $serializer->serialize($this->input(
            [$this->effectiveOption()],
            hash('sha256', 'device-policy-changed'),
        ));
        $changedDecision = $serializer->serialize($this->input([$this->deniedOption()]));

        self::assertNotSame($snapshot->hash, $changedConfiguration->hash);
        self::assertNotSame($snapshot->hash, $changedDecision->hash);
    }

    public function test_verification_fails_for_tampering_or_invalid_hashes(): void
    {
        $serializer = new PaymentPolicySnapshotSerializer;
        $snapshot = $serializer->serialize($this->input([$this->effectiveOption()]));
        $tampered = $snapshot->payload;
        $tampered['options'][0]['effective'] = false;

        self::assertFalse($serializer->verify($tampered, $snapshot->hash));
        self::assertFalse($serializer->verify($snapshot->payload, 'not-a-sha256'));
    }

    public function test_snapshot_boundary_rejects_duplicate_options_secret_shaped_identifiers_and_floats(): void
    {
        $option = $this->effectiveOption();

        try {
            $this->input([$option, $option]);
            self::fail('Duplicate options must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('duplicate option', strtolower($exception->getMessage()));
        }

        $secretShapedIdentifier = new EffectivePaymentOption(
            '00000000-0000-4000-8000-000000000603',
            'policy:serializer:invalid',
            true,
            PolicyReasonCode::Effective,
            'sk_live_not_an_identifier',
            '00000000-0000-4000-8000-000000000801',
            '00000000-0000-4000-8000-000000000901',
            PaymentConnectionOwnerScopeEnum::Hq,
            '00000000-0000-4000-8000-000000000401',
            self::OWNERSHIP_REVISION,
            [],
        );
        try {
            $this->input([$secretShapedIdentifier]);
            self::fail('Secret-shaped identifiers must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('valid UUID', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        (new PaymentPolicySnapshotSerializer)->canonicalJson(['amount' => 1.5]);
    }

    /** @param list<EffectivePaymentOption> $options */
    private function input(
        array $options,
        string $configurationHash = '',
    ): PaymentPolicySnapshotInput {
        return new PaymentPolicySnapshotInput(
            self::ORGANIZATION_ID,
            self::BRAND_ID,
            self::BRANCH_ID,
            self::OWNERSHIP_REVISION,
            $configurationHash === '' ? hash('sha256', 'configuration-a') : $configurationHash,
            $options,
        );
    }

    private function effectiveOption(
        string $optionId = '00000000-0000-4000-8000-000000000601',
        string $correlationId = 'policy:serializer:test',
    ): EffectivePaymentOption {
        return new EffectivePaymentOption(
            $optionId,
            $correlationId,
            true,
            PolicyReasonCode::Effective,
            '00000000-0000-4000-8000-000000000701',
            '00000000-0000-4000-8000-000000000801',
            '00000000-0000-4000-8000-000000000901',
            PaymentConnectionOwnerScopeEnum::Hq,
            '00000000-0000-4000-8000-000000000401',
            self::OWNERSHIP_REVISION,
            [new PaymentPolicyTraceEntry(
                PolicyLayer::Ownership,
                PolicyDecision::Allowed,
                PolicyReasonCode::OwnershipResolvedHq,
            )],
        );
    }

    private function deniedOption(
        string $optionId = '00000000-0000-4000-8000-000000000601',
    ): EffectivePaymentOption {
        return new EffectivePaymentOption(
            $optionId,
            'policy:serializer:denied',
            false,
            PolicyReasonCode::ConnectionRequired,
            null,
            null,
            null,
            null,
            null,
            null,
            [new PaymentPolicyTraceEntry(
                PolicyLayer::Connection,
                PolicyDecision::Denied,
                PolicyReasonCode::ConnectionRequired,
            )],
        );
    }
}
