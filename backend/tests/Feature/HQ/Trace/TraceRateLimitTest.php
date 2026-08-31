<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    RateLimiter::clear('throttle:30,1');
});

it('returns 429 when /trace exceeds 30 req/min', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId, 'slug' => 'rt-'.Str::random(4)]);
    $branch = Branch::factory()->create(['console_organization_id' => $orgId, 'console_brand_id' => $brand->id]);
    $warehouse = Warehouse::factory()->create(['organization_id' => $orgId, 'branch_id' => $branch->id]);
    $material = Material::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $lot = MaterialLot::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'material_id' => $material->id,
        'warehouse_id' => $warehouse->id,
    ]);

    $user = User::factory()->create(['console_organization_id' => $orgId]);
    grantOrgAccess($user, $orgId);
    $this->actingAs($user);

    $url = "/api/v1/hq/{$brand->slug}/trace/lot/{$lot->id}";

    // 30 OK requests within the window.
    for ($i = 0; $i < 30; $i++) {
        $this->getJson($url)->assertOk();
    }

    // 31st should hit the throttle limit.
    $this->getJson($url)->assertStatus(429);
});
