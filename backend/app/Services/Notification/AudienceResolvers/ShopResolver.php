<?php

namespace App\Services\Notification\AudienceResolvers;

use App\Models\Brand;
use App\Models\User;
use App\Services\Iam\Contracts\RoleAssignmentDirectory;
use Illuminate\Support\Collection;

/**
 * Resolves `{type: 'shop', shop_ids: [...], include_members: true}` sub-rules.
 *
 * In this codebase "shop" maps to `branches` (a Branch IS a shop). Members
 * of a shop are users whose `role_user_pivots.branch_id` matches. When
 * `include_members` is false only direct branch managers
 * (role slug `shop-manager`) are returned.
 *
 * Trace: `shop:{branch_id}:members` (or `:managers` when narrowed).
 */
class ShopResolver implements AudienceResolver
{
    /** #1622 — cổng đọc phân công vai của PlatformIntegration. */
    private function roles(): RoleAssignmentDirectory
    {
        return app(RoleAssignmentDirectory::class);
    }

    public function type(): string
    {
        return 'shop';
    }

    public function resolve(array $rule, Brand $brand): Collection
    {
        $shopIds = array_values(array_unique((array) ($rule['shop_ids'] ?? [])));
        if ($shopIds === []) {
            return collect();
        }

        $includeMembers = (bool) ($rule['include_members'] ?? true);
        $traceSuffix = $includeMembers ? 'members' : 'managers';

        $rows = collect($this->roles()->assignmentsInBranches(
            $shopIds,
            $includeMembers ? null : 'shop-manager',
        ));

        if ($rows->isEmpty()) {
            return collect();
        }

        $users = User::query()
            ->whereIn('id', $rows->pluck('userId')->unique())
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function (array $row) use ($users, $traceSuffix): ?array {
                $user = $users->get($row['userId']);
                if ($user === null) {
                    return null;
                }

                return [
                    'notifiable' => $user,
                    'key' => $user->getMorphClass().':'.$user->getKey(),
                    'trace' => "shop:{$row['branchId']}:{$traceSuffix}",
                ];
            })
            ->filter()
            ->values();
    }
}
