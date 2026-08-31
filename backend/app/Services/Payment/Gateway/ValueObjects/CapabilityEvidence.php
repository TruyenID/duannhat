<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;

/** Secret-free immutable evidence attached to one capability verification. */
final readonly class CapabilityEvidence implements JsonSerializable
{
    public string $contractOrConfigurationReference;

    public ?string $providerTestAccountReference;

    public string $testRunArtifactReference;

    public string $reviewerIdentityRevision;

    public function __construct(
        string $contractOrConfigurationReference,
        ?string $providerTestAccountReference,
        public DateTimeImmutable $certifiedAt,
        string $testRunArtifactReference,
        string $reviewerIdentityRevision,
        public DateTimeImmutable $reviewAt,
    ) {
        $this->contractOrConfigurationReference = self::reference($contractOrConfigurationReference, 'contractOrConfigurationReference', ['application-test:', 'config:', 'contract:']);
        $this->providerTestAccountReference = $providerTestAccountReference === null
            ? null
            : self::reference($providerTestAccountReference, 'providerTestAccountReference', ['account:']);
        $this->testRunArtifactReference = self::reference($testRunArtifactReference, 'testRunArtifactReference', ['artifact:', 'test-run:']);
        $this->reviewerIdentityRevision = self::reference($reviewerIdentityRevision, 'reviewerIdentityRevision', ['identity:']);

        if ($reviewAt <= $certifiedAt) {
            throw new InvalidArgumentException('Capability evidence reviewAt must be after certifiedAt.');
        }
    }

    /** @return array<string, string|null> */
    public function jsonSerialize(): array
    {
        return [
            'contract_or_configuration_reference' => $this->contractOrConfigurationReference,
            'provider_test_account_reference' => $this->providerTestAccountReference,
            'certified_at' => $this->certifiedAt->format(DATE_ATOM),
            'test_run_artifact_reference' => $this->testRunArtifactReference,
            'reviewer_identity_revision' => $this->reviewerIdentityRevision,
            'review_at' => $this->reviewAt->format(DATE_ATOM),
        ];
    }

    /** @param non-empty-list<string> $prefixes */
    private static function reference(string $value, string $name, array $prefixes): string
    {
        $value = trim($value);
        $hasAllowedPrefix = false;
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                $hasAllowedPrefix = true;
                break;
            }
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,254}$/', $value) !== 1
            || preg_match('/(?:sk|pk)_(?:live|test)_|whsec_|bearer|credential|password|secret/i', $value) === 1
            || ! $hasAllowedPrefix) {
            throw new InvalidArgumentException("{$name} must be a stable secret-free reference.");
        }

        return $value;
    }
}
