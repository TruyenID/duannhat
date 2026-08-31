<?php

namespace App\Http\Controllers\Traits;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-isolation guard for the caller-controlled `audience_inline` /
 * inline-`rule` path (plan-012 audit CRITICAL).
 *
 * An inline rule is fully caller-controlled JSON handed straight to the
 * brand-unscoped resolvers (UserResolver `whereIn('id', ...)`, DeviceResolver,
 * BrandResolver, RoleResolver), which happily expand arbitrary user_ids /
 * device_ids / role scopes from ANY organization. Both the broadcast DELIVERY
 * path and the audience PREVIEW path must run every resolved recipient through
 * this post-resolution ownership check; a single foreign recipient aborts the
 * whole operation with 422 `audience_cross_tenant` — we reject rather than
 * silently drop so the injection attempt is visible, is never partially
 * delivered, and leaks neither a recipient count nor a name sample.
 */
trait GuardsInlineAudienceTenancy
{
    /**
     * Every resolved recipient MUST belong to the route brand's organization.
     *
     * Ownership predicate:
     *   - User: `console_organization_id` matches the brand's console org, OR
     *     the user is assigned into one of the brand's local orgs via a
     *     `role_user_pivots` row (multi-org staff whose home console org
     *     differs but who legitimately belong here).
     *   - Device: its `branch_id` resolves to a branch under the brand's
     *     console org. A device with no branch is treated as foreign.
     *
     * @param  Collection<int, mixed>  $recipients
     */
    protected function assertInlineRecipientsOwned(Collection $recipients, Brand $brand): void
    {
        $consoleOrgId = (string) $brand->console_organization_id;
        $reject = fn () => abort(response()->json(['message' => 'audience_cross_tenant'], 422));

        // --- Users -----------------------------------------------------------
        $foreignUserIds = $recipients
            ->filter(fn ($r) => $r instanceof User)
            ->reject(fn (User $u) => (string) $u->console_organization_id === $consoleOrgId)
            ->pluck('id')
            ->unique()
            ->values();

        if ($foreignUserIds->isNotEmpty()) {
            // Rescue users linked to one of the brand's local orgs by a role
            // pivot before declaring them cross-tenant.
            $rescued = DB::table('role_user_pivots')
                ->whereIn('user_id', $foreignUserIds->all())
                ->whereIn('organization_id', $this->tenancyBrandOrgIds($brand))
                ->distinct()
                ->pluck('user_id');

            if ($foreignUserIds->diff($rescued)->isNotEmpty()) {
                $reject();
            }
        }

        // --- Devices ---------------------------------------------------------
        $devices = $recipients->filter(fn ($r) => $r instanceof Device);

        if ($devices->isNotEmpty()) {
            $branchIds = $devices->pluck('branch_id');

            // A branchless device cannot be proven ours → reject.
            if ($branchIds->contains(fn ($b) => empty($b))) {
                $reject();
            }

            $distinct = $branchIds->unique()->values();
            $ownedCount = Branch::query()
                ->whereIn('id', $distinct->all())
                ->where('console_organization_id', $consoleOrgId)
                ->count();

            if ($ownedCount !== $distinct->count()) {
                $reject();
            }
        }
    }

    /**
     * Local organization primary keys that map to the brand's console org.
     *
     * @return array<int, string>
     */
    private function tenancyBrandOrgIds(Brand $brand): array
    {
        return Organization::query()
            ->where('console_organization_id', $brand->console_organization_id)
            ->pluck('id')
            ->all();
    }
}
