<?php

declare(strict_types=1);

namespace App\Services\Shop;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\User;
use App\Services\Iam\UserWorkspaceAccess;

/**
 * Directory of shops a user can access — the org-scoped listing use-case
 * shared by REST (/api/v1/me/shops) and the MCP `shop_list` tool.
 *
 * Scope source of truth: the user's IAM role assignments (role_user_pivots),
 * NEVER a caller-supplied organization id (IDOR guard — MCP Service Contract §7).
 */
class UserShopDirectoryService
{
    public function __construct(private readonly UserWorkspaceAccess $access) {}

    /**
     * Active shops in every organization the user holds a role in.
     *
     * @return array<int, array{id: string, name: string, slug: string, is_active: bool, brand_name: string|null}>
     */
    public function listFor(User $user, ?string $search = null, int $limit = 25): array
    {
        $shops = $this->access->branches($user)
            ->where('is_active', true)
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->select(['id', 'name', 'slug', 'console_brand_id', 'is_active'])
            ->orderBy('name')
            ->limit(max(1, min($limit, 100)))
            ->get();

        $brandsByConsoleId = Brand::whereIn(
            'console_brand_id',
            $shops->pluck('console_brand_id')->filter()->unique()->all(),
        )->pluck('name', 'console_brand_id');

        return $shops->map(fn (Branch $shop) => [
            'id' => $shop->id,
            'name' => $shop->name,
            'slug' => $shop->slug,
            'is_active' => (bool) $shop->is_active,
            'brand_name' => $brandsByConsoleId[$shop->console_brand_id] ?? null,
        ])->all();
    }
}
