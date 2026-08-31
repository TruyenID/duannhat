<?php

namespace App\Services\Notification\AudienceResolvers;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use App\Services\Iam\Contracts\RoleAssignmentDirectory;
use Illuminate\Support\Collection;

/**
 * Resolves `{type: 'brand', brand_id: '<uuid>', include_all_members: true}`.
 *
 * Brand → local organizations (via `brands.console_organization_id` mirror)
 * → users with any role row in those orgs via `role_user_pivots`. De-dup is
 * the orchestrator's job.
 *
 * Trace: `brand:{brand_id}:members`.
 */
class BrandResolver implements AudienceResolver
{
    /** #1622 — cổng đọc phân công vai của PlatformIntegration. */
    private function roles(): RoleAssignmentDirectory
    {
        return app(RoleAssignmentDirectory::class);
    }

    public function type(): string
    {
        return 'brand';
    }

    public function resolve(array $rule, Brand $brand): Collection
    {
        $brandId = $rule['brand_id'] ?? $brand->id;

        $target = (string) $brandId === (string) $brand->id
            ? $brand
            : Brand::query()->find($brandId);

        if ($target === null) {
            return collect();
        }

        $orgIds = Organization::query()
            ->where('console_organization_id', $target->console_organization_id)
            ->pluck('id')
            ->all();

        if ($orgIds === []) {
            return collect();
        }

        $userIds = $this->roles()->userIdsInOrganizations($orgIds);

        if ($userIds === []) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->map(fn (User $user): array => [
                'notifiable' => $user,
                'key' => $user->getMorphClass().':'.$user->getKey(),
                'trace' => "brand:{$brandId}:members",
            ])
            ->values();
    }
}
