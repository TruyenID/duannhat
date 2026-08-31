<?php

namespace App\Services\Payment\Policy;

use App\Services\Payment\Policy\ValueObjects\CanonicalPaymentPolicySnapshot;
use App\Services\Payment\Policy\ValueObjects\EffectivePaymentOption;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicySnapshotInput;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicyTraceEntry;
use InvalidArgumentException;
use JsonException;

final class PaymentPolicySnapshotSerializer
{
    public const SCHEMA_VERSION = 'payment_policy_snapshot.v1';

    public function serialize(PaymentPolicySnapshotInput $input): CanonicalPaymentPolicySnapshot
    {
        $options = array_map(
            fn (EffectivePaymentOption $option): array => $this->optionPayload($option),
            $input->options,
        );
        usort(
            $options,
            static fn (array $left, array $right): int => $left['option_id'] <=> $right['option_id'],
        );

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'scope' => [
                'organization_id' => $input->organizationId,
                'brand_id' => $input->brandId,
                'branch_id' => $input->branchId,
            ],
            'ownership_revision' => $input->ownershipRevision,
            'configuration_hash' => $input->configurationHash,
            'options' => $options,
        ];
        $canonicalJson = $this->canonicalJson($payload);

        return new CanonicalPaymentPolicySnapshot(
            $this->canonicalize($payload),
            $canonicalJson,
            hash('sha256', $canonicalJson),
            count(array_filter(
                $options,
                static fn (array $option): bool => $option['effective'] === true,
            )),
        );
    }

    /** @param array<string, mixed> $snapshot */
    public function verify(array $snapshot, string $expectedHash): bool
    {
        if (preg_match('/^[0-9a-f]{64}$/', $expectedHash) !== 1) {
            return false;
        }

        try {
            return hash_equals($expectedHash, hash('sha256', $this->canonicalJson($snapshot)));
        } catch (InvalidArgumentException|JsonException) {
            return false;
        }
    }

    /** @param array<string, mixed> $value
     * @throws JsonException
     */
    public function canonicalJson(array $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** @return array<string, mixed> */
    private function optionPayload(EffectivePaymentOption $option): array
    {
        return [
            'option_id' => $option->optionId,
            'effective' => $option->effective,
            'reason' => $option->reason->value,
            'error_code' => $option->reason->publicErrorCode(),
            'connection_id' => $option->connectionId,
            'connection_option_id' => $option->connectionOptionId,
            'shop_option_id' => $option->shopOptionId,
            'owner_scope' => $option->ownerScope?->value,
            'operator_org_unit_id' => $option->operatorOrgUnitId,
            'ownership_revision' => $option->ownershipRevision,
            'trace' => array_map(
                static fn (PaymentPolicyTraceEntry $entry): array => $entry->jsonSerialize(),
                $option->trace,
            ),
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_float($value) || is_resource($value) || is_object($value)) {
            throw new InvalidArgumentException('Policy snapshots accept only null, boolean, integer, string, and array values.');
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Policy snapshot object keys must be strings.');
            }

            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
