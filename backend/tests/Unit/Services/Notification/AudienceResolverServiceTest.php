<?php

/**
 * AudienceResolverService unit tests (plan-012 T1.2).
 *
 * Covers the rule-resolution path that replaces Phase A's cap-50 union.
 * Tests target the service + its 5 sub-resolvers; DB is refreshed per test
 * so pivot inserts don't bleed across scenarios.
 */

use App\Exceptions\NotificationException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseMember;
use App\Services\Notification\AudienceResolvers\AudienceResolver;
use App\Services\Notification\AudienceResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->create([
        'console_organization_id' => (string) Str::uuid(),
        'slug' => 'aud-test-'.Str::random(6),
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'name' => 'Test Brand',
        'slug' => 'brand-'.Str::random(6),
    ]);

    $this->service = app(AudienceResolverService::class);
});

// Assign an org-level role row in role_user_pivots for a given user + slug.
function assignRole(User $user, string $slug, string $organizationId, ?string $branchId = null): Role
{
    $role = Role::query()->where('slug', $slug)->first()
        ?? Role::query()->create([
            'id' => (string) Str::uuid(),
            'console_organization_id' => $organizationId,
            'name' => ucfirst(str_replace('_', ' ', $slug)),
            'slug' => $slug,
            'level' => 100,
        ]);

    DB::table('role_user_pivots')->insert([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'organization_id' => $organizationId,
        'branch_id' => $branchId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $role;
}

describe('UserResolver', function () {
    it('returns exactly the users listed in user_ids', function () {
        $u1 = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);
        $u2 = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);
        User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]); // unrelated

        $result = $this->service->resolve([
            'combinator' => 'or',
            'rules' => [['type' => 'user', 'user_ids' => [$u1->id, $u2->id]]],
        ], $this->brand);

        expect($result->pluck('id')->sort()->values()->all())
            ->toBe(collect([$u1->id, $u2->id])->sort()->values()->all());
    });
});

describe('RoleResolver', function () {
    it('resolves warehouse_manager scoped to a warehouse via WarehouseMember', function () {
        $warehouse = Warehouse::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $manager = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);
        $staff = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);

        WarehouseMember::factory()->create(['warehouse_id' => $warehouse->id, 'user_id' => $manager->id, 'role' => 'manager']);
        WarehouseMember::factory()->create(['warehouse_id' => $warehouse->id, 'user_id' => $staff->id, 'role' => 'staff']);

        $result = $this->service->resolve([
            'rules' => [[
                'type' => 'role',
                'role' => 'warehouse_manager',
                'scope' => ['warehouse_id' => $warehouse->id],
            ]],
        ], $this->brand);

        expect($result->pluck('id')->all())->toBe([$manager->id]);
    });

    it('resolves brand_admin scoped to the brand via role_user_pivots', function () {
        $admin = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);
        assignRole($admin, 'org-admin', $this->organization->id);
        User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]); // no role row

        $result = $this->service->resolve([
            'rules' => [[
                'type' => 'role',
                'role' => 'org-admin',
                'scope' => ['brand_id' => $this->brand->id],
            ]],
        ], $this->brand);

        expect($result->pluck('id')->all())->toBe([$admin->id]);
    });
});

describe('BrandResolver', function () {
    it('returns every user with a role row in the brand\'s organizations', function () {
        $u1 = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);
        $u2 = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);
        assignRole($u1, 'org-admin', $this->organization->id);
        assignRole($u2, 'shop-manager', $this->organization->id);

        $result = $this->service->resolve([
            'rules' => [[
                'type' => 'brand',
                'brand_id' => $this->brand->id,
                'include_all_members' => true,
            ]],
        ], $this->brand);

        expect($result->pluck('id')->sort()->values()->all())
            ->toBe(collect([$u1->id, $u2->id])->sort()->values()->all());
    });
});

