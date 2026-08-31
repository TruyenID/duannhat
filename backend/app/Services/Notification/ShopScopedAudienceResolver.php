<?php

namespace App\Services\Notification;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\NotificationAudience;
use App\Models\User;
use App\Services\Iam\Contracts\RoleAssignmentDirectory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Plan-023 M6 T6.2 — wraps AudienceResolverService to intersect the
 * resolved recipient set with a shop's (branch's) memberships.
 *
 * Use case: a shop_admin composes a broadcast against a brand-wide
 * audience like "All shop managers". Without scoping, that broadcast
 * fans out to shop managers of OTHER shops in the brand. The
 * decorator intersects the resolved set with role_user_pivots rows
 * where `branch_id = $shop->id`, so only members of the requesting
 * shop receive the notification.
 *
 * Device recipients pass through untouched — Device→shop membership
 * is modelled via `Device.branch_id` directly (not via role pivots).
 *
 * Empty intersection is legitimate (admin picked an audience whose
 * members aren't part of this shop) — surface to the broadcast
 * controller as zero-recipients, NOT a 422; the composer step 1
 * already shows a preview count.
 */
final class ShopScopedAudienceResolver
{
    /** #1622 — cổng đọc phân công vai của PlatformIntegration. */
    private function roles(): RoleAssignmentDirectory
    {
        return app(RoleAssignmentDirectory::class);
    }

    public function __construct(
        private readonly AudienceResolverService $base,
    ) {}

    /**
     * Resolve the audience rule + intersect with shop membership.
     *
     * @return Collection<int, Model>
     */
    public function resolveForShop(NotificationAudience|array $input, Brand $brand, Branch $shop): Collection
    {
        $resolved = $this->base->resolve($input, $brand);
        if ($resolved->isEmpty()) {
            return $resolved;
        }

        // Partition by morph class — only User entries get intersected
        // through role_user_pivots. Devices stay scoped via their own
        // branch_id and pass through if and only if Device.branch_id
        // matches the shop.
        $userIds = $resolved
            ->filter(fn (Model $m) => $m->getMorphClass() === 'User')
            ->map(fn (Model $m) => $m->getKey())
            ->values();

        $devices = $resolved
            ->filter(fn (Model $m) => $m->getMorphClass() === 'Device')
            ->filter(fn (Model $m) => (string) ($m->branch_id ?? '') === (string) $shop->id);

        if ($userIds->isEmpty()) {
            return $devices->values();
        }

        $shopMemberIds = collect($this->roles()->userIdsAssignedToBranch(
            $userIds->map(fn ($id): string => (string) $id)->all(),
            (string) $shop->id,
        ));

        $shopMembers = $resolved
            ->filter(fn (Model $m) => $m->getMorphClass() === 'User')
            ->filter(fn (Model $m) => $shopMemberIds->contains((string) $m->getKey()));

        return $shopMembers->concat($devices)->values();
    }

    /**
     * Convenience helper for callers that already hold User and Device
     * collections rather than an audience rule (e.g. NotificationService
     * dispatch path where recipients were pre-resolved).
     *
     * @param  Collection<int, Model>  $recipients
     * @return Collection<int, Model>
     */
    public function intersectWithShop(Collection $recipients, Branch $shop): Collection
    {
        if ($recipients->isEmpty()) {
            return $recipients;
        }

        $userIds = $recipients
            ->filter(fn (Model $m) => $m instanceof User)
            ->map(fn (Model $m) => $m->getKey())
            ->values();

        $devices = $recipients
            ->filter(fn (Model $m) => $m->getMorphClass() === 'Device')
            ->filter(fn (Model $m) => (string) ($m->branch_id ?? '') === (string) $shop->id);

        if ($userIds->isEmpty()) {
            return $devices->values();
        }

        $shopMemberIds = collect($this->roles()->userIdsAssignedToBranch(
            $userIds->map(fn ($id): string => (string) $id)->all(),
            (string) $shop->id,
        ));

        return $recipients
            ->filter(function (Model $m) use ($shopMemberIds, $shop) {
                if ($m instanceof User) {
                    return $shopMemberIds->contains((string) $m->getKey());
                }
                if ($m->getMorphClass() === 'Device') {
                    return (string) ($m->branch_id ?? '') === (string) $shop->id;
                }

                return false;
            })
            ->values();
    }
}
