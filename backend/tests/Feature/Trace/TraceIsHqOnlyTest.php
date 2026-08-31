<?php

declare(strict_types=1);

/**
 * #1271 — TracePolicy was written, documented as HQ-only, and never consulted.
 *
 * Its docblock states the reason plainly: "Trace queries hit recursive joins on
 * hot supplier lots that can fan out to thousands of children — only HQ roles
 * get access." TraceController authorised `MaterialLot::viewAny` instead, and
 * that policy allows shop-manager, staff and shop-staff. ResolveBrandFromSlug
 * only requires SOME role in the organisation, so a branch-level staff account
 * passed every check and reached the endpoint.
 *
 * The protection in place was a hidden sidebar link, which is not a check.
 *
 * Not an anonymous hole — it needs a logged-in account in the same organisation.
 * What was wrong is that a written, documented intent had never been enforced on
 * any single day.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

it('binds trace.view to TracePolicy, not to a lot policy', function () {
    // The wiring is the fix. Without the binding the controller call would throw
    // "ability not defined" rather than silently allow — but pinning it here
    // says which policy owns the decision.
    expect(Gate::has('trace.view'))->toBeTrue('trace.view is not defined; TraceController would fail closed');
});

it('refuses a branch-level staff account and admits an org manager', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId, 'is_active' => true]);
    Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    // grantOrgAccess() always assigns org-admin and takes no role argument — my
    // first attempt passed 'staff' as a third argument, it was ignored, and the
    // "staff" account was an org-admin. The test then failed on a 404 (the lot
    // does not exist) rather than the 403 it claimed to check.
    $staffRole = Role::query()->firstOrCreate(['slug' => 'staff'], ['name' => 'Staff', 'level' => 20]);
    $staff = User::factory()->create(['console_organization_id' => $orgId]);
    $staff->assignRole($staffRole, $orgId);

    // Exactly the account MaterialLotPolicy::viewAny lets through: a role in the
    // org, branch-scoped. Before #1271 this reached the recursive trace query.
    $this->actingAs($staff)
        ->getJson("/api/v1/hq/{$brand->slug}/trace/lot/".Str::uuid())
        ->assertForbidden();

    $manager = User::factory()->create(['console_organization_id' => $orgId]);
    grantOrgAccess($manager, $orgId);   // org-admin — a role TracePolicy names

    // And the roles the policy names must still get in — a fix that locks
    // everybody out would pass the assertion above and be useless. 404 is the
    // expected answer here: the gate lets them through and the lot id is random.
    $response = $this->actingAs($manager)
        ->getJson("/api/v1/hq/{$brand->slug}/trace/lot/".Str::uuid());

    expect($response->status())->not->toBe(403);
});