describe('ShopResolver', function () {
    it('returns every User member of a shop when include_members=true', function () {
        $branch = Branch::factory()->create([
            'console_organization_id' => $this->organization->console_organization_id,
        ]);
        $role = Role::firstOrCreate(['slug' => 'shop_member'], ['name' => 'Shop Member', 'level' => 10]);

        $u1 = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);
        $u2 = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);
        $outsider = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);

        DB::table('role_user_pivots')->insert([
            ['user_id' => $u1->id, 'role_id' => $role->id, 'organization_id' => $this->organization->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $u2->id, 'role_id' => $role->id, 'organization_id' => $this->organization->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()],
            // outsider attached to a different branch — must not leak
            ['user_id' => $outsider->id, 'role_id' => $role->id, 'organization_id' => $this->organization->id, 'branch_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $result = $this->service->resolve([
            'rules' => [[
                'type' => 'shop',
                'shop_ids' => [$branch->id],
                'include_members' => true,
            ]],
        ], $this->brand);

        expect($result->pluck('id')->sort()->values()->all())
            ->toBe(collect([$u1->id, $u2->id])->sort()->values()->all());
    });
});

describe('DeviceResolver', function () {
    it('returns Device models matching device_types and branch_id', function () {
        $branch = Branch::factory()->create([
            'console_organization_id' => $this->organization->console_organization_id,
        ]);

        $workstation = Device::factory()->create(['branch_id' => $branch->id, 'type' => 'workstation']);
        Device::factory()->create(['branch_id' => $branch->id, 'type' => 'tms']); // wrong type

        $result = $this->service->resolve([
            'rules' => [[
                'type' => 'device',
                'device_types' => ['workstation'],
                'branch_id' => $branch->id,
            ]],
        ], $this->brand);

        expect($result)->toHaveCount(1);
        expect($result->first())->toBeInstanceOf(Device::class);
        expect($result->first()->id)->toBe($workstation->id);
    });
});

describe('combinator and exclude', function () {
    it('union dedupes so a user matched by two role rules appears once', function () {
        $user = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);
        assignRole($user, 'org-admin', $this->organization->id);
        assignRole($user, 'shop-manager', $this->organization->id);

        $result = $this->service->resolve([
            'combinator' => 'or',
            'rules' => [
                ['type' => 'role', 'role' => 'org-admin', 'scope' => ['organization_id' => $this->organization->id]],
                ['type' => 'role', 'role' => 'shop-manager', 'scope' => ['organization_id' => $this->organization->id]],
            ],
        ], $this->brand);

        expect($result)->toHaveCount(1);
        expect($result->first()->id)->toBe($user->id);
    });

    it('exclude removes matched entries even if included by another rule', function () {
        $keep = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);
        $drop = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);

        $result = $this->service->resolve([
            'rules' => [['type' => 'user', 'user_ids' => [$keep->id, $drop->id]]],
            'exclude' => [['type' => 'user', 'user_ids' => [$drop->id]]],
        ], $this->brand);

        expect($result->pluck('id')->all())->toBe([$keep->id]);
    });
});

describe('trace', function () {
    it('resolveWithTrace returns a trace map keyed by morph+id', function () {
        $user = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);

        $result = $this->service->resolveWithTrace([
            'rules' => [['type' => 'user', 'user_ids' => [$user->id]]],
        ], $this->brand);

        expect($result)->toHaveKeys(['recipients', 'trace']);
        expect($result['recipients']->first()->id)->toBe($user->id);

        $expectedKey = $user->getMorphClass().':'.$user->id;
        expect($result['trace']->get($expectedKey))->toBe('user:direct');
    });
});

describe('caps and errors', function () {
    it('throws audience_too_large when resolved set exceeds MAX_RECIPIENTS', function () {
        // Stub resolver that returns 10001 synthetic entries, bypassing factory cost.
        $oversize = new class implements AudienceResolver
        {
            public function type(): string
            {
                return 'role';
            }

            public function resolve(array $rule, Brand $brand): Collection
            {
                $user = User::factory()->create();

                return Collection::make(range(1, AudienceResolverService::MAX_RECIPIENTS + 1))
                    ->map(fn (int $i): array => [
                        'notifiable' => $user,
                        'key' => 'user:synthetic-'.$i, // unique per entry to bypass dedup
                        'trace' => 'role:stub',
                    ]);
            }
        };

        $service = new AudienceResolverService([$oversize]);

        expect(fn () => $service->resolve(['rules' => [['type' => 'role']]], $this->brand))
            ->toThrow(
                fn (NotificationException $e) => $e->errorCode === 'audience_too_large'
                    && $e->statusCode === 422,
            );
    });

    it('throws unknown_resolver_type when a sub-rule references a missing type', function () {
        expect(fn () => $this->service->resolve([
            'rules' => [['type' => 'never-registered']],
        ], $this->brand))->toThrow(
            fn (NotificationException $e) => $e->errorCode === 'unknown_resolver_type',
        );
    });
});
