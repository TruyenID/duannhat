<?php

namespace App\Services\Payment\Policy\ValueObjects;

use App\Services\Payment\Policy\Enums\PaymentPolicyPublicationSource;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PaymentPolicyPublication
{
    public function __construct(
        public PaymentPolicySnapshotInput $input,
        public CanonicalPaymentPolicySnapshot $snapshot,
        public PaymentPolicyPublicationSource $source,
        public DateTimeImmutable $publishedAt,
    ) {
        $scope = $snapshot->payload['scope'] ?? null;

        if (! is_array($scope)
            || ($scope['organization_id'] ?? null) !== $input->organizationId
            || ($scope['brand_id'] ?? null) !== $input->brandId
            || ($scope['branch_id'] ?? null) !== $input->branchId
            || ($snapshot->payload['ownership_revision'] ?? null) !== $input->ownershipRevision
            || ($snapshot->payload['configuration_hash'] ?? null) !== $input->configurationHash) {
            throw new InvalidArgumentException('Publication input and canonical snapshot scope do not match.');
        }
    }
}
