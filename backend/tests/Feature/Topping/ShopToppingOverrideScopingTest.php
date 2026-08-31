<?php

declare(strict_types=1);

/**
 * #1265 — the shop-side topping-override resolvers looked the topping group up
 * globally by a URL-supplied id, three lines below a query that filters the menu
 * on both branch_id and organization_id. A shop could therefore hang its
 * overrides off another organization's topping group, and
 * validateToppingOverrides would then validate the item ids against that foreign
 * group.
 *
 * Not dramatic — the id is a UUID, so it cannot be guessed, and reading the pair
 * (this shop's menu product, a foreign group) returns nothing. What was wrong is
 * the asymmetry inside one method: two lookups carefully scoped, the third not.
 * HQ\ToppingGroupController scopes the same model by organization_id.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\ToppingGroup;
use App\Models\User;
use Illuminate\Support\Str;

function toppingScopingOrg(): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId, 'is_active' => true]);
    $shop = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);
    $user = User::factory()->create(['console_organization_id' => $orgId]);
    grantOrgAccess($user, $orgId);

    return [$orgId, $brand, $shop, $user];
}

it('refuses a topping group belonging to another organization', function () {
    [$orgId, , $shop, $user] = toppingScopingOrg();
    [$otherOrgId] = toppingScopingOrg();

    $foreignGroup = ToppingGroup::factory()->create(['organization_id' => $otherOrgId]);

    // The menu id is deliberately nonsense: the group lookup must not be the
    // thing that decides the outcome, so a 404 here is the correct answer
    // whichever lookup fires first. What must NOT happen is the foreign group
    // resolving and being written into an override row.
    $response = $this->actingAs($user)->getJson(
        "/api/v1/shops/{$shop->slug}/menus/".Str::uuid().'/products/'.Str::uuid()
        ."/topping-groups/{$foreignGroup->id}/overrides"
    );

    expect($response->status())->toBeIn([403, 404]);

    // And the group really does belong to the other organization, so the test
    // is not passing because the fixture was mis-built.
    expect($foreignGroup->fresh()->organization_id)->toBe($otherOrgId)
        ->and($otherOrgId)->not->toBe($orgId);
});

it('scopes the topping group lookup in both shop override resolvers', function () {
    // A source assertion because the HTTP path above cannot distinguish "the
    // group was scoped out" from "the menu id was nonsense" — and the defect was
    // precisely that one of three lookups in the method was unscoped.
    foreach ([
        'app/Http/Controllers/Api/V1/Shop/ShopMenuToppingOverrideController.php',
        'app/Http/Controllers/Api/V1/Shop/ShopFloatingSectionToppingOverrideController.php',
    ] as $file) {
        $source = file_get_contents(base_path($file));

        expect($source)->toMatch(
            '/ToppingGroup::query\(\)\s*\n\s*->where\(\'organization_id\'/',
            "{$file} looks a ToppingGroup up without scoping it to the caller's organization",
        );
    }
});
