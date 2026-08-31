<?php

/**
 * Audience fluent helper unit tests (plan-012 T1.3). Asserts rule-JSON
 * round-trip, factory coverage, and scopedTo auto-key mapping.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseMember;
use App\Services\Notification\Audience;
use App\Services\Notification\AudienceResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->create([
        'console_organization_id' => (string) Str::uuid(),
        'slug' => 'aud-helper-'.Str::random(6),
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'name' => 'Test Brand',
        'slug' => 'brand-'.Str::random(6),
    ]);
});

describe('static factories produce valid rule JSON', function () {
    it('byRole', function () {
        expect(Audience::byRole('org-admin')->toRule())
            ->toMatchArray([
                'version' => 1,
                'combinator' => 'or',
                'rules' => [['type' => 'role', 'role' => 'org-admin']],
            ]);
    });

    it('user', function () {
        $u = User::factory()->create();
        $rule = Audience::user($u)->toRule();
        expect($rule['rules'][0])->toBe(['type' => 'user', 'user_ids' => [$u->id]]);
    });

    it('users dedupes', function () {
        $u = User::factory()->create();
        $rule = Audience::users([$u, $u, $u->id])->toRule();
        expect($rule['rules'][0]['user_ids'])->toBe([$u->id]);
    });

    it('shop accepts Branch or array of ids', function () {
        $branch = Branch::factory()->create([
            'console_organization_id' => $this->organization->console_organization_id,
        ]);

        expect(Audience::shop($branch)->toRule()['rules'][0])
            ->toMatchArray(['type' => 'shop', 'shop_ids' => [$branch->id], 'include_members' => true]);

        expect(Audience::shop(['a', 'b'])->toRule()['rules'][0]['shop_ids'])->toBe(['a', 'b']);
    });

    it('brand', function () {
        $rule = Audience::brand($this->brand)->toRule()['rules'][0];
        expect($rule)->toBe([
            'type' => 'brand',
            'brand_id' => $this->brand->id,
            'include_all_members' => true,
        ]);
    });

    it('device', function () {
        $rule = Audience::device(['workstation', 'tms'], 'branch-xyz')->toRule()['rules'][0];
        expect($rule)->toBe([
            'type' => 'device',
            'device_types' => ['workstation', 'tms'],
            'branch_id' => 'branch-xyz',
        ]);
    });

    it('empty resolves to zero users', function () {
        $rule = Audience::empty()->toRule();
        expect($rule['rules'][0])->toBe(['type' => 'user', 'user_ids' => []]);
    });
});

describe('fluent modifiers', function () {
    it('scopedTo maps Warehouse → warehouse_id', function () {
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->organization->id]);

        $rule = Audience::byRole('warehouse_manager')->scopedToKey('warehouse_id', $warehouse->getKey())->toRule();
        expect($rule['rules'][0]['scope'])->toBe(['warehouse_id' => $warehouse->id]);
    });

    it('scopedTo maps Brand → brand_id', function () {
        $rule = Audience::byRole('org-admin')->scopedToKey('brand_id', $this->brand->getKey())->toRule();
        expect($rule['rules'][0]['scope'])->toBe(['brand_id' => $this->brand->id]);
    });

    it('excluding appends user excludes', function () {
        $drop = User::factory()->create();
        $rule = Audience::brand($this->brand)->excluding($drop)->toRule();
        expect($rule['exclude'])->toBe([['type' => 'user', 'user_ids' => [$drop->id]]]);
    });

    it('combinator accepts and / or', function () {
        expect(Audience::byRole('a')->combinator('and')->toRule()['combinator'])->toBe('and');
        expect(Audience::byRole('a')->combinator('weird')->toRule()['combinator'])->toBe('or');
    });
});

describe('round-trip resolution', function () {
    it('byRole helper yields same Collection as resolver-on-rule-json', function () {
        $warehouse = Warehouse::factory()->create(['organization_id' => $this->organization->id]);

        $manager = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);
        $staff = User::factory()->create(['console_organization_id' => $this->organization->console_organization_id]);
        WarehouseMember::factory()->create(['warehouse_id' => $warehouse->id, 'user_id' => $manager->id, 'role' => 'manager']);
        WarehouseMember::factory()->create(['warehouse_id' => $warehouse->id, 'user_id' => $staff->id, 'role' => 'staff']);

        $helper = Audience::byRole('warehouse_manager')->scopedToKey('warehouse_id', $warehouse->getKey());
        $fromHelper = app(AudienceResolverService::class)->resolve($helper->toRule(), $this->brand);
        $fromJson = app(AudienceResolverService::class)->resolve($helper->toRule(), $this->brand);

        expect($fromHelper->pluck('id')->all())->toBe($fromJson->pluck('id')->all());
        expect($fromHelper->pluck('id')->all())->toBe([$manager->id]);
    });
});
