<?php

namespace App\Services\Payment\Policy\Persistence;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentPolicyRevision;
use App\Services\Payment\Policy\Contracts\PaymentPolicyRevisionPersistence;
use App\Services\Payment\Policy\Enums\PaymentPolicyPublicationSource;
use App\Services\Payment\Policy\PaymentPolicySnapshotSerializer;
use App\Services\Payment\Policy\ValueObjects\PaymentPolicyPublication;
use App\Services\Payment\Policy\ValueObjects\PublishedPaymentPolicyRevision;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use RuntimeException;

/** Eloquent is confined to this append-only persistence adapter. */
final readonly class EloquentPaymentPolicyRevisionPersistence implements PaymentPolicyRevisionPersistence
{
    public function __construct(
        private ConnectionInterface $database,
        private PaymentPolicySnapshotSerializer $serializer,
    ) {}

    public function publishAtomically(PaymentPolicyPublication $publication): PublishedPaymentPolicyRevision
    {
        return $this->database->transaction(function () use ($publication): PublishedPaymentPolicyRevision {
            $input = $publication->input;
            $organization = Organization::query()->find($input->organizationId);
            $brand = Brand::query()->find($input->brandId);
            $branch = Branch::query()->whereKey($input->branchId)->lockForUpdate()->first();

            if ($organization === null
                || $brand === null
                || $branch === null
                || ! is_string($organization->console_organization_id)
                || $organization->console_organization_id === ''
                || (string) $brand->console_organization_id !== (string) $organization->console_organization_id
                || (string) $branch->console_organization_id !== (string) $organization->console_organization_id
                || ! is_string($brand->console_brand_id)
                || $brand->console_brand_id === ''
                || (string) $branch->console_brand_id !== (string) $brand->console_brand_id) {
                throw new LogicException('Payment policy publication scope is invalid.');
            }

            $latest = PaymentPolicyRevision::query()
                ->where('branch_id', $input->branchId)
                ->orderByDesc('revision')
                ->lockForUpdate()
                ->first();

            if ($latest !== null
                && ((string) $latest->organization_id !== $input->organizationId
                    || (string) $latest->brand_id !== $input->brandId)) {
                throw new LogicException('Existing payment policy revision has an invalid tenant scope.');
            }

            if ($latest !== null && ! $this->storedRevisionIsValid($latest)) {
                throw new RuntimeException('Stored payment policy snapshot failed integrity verification.');
            }

            if ($latest !== null && hash_equals((string) $latest->snapshot_hash, $publication->snapshot->hash)) {
                return $this->result($latest, false);
            }

            if ($latest !== null && (int) $latest->revision >= PHP_INT_MAX) {
                throw new RuntimeException('Payment policy revision range is exhausted.');
            }

            $revision = $latest === null ? 1 : (int) $latest->revision + 1;

            $record = PaymentPolicyRevision::query()->create([
                'organization_id' => $input->organizationId,
                'brand_id' => $input->brandId,
                'branch_id' => $input->branchId,
                'revision' => $revision,
                'ownership_revision' => $input->ownershipRevision,
                'snapshot_hash' => $publication->snapshot->hash,
                'snapshot' => $publication->snapshot->payload,
                'effective_option_count' => $publication->snapshot->effectiveOptionCount,
                'source' => $publication->source->value,
                'published_at' => $publication->publishedAt,
            ]);

            return $this->result($record, true);
        }, 5);
    }

    private function result(PaymentPolicyRevision $record, bool $created): PublishedPaymentPolicyRevision
    {
        return new PublishedPaymentPolicyRevision(
            (string) $record->id,
            (string) $record->organization_id,
            (string) $record->brand_id,
            (string) $record->branch_id,
            (int) $record->revision,
            (string) $record->ownership_revision,
            (string) $record->snapshot_hash,
            (array) $record->snapshot,
            (int) $record->effective_option_count,
            PaymentPolicyPublicationSource::tryFrom((string) $record->source)
                ?? throw new RuntimeException('Stored payment policy publication source is invalid.'),
            DateTimeImmutable::createFromInterface($record->published_at),
            $created,
        );
    }

    private function storedRevisionIsValid(PaymentPolicyRevision $record): bool
    {
        $snapshot = (array) $record->snapshot;
        $scope = $snapshot['scope'] ?? null;
        $options = $snapshot['options'] ?? null;

        return $this->serializer->verify($snapshot, (string) $record->snapshot_hash)
            && (int) $record->revision >= 1
            && is_array($scope)
            && ($scope['organization_id'] ?? null) === (string) $record->organization_id
            && ($scope['brand_id'] ?? null) === (string) $record->brand_id
            && ($scope['branch_id'] ?? null) === (string) $record->branch_id
            && ($snapshot['ownership_revision'] ?? null) === (string) $record->ownership_revision
            && is_array($options)
            && count(array_filter(
                $options,
                static fn (mixed $option): bool => is_array($option) && ($option['effective'] ?? null) === true,
            )) === (int) $record->effective_option_count
            && PaymentPolicyPublicationSource::tryFrom((string) $record->source) !== null;
    }
}
