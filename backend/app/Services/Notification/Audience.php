<?php

namespace App\Services\Notification;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Fluent helper for building audience rule JSON at emitter call sites.
 *
 * Emitters use this instead of hand-writing the rule array. `toRule()`
 * produces the SAME JSON shape persisted on `notification_audiences.rule`,
 * so `AudienceResolverService::resolve` is a single code path whether the
 * rule came from a saved audience row or an inline helper invocation.
 *
 * Examples:
 *
 *   Audience::byRole('warehouse_manager')->scopedTo($warehouse)
 *   Audience::byRole('org-admin')->scopedTo($brand)->excluding($user)
 *   Audience::users([$u1, $u2])
 *   Audience::device(['workstation'], $branchId)
 *
 * Each static factory maps 1:1 to an AudienceResolver's `type()`; an arch
 * test asserts the mapping so adding a new factory without a resolver
 * (or vice versa) fails CI.
 */
class Audience
{
    /** @var array<int, array<string, mixed>> */
    private array $rules = [];

    /** @var array<int, array<string, mixed>> */
    private array $exclude = [];

    private string $combinator = 'or';

    private int $version = 1;

    /**
     * Map of static factory method → resolver type it produces. Audited by
     * an arch test to catch factory/resolver drift.
     *
     * @var array<string, string>
     */
    public const FACTORY_TO_RESOLVER_TYPE = [
        'byRole' => 'role',
        'user' => 'user',
        'users' => 'user',
        'shop' => 'shop',
        'brand' => 'brand',
        'device' => 'device',
        'empty' => 'user',
    ];

    // =========================================================================
    //  Static factories
    // =========================================================================

    public static function byRole(string $role): self
    {
        return (new self)->addRule(['type' => 'role', 'role' => $role]);
    }

    /**
     * Nhiều vai CÙNG một phạm vi, OR với nhau (#2450).
     *
     * Phải nhận phạm vi ở đây chứ không để caller nối `scopedToKey()`, vì hàm đó
     * cố ý chỉ gắn scope vào rule CUỐI. Dựng `byRole(a)->byRole(b)->scopedToKey()`
     * sẽ ra một rule có phạm vi và một rule KHÔNG — tức vai thứ nhất bắn cho
     * toàn bộ tổ chức. Ghép sẵn thành một factory để hình dạng sai đó không dựng
     * được.
     *
     * @param  list<string>  $roles
     */
    public static function byRolesInScope(array $roles, string $scopeKey, string|int $scopeId): self
    {
        $audience = new self;

        foreach (array_values(array_unique($roles)) as $role) {
            $audience = $audience->addRule([
                'type' => 'role',
                'role' => $role,
                'scope' => [$scopeKey => $scopeId],
            ]);
        }

        return $audience;
    }

    public static function user(User $user): self
    {
        return (new self)->addRule(['type' => 'user', 'user_ids' => [$user->id]]);
    }

    /**
     * @param  iterable<User>|array<int, string>  $users
     */
    public static function users(iterable $users): self
    {
        $ids = [];
        foreach ($users as $u) {
            $ids[] = $u instanceof User ? $u->id : (string) $u;
        }

        return (new self)->addRule(['type' => 'user', 'user_ids' => array_values(array_unique($ids))]);
    }

    /**
     * @param  array<int, string>|Branch  $shop
     */
    public static function shop(array|Branch $shop): self
    {
        $ids = $shop instanceof Branch ? [$shop->id] : $shop;

        return (new self)->addRule(['type' => 'shop', 'shop_ids' => $ids, 'include_members' => true]);
    }

    public static function brand(Brand $brand): self
    {
        return (new self)->addRule([
            'type' => 'brand',
            'brand_id' => $brand->id,
            'include_all_members' => true,
        ]);
    }

