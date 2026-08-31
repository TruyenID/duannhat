<?php

/**
 * #1122 — branch-scope SECOND LAYER in org-only policies (follow-up to the
 * #904 decision: branch IS an isolation boundary).
 *
 * The route ring (ResolvesShopContext) already blocks a wrong-branch user on
 * every /shops/{slug}/… route. These tests call the POLICY DIRECTLY — i.e.
 * they simulate a future route that forgets the shop-context middleware —
 * and prove the policy itself now denies cross-branch access:
 *
 *   pivot (org, branch A)  → branch-A rows only
 *   pivot (org, NULL)      → org-wide, every branch
 *
 * Decision record: docs/explanation/branch-isolation.md.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;
use App\Policies\CustomerOrderPolicy;
use App\Services\Iam\UserWorkspaceAccess;
use Database\Seeders\IamSeeder;
use Illuminate\Support\Str;

uses()->group('iam');

beforeEach(function () {
    if (! Permission::query()->exists()) {
        (new IamSeeder)->run();
    }

    $orgId = (string) Str::uuid();
    $this->org = Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);

    $this->branchA = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
    ]);
    $this->branchB = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
    ]);

    $this->orderA = CustomerOrder::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'branch_id' => $this->branchA->id,
    ]);
    $this->orderB = CustomerOrder::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'branch_id' => $this->branchB->id,
    ]);

    // Simulate ONLY the brand/org resolution a non-shop route would have —
    // deliberately NO shop_id attribute (that is the middleware we bypass).
    request()->attributes->set('organization_id', $orgId);

    $this->policy = new CustomerOrderPolicy;
});

it('a branch-A-scoped role cannot touch a branch-B order even with middleware bypassed', function () {
    $user = User::factory()->create(['console_organization_id' => $this->org->id]);
    $user->assignRole('shop-manager', $this->org->id, $this->branchA->id);

    expect($this->policy->view($user, $this->orderA))->toBeTrue()
        ->and($this->policy->update($user, $this->orderA))->toBeTrue()
        // The backstop: same org, wrong branch → denied at the POLICY layer.
        ->and($this->policy->view($user, $this->orderB))->toBeFalse()
        ->and($this->policy->update($user, $this->orderB))->toBeFalse()
        ->and($this->policy->checkout($user, $this->orderB))->toBeFalse()
        ->and($this->policy->refund($user, $this->orderB))->toBeFalse()
        ->and($this->policy->void($user, $this->orderB))->toBeFalse();
});

it('an org-wide role reaches both branches — the backstop only bites branch-scoped pivots', function () {
    $user = User::factory()->create(['console_organization_id' => $this->org->id]);
    $user->assignRole('org-admin', $this->org->id);

    expect($this->policy->view($user, $this->orderA))->toBeTrue()
        ->and($this->policy->view($user, $this->orderB))->toBeTrue()
        ->and($this->policy->refund($user, $this->orderB))->toBeTrue();
});

it('a user with NO pivot at all is denied even inside the right org context', function () {
    $user = User::factory()->create(['console_organization_id' => $this->org->id]);

    expect($this->policy->view($user, $this->orderA))->toBeFalse();
});

it('cross-org stays denied before the branch layer is even consulted', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $user = User::factory()->create(['console_organization_id' => $otherOrgId]);
    $user->assignRole('org-admin', $otherOrgId);

    // Request context resolves THIS org; the order belongs to it, but if the
    // request context were the other org the org equality check fails first.
    request()->attributes->set('organization_id', $otherOrgId);

    expect($this->policy->view($user, $this->orderA))->toBeFalse();
});

it('UserWorkspaceAccess::canAccessBranch mirrors the ResolvesShopContext predicate', function () {
    $scoped = User::factory()->create(['console_organization_id' => $this->org->id]);
    $scoped->assignRole('shop-manager', $this->org->id, $this->branchA->id);

    $orgWide = User::factory()->create(['console_organization_id' => $this->org->id]);
    $orgWide->assignRole('org-admin', $this->org->id);

    $access = app(UserWorkspaceAccess::class);

    expect($access->canAccessBranch($scoped, (string) $this->branchA->id, (string) $this->org->id))->toBeTrue()
        ->and($access->canAccessBranch($scoped, (string) $this->branchB->id, (string) $this->org->id))->toBeFalse()
        ->and($access->canAccessBranch($orgWide, (string) $this->branchA->id, (string) $this->org->id))->toBeTrue()
        ->and($access->canAccessBranch($orgWide, (string) $this->branchB->id, (string) $this->org->id))->toBeTrue();
});
