<?php

namespace App\Models;

use App\Contracts\Notifiable as InboxRecipient;
use App\Models\Concerns\ReceivesNotifications;
use App\Support\Iam\RoleTemplateMatrix;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection as SupportCollection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements InboxRecipient
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    // plan-008 inbox relations — override Laravel `Notifiable` trait's
    // `notifications`/`unreadNotifications` so $user->notificationInbox() and
    // $user->unreadNotifications() point at OUR notification_recipients table.
    use ReceivesNotifications {
        ReceivesNotifications::unreadNotifications insteadof Notifiable;
    }

    /**
     * `console_user_id` / `console_organization_id` are deliberately NOT
     * fillable — an HTTP caller must never switch their own principal or
     * console_organization_id and escape tenancy. They are written only via
     * `forceFill` on the provisioning/sync paths (factories are unguarded).
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_url',
        'locale',
        'timezone',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'console_access_token',
        'console_refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'console_token_expires_at' => 'datetime',
            'last_org_sync_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user_pivots')
            ->withPivot('organization_id', 'branch_id')
            ->withTimestamps();
    }

    /**
     * Inbox rows addressed to this user (#1561 — declared here, not in
     * `ReceivesNotifications`, so SharedKernel never names a Notifications
     * model).
     */
    public function notificationInbox(): MorphMany
    {
        return $this->morphMany(NotificationRecipient::class, 'recipient');
    }

    /*
     * ------------------------------------------------------------------
     * Scoped role assignments — #1561 (epic #962).
     * Was `App\Models\Concerns\HasScopedRoles`.
     * ------------------------------------------------------------------
     * Local domain role assignments; Platform remains authoritative for
     * service permissions.
     *
     * The trait lived under `App\Models\Concerns\`, which `deptrac.yaml` maps
     * to SharedKernel — and `SharedKernel: ~` says shared infrastructure
     * depends on NOTHING. It named `Role` (PlatformIntegration) and
     * `Organization` (TenancyKernel), so the bottom of the dependency graph
     * pointed back up into the modules and closed a two-step cycle with the
     * allowed reverse edge.
     *
     * `User` was its ONLY consumer and is itself the TenancyKernel anchor
     * these methods are about, so the code moved to its owner instead of
     * hiding behind an indirection. `User → Organization` is now a same-layer
     * edge; `User → Role` already existed here (`roles()`) and is recorded in
     * `deptrac-baseline.yaml`. No namespace a trait could live in lands in
     * TenancyKernel — that layer is collected by exact model class name — so
     * inlining is the only move that REMOVES the edge rather than renaming it.
     */

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(
            Organization::class,
            'role_user_pivots',
            'user_id',
            'organization_id',
            'id',
            'id',
        )->whereNotNull('role_user_pivots.organization_id')->distinct();
    }

    public function assignRole(Role|string $role, ?string $organizationId = null, ?string $branchId = null): void
    {
        if (is_string($role)) {
            $role = Role::query()->where('slug', $role)->first()
                ?? throw new \InvalidArgumentException("Role with slug [{$role}] not found.");
        }

        $query = $this->roles()->where('roles.id', $role->id);
        $query = $organizationId === null
            ? $query->wherePivotNull('organization_id')
            : $query->wherePivot('organization_id', $organizationId);
        $query = $branchId === null
            ? $query->wherePivotNull('branch_id')
            : $query->wherePivot('branch_id', $branchId);

        if (! $query->exists()) {
            $this->roles()->attach($role->id, [
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
            ]);
        }
    }

    public function removeRole(Role $role, ?string $organizationId = null, ?string $branchId = null): void
    {
        $query = $this->roles();
        $query = $organizationId === null
            ? $query->wherePivotNull('organization_id')
            : $query->wherePivot('organization_id', $organizationId);
        $query = $branchId === null
            ? $query->wherePivotNull('branch_id')
            : $query->wherePivot('branch_id', $branchId);
        $query->detach($role->id);
    }

    public function getRolesForContext(?string $organizationId = null, ?string $branchId = null): Collection
    {
        $query = $this->roles()->withPivot('organization_id', 'branch_id');

        if ($organizationId === null) {
            return $query->wherePivotNull('organization_id')->wherePivotNull('branch_id')->get();
        }

        if ($branchId === null) {
            return $query->where(function ($query) use ($organizationId): void {
                $query->whereRaw('(role_user_pivots.organization_id IS NULL AND role_user_pivots.branch_id IS NULL)')
                    ->orWhereRaw(
                        '(role_user_pivots.organization_id = ? AND role_user_pivots.branch_id IS NULL)',
                        [$organizationId],
                    );
            })->get();
        }

        return $query->where(function ($query) use ($organizationId, $branchId): void {
            $query->whereRaw('(role_user_pivots.organization_id IS NULL AND role_user_pivots.branch_id IS NULL)')
                ->orWhereRaw(
                    '(role_user_pivots.organization_id = ? AND role_user_pivots.branch_id IS NULL)',
                    [$organizationId],
                )
                ->orWhereRaw(
                    '(role_user_pivots.organization_id = ? AND role_user_pivots.branch_id = ?)',
                    [$organizationId, $branchId],
                );
        })->get();
    }

    public function hasRoleInContext(string $slug, ?string $organizationId = null, ?string $branchId = null): bool
    {
        $equivalentSlugs = RoleTemplateMatrix::equivalentSlugs($slug);

        return $this->getRolesForContext($organizationId, $branchId)
            ->contains(fn (Role $role): bool => in_array($role->slug, $equivalentSlugs, true));
    }

    public function getHighestRoleLevelInContext(?string $organizationId = null, ?string $branchId = null): int
    {
        return (int) ($this->getRolesForContext($organizationId, $branchId)->max('level') ?? 0);
    }

    /** @param list<string> $permissions */
    public function hasAnyPermission(array $permissions, ?string $organizationId = null, ?string $branchId = null): bool
    {
        foreach ($this->getRolesForContext($organizationId, $branchId) as $role) {
            $rolePermissions = $role->permissions->pluck('slug')->all();
            foreach ($permissions as $permission) {
                if (in_array(trim($permission), $rolePermissions, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasPermission(string $permission, ?string $organizationId = null, ?string $branchId = null): bool
    {
        return $this->hasAnyPermission([$permission], $organizationId, $branchId);
    }

    public function getAllPermissions(?string $organizationId = null, ?string $branchId = null): SupportCollection
    {
        return $this->getRolesForContext($organizationId, $branchId)
            ->flatMap(fn (Role $role) => $role->permissions)
            ->unique('id');
    }

    /** @param list<Role> $roles */
    public function syncRolesInScope(array $roles, ?string $organizationId = null, ?string $branchId = null): void
    {
        $query = $this->roles();
        $query = $organizationId === null
            ? $query->wherePivotNull('organization_id')
            : $query->wherePivot('organization_id', $organizationId);
        $query = $branchId === null
            ? $query->wherePivotNull('branch_id')
            : $query->wherePivot('branch_id', $branchId);
        $query->detach();

        foreach ($roles as $role) {
            $this->assignRole($role, $organizationId, $branchId);
        }
    }
}
