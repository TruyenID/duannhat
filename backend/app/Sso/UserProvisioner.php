<?php

declare(strict_types=1);

namespace App\Sso;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Provisioning\BranchBaselineProvisioner;
use App\Services\Provisioning\BrandBaselineProvisioner;
use App\Support\Iam\RoleTemplateMatrix;
use Dxs\Auth\Contracts\ProvisionsUsers;
use Dxs\Auth\Services\PlatformContextClient;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class UserProvisioner implements ProvisionsUsers
{
    public function __construct(private readonly PlatformContextClient $contexts) {}

    /**
     * @param  array<string, mixed>  $claims
     * @param  array{access_token: string, refresh_token?: string, expires_in?: int}  $tokens
     */
    public function provision(array $claims, array $tokens): Authenticatable
    {
        $subject = (string) $claims['sub'];
        $user = User::query()->where('console_user_id', $subject)->first() ?? new User;

        $user->forceFill(array_filter([
            'console_user_id' => $subject,
            'name' => $claims['name'] ?? $user->name ?? '',
            'email' => $claims['email'] ?? $user->email ?? '',
            'is_active' => true,
            'console_organization_id' => $claims['organization_context_id']
                ?? $claims['organization_id']
                ?? $user->console_organization_id
                ?? null,
            'console_access_token' => $tokens['access_token'] ?? $user->console_access_token ?? null,
            'console_refresh_token' => $tokens['refresh_token'] ?? $user->console_refresh_token ?? null,
            'console_token_expires_at' => isset($tokens['expires_in'])
                ? Carbon::now()->addSeconds((int) $tokens['expires_in'])
                : ($user->console_token_expires_at ?? null),
        ], static fn (mixed $value): bool => $value !== null));

        $user->save();
        $this->syncOrganizationRole($user, (string) ($tokens['access_token'] ?? ''));

        return $user;
    }

    public function resolveBySubject(string $subject): ?Authenticatable
    {
        return User::query()->where('console_user_id', $subject)->first();
    }

    private function syncOrganizationRole(User $user, string $accessToken): void
    {
        if ($accessToken === '') {
            return;
        }

        try {
            $organizations = $this->contexts->organizations($accessToken);
            $platformOrganizationId = (string) $user->console_organization_id;
            $context = collect($organizations)->first(
                fn (array $organization): bool => (string) ($organization['organization_id'] ?? '') === $platformOrganizationId,
            );

            if (! is_array($context)) {
                return;
            }

            // #1153 — adopt the Platform's operating country when the contexts
            // payload carries it. Adopt-if-present on purpose: an older
            // Platform without the field must NOT reset an already-mirrored
            // value back to the JP default.
            $country = strtoupper(trim((string) ($context['country'] ?? '')));

            $organization = Organization::withTrashed()->updateOrCreate(
                ['console_organization_id' => $platformOrganizationId],
                [
                    'name' => (string) ($context['organization_name'] ?? 'Organization'),
                    'slug' => (string) ($context['organization_slug'] ?? 'org-'.substr($platformOrganizationId, 0, 8)),
                    'is_active' => true,
                    ...(preg_match('/^[A-Z]{2}$/', $country) === 1 ? ['operating_country' => $country] : []),
                ],
            );
            if ($organization->trashed()) {
                $organization->restore();
            }

            $serviceRole = strtolower((string) ($context['service_role'] ?? 'member'));
            $roleSlug = 'tempo-'.$serviceRole;
            // #2460 — template PHẢI lấy từ RoleTemplateMatrix, không phải một
            // `match` viết tay ở đây. Bản `match` cũ nói `manager => org-manager`
            // trong khi bảng ánh xạ (và test A6f ghim nó) nói `tempo-manager ≡
            // shop-manager`: cùng một người được CẤP quyền org-manager nhưng lại
            // được ĐỐI XỬ như shop-manager ở mọi chỗ hỏi theo vai. Docblock của
            // PLATFORM_ROLE_TEMPLATES nói rõ nó tồn tại để provisioning và
            // authorization không trôi khỏi nhau — nên đọc từ đó.
            $template = RoleTemplateMatrix::PLATFORM_ROLE_TEMPLATES[$roleSlug] ?? 'shop-staff';
            $role = Role::firstOrCreate(
                ['slug' => $roleSlug, 'console_organization_id' => $platformOrganizationId],
                [
                    'name' => 'Tempo '.ucfirst($serviceRole),
                    'level' => (int) ($context['service_role_level'] ?? RoleTemplateMatrix::ROLES[$template]['level']),
                ],
            );

            if ($role->wasRecentlyCreated || ! $role->permissions()->exists()) {
                $role->permissions()->sync(
                    Permission::query()->whereIn('slug', RoleTemplateMatrix::for($template))->pluck('id')->all(),
                );
            }

            $this->syncBrands($organization, $accessToken);
            $branchScope = $this->syncBranches($organization, $accessToken);
            $this->syncRoleScopes($user, $organization, $role, $branchScope);
            $user->forceFill(['last_org_sync_at' => now()])->save();
        } catch (\Throwable $exception) {
            Log::warning('Platform organization provisioning failed; login continues fail-safe.', [
                'subject' => $user->console_user_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function syncBrands(Organization $organization, string $accessToken): void
    {
        $directory = $this->contexts->brands($accessToken, $organization->slug);
        $brands = $directory['brands'] ?? [];

        if (! is_array($brands)) {
            return;
        }

        foreach ($brands as $brandData) {
            if (! is_array($brandData) || ! filled($brandData['brand_id'] ?? null)) {
                continue;
            }

            $brand = Brand::withTrashed()->updateOrCreate(
                ['console_brand_id' => (string) $brandData['brand_id']],
                [
                    'console_organization_id' => $organization->console_organization_id,
                    'slug' => (string) ($brandData['brand_slug'] ?? 'brand-'.substr((string) $brandData['brand_id'], 0, 8)),
                    'name' => (string) ($brandData['brand_name'] ?? 'Brand'),
                    'description' => filled($brandData['description'] ?? null) ? (string) $brandData['description'] : null,
                    'logo_url' => filled($brandData['logo_url'] ?? null) ? (string) $brandData['logo_url'] : null,
                    'is_active' => (bool) ($brandData['is_active'] ?? true),
                ],
            );

            if ($brand->trashed()) {
                $brand->restore();
            }

            // #2320 — ĐÂY là "entrypoint provisioning của Platform". Brand xuất
            // hiện ở tempo qua đúng vòng lặp này (ai đó đăng nhập, thư mục
            // Platform đổ xuống), nên đây là chỗ duy nhất biết một brand vừa
            // sinh ra ngoài lúc seed. Trước #2320 không ai gọi nó, và brand mới
            // sống tiếp mà không có loại thuế nào.
            //
            // Idempotent, nên gọi mỗi lượt đăng nhập là an toàn — và còn vá
            // được brand cũ đang thiếu, thứ một hook `created` không làm được.
            app(BrandBaselineProvisioner::class)->ensure($brand);
        }
    }

    /** @return array{all: bool, branch_ids: list<string>} */
    private function syncBranches(Organization $organization, string $accessToken): array
    {
        $directory = $this->contexts->branches($accessToken, $organization->slug);
        $branches = $directory['branches'] ?? [];

        if (! is_array($branches)) {
            return ['all' => false, 'branch_ids' => []];
        }

        $localBranchIds = [];

        foreach ($branches as $branchData) {
            if (! is_array($branchData) || ! filled($branchData['id'] ?? null)) {
                continue;
            }

            $branch = Branch::withTrashed()->updateOrCreate(
                ['console_branch_id' => (string) $branchData['id']],
                [
                    'console_organization_id' => $organization->console_organization_id,
                    'console_brand_id' => filled($branchData['brand_id'] ?? null)
                        ? (string) $branchData['brand_id']
                        : null,
                    'code' => filled($branchData['code'] ?? null) ? (string) $branchData['code'] : null,
                    'slug' => (string) ($branchData['slug'] ?? 'branch-'.substr((string) $branchData['id'], 0, 8)),
                    'name' => (string) ($branchData['name'] ?? 'Branch'),
                    'is_headquarters' => (bool) ($branchData['is_headquarters'] ?? false),
                    // `true` unconditionally is CORRECT for this feed, not a
                    // leftover — do not "fix" it into a mirror (#3161).
                    //
                    // Platform's `/api/sso/branches` filters to active branches
                    // (`SsoBranchController` → `->where('is_active', true)`) and
                    // its payload carries NO `is_active` key at all. For a feed
                    // that is already filtered, the only truthful thing a
                    // consumer may conclude is "everything I received is active"
                    // — which is exactly what this line encodes.
                    //
                    // Deriving `false` from a branch's ABSENCE would be the
                    // "missing means deleted" antipattern: absence conflates
                    // deactivated · out of scope for this credential · truncated
                    // page · failed upstream call. SCIM 2.0 (RFC 7643 §4.1.1) is
                    // explicit that deactivation travels as an `active`
                    // attribute, never as list membership.
                    //
                    // The gap this leaves is real and is handled elsewhere: a
                    // branch deactivated upstream simply stops appearing, so the
                    // mirror never learns. `platform:reconcile-directory`
                    // (#3143) reports that as `local present / remote absent`,
                    // which is the signal a human acts on.
                    //
                    // ORDER MATTERS before changing any of this: the explicit
                    // `is_active` already exists in the identity outbox
                    // (dxs-platform/platform#798), so (1) land the relay, (2)
                    // mirror the field FROM THE EVENT FEED, and only then (3)
                    // drop Platform's filter. Doing (3) first makes Tempo stamp
                    // inactive branches as active — worse than today. And note
                    // what (2) buys: `is_active = false` makes a branch
                    // unresolvable in `ResolveBranchFromSlug`, i.e. the shop
                    // disappears from its own URL. That is a product decision,
                    // not a cleanup.
                    'is_active' => true,
                    'timezone' => filled($branchData['timezone'] ?? null) ? (string) $branchData['timezone'] : null,
                    'currency' => filled($branchData['currency'] ?? null) ? (string) $branchData['currency'] : null,
                    'locale' => filled($branchData['locale'] ?? null) ? (string) $branchData['locale'] : null,
                ],
            );

            if ($branch->trashed()) {
                $branch->restore();
            }

            // #2320 — cùng lý do với brand ở trên: chi nhánh cũng sinh ra ở
            // đúng vòng lặp này, và một chi nhánh không có `shop_order_settings`
            // là chi nhánh chưa biết mình bán bằng tiền gì.
            app(BranchBaselineProvisioner::class)->ensure($branch);

            $localBranchIds[] = (string) $branch->id;
        }

        return [
            'all' => (bool) ($directory['all_branches_access'] ?? false),
            'branch_ids' => array_values(array_unique($localBranchIds)),
        ];
    }

    /** @param array{all: bool, branch_ids: list<string>} $branchScope */
    private function syncRoleScopes(
        User $user,
        Organization $organization,
        Role $role,
        array $branchScope,
    ): void {
        DB::transaction(function () use ($user, $organization, $role, $branchScope): void {
            DB::table('role_user_pivots')
                ->where('user_id', $user->id)
                ->where('organization_id', $organization->id)
                ->delete();

            if ($branchScope['all']) {
                $user->assignRole($role, $organization->id);

                return;
            }

            foreach ($branchScope['branch_ids'] as $branchId) {
                $user->assignRole($role, $organization->id, $branchId);
            }
        });
    }
}
