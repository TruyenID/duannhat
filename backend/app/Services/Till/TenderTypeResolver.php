<?php

namespace App\Services\Till;

use App\Models\TillTenderType;
use Illuminate\Support\Collection;

/**
 * #1156 — per-branch tender activation.
 *
 * The org-wide seed (`TillTenderTypeSeeder`, branch_id NULL) is the
 * organization's tender VOCABULARY; a branch-scoped row with the SAME
 * `tender_key` is that branch's OVERRIDE of the vocabulary entry. This
 * resolver computes the EFFECTIVE tender list for one branch:
 *
 *   - org row, no override            → passes through (when active).
 *   - org row + override active=true  → the override row wins entirely
 *                                       (a branch may re-activate a tender
 *                                       the org keeps globally off).
 *   - org row + override active=false → the tender is HIDDEN for the branch.
 *   - branch-only row (custom tender) → included when active.
 *
 * Ordering is deterministic: effective `sort_order` ascending, then
 * `tender_key` ascending — so two calls (or Cloud vs a future workstation
 * port) always render the 精算 manifest in the same sequence.
 */
class TenderTypeResolver
{
    /**
     * Effective (visible) tender types for a branch.
     *
     * @return Collection<int, TillTenderType>
     */
    public function effectiveForBranch(string $organizationId, string $branchId): Collection
    {
        $rows = TillTenderType::query()
            ->with('translations')
            ->where('organization_id', $organizationId)
            ->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
            })
            ->get();

        /** @var Collection<string, TillTenderType> $overrides */
        $overrides = $rows->whereNotNull('branch_id')->keyBy('tender_key');

        $effective = $rows
            ->whereNull('branch_id')
            // Branch override replaces the org row wholesale (including its
            // is_active — that IS the per-branch activation switch).
            ->map(fn (TillTenderType $org) => $overrides->get($org->tender_key, $org))
            // Branch-only custom tenders (no org counterpart) join the list.
            ->concat($overrides->filter(
                fn (TillTenderType $branch) => $rows
                    ->whereNull('branch_id')
                    ->where('tender_key', $branch->tender_key)
                    ->isEmpty()
            ))
            ->filter(fn (TillTenderType $row) => (bool) $row->is_active);

        return $effective
            ->sortBy([
                fn (TillTenderType $a, TillTenderType $b) => ((int) $a->sort_order) <=> ((int) $b->sort_order),
                fn (TillTenderType $a, TillTenderType $b) => strcmp((string) $a->tender_key, (string) $b->tender_key),
            ])
            ->values();
    }
}
