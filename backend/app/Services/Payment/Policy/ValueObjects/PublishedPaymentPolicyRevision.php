<?php

namespace App\Services\Payment\Policy\ValueObjects;

use App\Services\Payment\Policy\Enums\PaymentPolicyPublicationSource;
use DateTimeImmutable;
use JsonSerializable;

final readonly class PublishedPaymentPolicyRevision implements JsonSerializable
{
    /** @param array<string, mixed> $snapshot */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $brandId,
        public string $branchId,
        public int $revision,
        public string $ownershipRevision,
        public string $snapshotHash,
        public array $snapshot,
        public int $effectiveOptionCount,
        public PaymentPolicyPublicationSource $source,
        public DateTimeImmutable $publishedAt,
        public bool $created,
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organizationId,
            'brand_id' => $this->brandId,
            'branch_id' => $this->branchId,
            'revision' => $this->revision,
            'ownership_revision' => $this->ownershipRevision,
            'snapshot_hash' => $this->snapshotHash,
            'snapshot' => $this->snapshot,
            'effective_option_count' => $this->effectiveOptionCount,
            'source' => $this->source->value,
            'published_at' => $this->publishedAt->format(DATE_ATOM),
            'created' => $this->created,
        ];
    }
}