    /**
     * @param  array<int, string>  $deviceTypes
     */
    public static function device(array $deviceTypes, Branch|string|null $branch = null): self
    {
        $rule = ['type' => 'device', 'device_types' => $deviceTypes];
        if ($branch !== null) {
            $rule['branch_id'] = $branch instanceof Branch ? $branch->id : $branch;
        }

        return (new self)->addRule($rule);
    }

    /**
     * Empty audience — resolves to nothing. Useful as a safe default for
     * call sites that gate dispatch by feature flag.
     */
    public static function empty(): self
    {
        return (new self)->addRule(['type' => 'user', 'user_ids' => []]);
    }

    // =========================================================================
    //  Fluent modifiers
    // =========================================================================

    /**
     * Attach a scope to the MOST-RECENTLY-ADDED sub-rule. Auto-maps the
     * model class to its scope key so emitter call sites read naturally:
     *
     *   Audience::byRole('warehouse_manager')->scopedTo($warehouse)
     *   Audience::byRole('org-admin')->scopedTo($brand)
     *   Audience::byRole('shop-manager')->scopedTo($branch)
     */

    /**
     * @param  User|array<int, User|string>  $users
     */
    /**
     * Giới hạn phạm vi bằng KHOÁ, không bằng Model (#1568).
     *
     * Bản cũ `scopedTo(Model $scope)` phải dò `instanceof` để suy ra khoá, nên nó
     * buộc chính lớp này import `Warehouse` (Inventory) và `Device`
     * (PlatformIntegration) — hai cạnh RA của Notifications sinh ra chỉ vì chữ ký
     * nhận Model. Caller nêu thẳng khoá thì ladder đó không còn lý do tồn tại.
     *
     * Khoá hợp lệ: `warehouse_id` · `brand_id` · `organization_id` · `branch_id`
     * · `device_id` — đúng tập mà `AudienceResolverService` hiểu.
     */
    public function scopedToKey(string $key, string|int $id): self
    {
        if ($this->rules === []) {
            return $this;
        }

        $lastIdx = array_key_last($this->rules);
        $this->rules[$lastIdx]['scope'] = ($this->rules[$lastIdx]['scope'] ?? []) + [$key => $id];

        return $this;
    }

    public function excluding(User|array $users): self
    {
        $ids = [];
        if ($users instanceof User) {
            $ids[] = $users->id;
        } else {
            foreach ($users as $u) {
                $ids[] = $u instanceof User ? $u->id : (string) $u;
            }
        }

        if ($ids === []) {
            return $this;
        }

        $this->exclude[] = ['type' => 'user', 'user_ids' => array_values(array_unique($ids))];

        return $this;
    }

    /**
     * Override the last rule's `include_members` flag (applies to `shop`,
     * `brand`, and similar rule types).
     */
    public function includeMembers(bool $flag): self
    {
        if ($this->rules === []) {
            return $this;
        }

        $lastIdx = array_key_last($this->rules);
        $lastType = $this->rules[$lastIdx]['type'] ?? null;

        $key = match ($lastType) {
            'brand' => 'include_all_members',
            default => 'include_members',
        };
        $this->rules[$lastIdx][$key] = $flag;

        return $this;
    }

    public function combinator(string $mode): self
    {
        $this->combinator = match ($mode) {
            'and' => 'and',
            default => 'or',
        };

        return $this;
    }

    // =========================================================================
    //  Serialization
    // =========================================================================

    /**
     * @return array{version: int, combinator: string, rules: array<int, array<string, mixed>>, exclude?: array<int, array<string, mixed>>}
     */
    public function toRule(): array
    {
        $out = [
            'version' => $this->version,
            'combinator' => $this->combinator,
            'rules' => array_values($this->rules),
        ];

        if ($this->exclude !== []) {
            $out['exclude'] = array_values($this->exclude);
        }

        return $out;
    }

    // =========================================================================
    //  Internal
    // =========================================================================

    /**
     * @param  array<string, mixed>  $rule
     */
    private function addRule(array $rule): self
    {
        $this->rules[] = $rule;

        return $this;
    }

    /**
     * Map a scope model class to its rule-scope key.
     */
}
