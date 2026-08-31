<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;

/**
 * Applies one identity event to Tempo's mirror (#3199, ADR 0002).
 *
 * ## What it deliberately does NOT do
 *
 * **`deleted` events are recorded, not enacted.** Deleting a branch or brand
 * here would cascade into rows that reference them — orders point at branches —
 * and a downstream mirror is the wrong place to decide that a shop's history
 * should become unreachable. The event is kept in the inbox and counted in the
 * run report; `platform:reconcile-directory` (#3143) already surfaces the same
 * fact as `local present / remote absent`, which is the shape a human can act
 * on.
 *
 * **Branch `is_active` is not mirrored**, even though the feed now carries it
 * explicitly. #3161 recorded the required order: (1) the relay lands — done,
 * (2) mirror the field FROM the feed, (3) drop Platform's active-only filter.
 * This is step 2, and it is the step that needs a person: `is_active = false`
 * makes a branch unresolvable in `ResolveBranchFromSlug`, i.e. the shop
 * disappears from its own URL. Letting another system switch that automatically
 * is a product decision, not plumbing, so it is not smuggled in here.
 */
final class IdentityEventApplier
{
    /**
     * @param  array<string, mixed>  $payload
     * @return 'applied'|'skipped_unknown_resource'|'skipped_destructive'|'skipped_missing_locally'
     */
    public function apply(string $resourceType, string $action, string $resourceId, array $payload): string
    {
        if ($action === 'deleted') {
            return 'skipped_destructive';
        }

        return match ($resourceType) {
            'organization' => $this->applyOrganization($resourceId, $payload),
            'brand' => $this->applyBrand($resourceId, $payload),
            'branch' => $this->applyBranch($resourceId, $payload),
            // A resource type this app does not mirror (users, service access) is
            // not an error — the feed is shared, and a consumer takes what
            // concerns it. Counted separately so "we ignored it" never reads as
            // "we applied it".
            default => 'skipped_unknown_resource',
        };
    }

    /** @param array<string, mixed> $payload */
    private function applyOrganization(string $id, array $payload): string
    {
        $organization = Organization::query()->where('console_organization_id', $id)->first();

        if ($organization === null) {
            // Tempo mirrors only organizations it has been provisioned for. An
            // event about an unknown one is not drift and not an error.
            return 'skipped_missing_locally';
        }

        $organization->forceFill(array_filter([
            'name' => $this->str($payload, 'name'),
            'slug' => $this->str($payload, 'slug'),
            // Same adopt-if-present rule the login path uses: a producer that
            // omits `country` must not blank an already-mirrored value.
            'operating_country' => $this->country($payload),
        ], static fn (mixed $v): bool => $v !== null))->save();

        return 'applied';
    }

    /** @param array<string, mixed> $payload */
    private function applyBrand(string $id, array $payload): string
    {
        $brand = Brand::query()->where('console_brand_id', $id)->first();

        if ($brand === null) {
            return 'skipped_missing_locally';
        }

        $brand->forceFill(array_filter([
            'slug' => $this->str($payload, 'slug'),
            'name' => $this->str($payload, 'name'),
            'description' => $this->str($payload, 'description'),
            'logo_url' => $this->str($payload, 'logo_url'),
            'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : null,
        ], static fn (mixed $v): bool => $v !== null))->save();

        return 'applied';
    }

    /** @param array<string, mixed> $payload */
    private function applyBranch(string $id, array $payload): string
    {
        $branch = Branch::query()->where('console_branch_id', $id)->first();

        if ($branch === null) {
            return 'skipped_missing_locally';
        }

        // `is_active` is absent from this list ON PURPOSE — see the class docblock.
        $branch->forceFill(array_filter([
            'console_brand_id' => $this->str($payload, 'brand_id'),
            'code' => $this->str($payload, 'code'),
            'slug' => $this->str($payload, 'slug'),
            'name' => $this->str($payload, 'name'),
            'is_headquarters' => array_key_exists('is_headquarters', $payload) ? (bool) $payload['is_headquarters'] : null,
            'timezone' => $this->str($payload, 'timezone'),
            'currency' => $this->str($payload, 'currency'),
            'locale' => $this->str($payload, 'locale'),
        ], static fn (mixed $v): bool => $v !== null))->save();

        return 'applied';
    }

    /** @param array<string, mixed> $payload */
    private function str(array $payload, string $key): ?string
    {
        return filled($payload[$key] ?? null) ? (string) $payload[$key] : null;
    }

    /** @param array<string, mixed> $payload */
    private function country(array $payload): ?string
    {
        $country = strtoupper(trim((string) ($payload['country'] ?? '')));

        return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
    }
}
