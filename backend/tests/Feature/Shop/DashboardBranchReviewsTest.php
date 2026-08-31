<?php

use App\Models\Branch;
use App\Models\BranchReview;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\File;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

// Read-back coverage for the /dashboard/branch-reviews endpoint: the plan-026
// aspect ratings, highlights, and photos must be surfaced (not write-only).

beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'test-shop-reviews',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/shops/{$this->shop->slug}/dashboard";
});

function makeReviewOrder(): CustomerOrder
{
    return CustomerOrder::factory()->closed()->create([
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
        'branch_id' => test()->shop->id,
        'total_amount' => 1000,
    ]);
}

it('surfaces aspect ratings, highlights, and photos in recent reviews', function () {
    $order = makeReviewOrder();

    $review = BranchReview::factory()->create([
        'branch_id' => $this->shop->id,
        'customer_order_id' => $order->id,
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'rating' => 5,
        'service_rating' => 4,
        'staff_rating' => 5,
        'space_rating' => 3,
        'highlights' => ['Hương vị đậm đà', 'Phục vụ nhanh'],
        'comment' => 'Tốt',
    ]);

    File::factory()->permanent()->create([
        'fileable_type' => $review->getMorphClass(),
        'fileable_id' => $review->id,
        'collection' => 'review',
        'disk' => 'public',
        'sort_order' => 0,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("{$this->base}/branch-reviews")
        ->assertOk()
        ->assertJsonPath('data.recent.0.service_rating', 4)
        ->assertJsonPath('data.recent.0.staff_rating', 5)
        ->assertJsonPath('data.recent.0.space_rating', 3)
        ->assertJsonPath('data.recent.0.highlights', ['Hương vị đậm đà', 'Phục vụ nhanh'])
        ->assertJsonCount(1, 'data.recent.0.photos')
        ->assertJsonStructure(['data' => ['recent' => [['id', 'rating', 'photos' => [['id', 'url']]]]]]);

    expect($response->json('data.recent.0.service_rating'))->toBeInt();
});

it('returns null aspects + empty highlights/photos for a rating-only review', function () {
    $order = makeReviewOrder();

    BranchReview::factory()->create([
        'branch_id' => $this->shop->id,
        'customer_order_id' => $order->id,
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'rating' => 4,
    ]);

    $this->actingAs($this->user)
        ->getJson("{$this->base}/branch-reviews")
        ->assertOk()
        ->assertJsonPath('data.recent.0.service_rating', null)
        ->assertJsonPath('data.recent.0.highlights', [])
        ->assertJsonPath('data.recent.0.photos', []);
});

it('returns 401 when unauthenticated', function () {
    $this->getJson("{$this->base}/branch-reviews")->assertUnauthorized();
});
