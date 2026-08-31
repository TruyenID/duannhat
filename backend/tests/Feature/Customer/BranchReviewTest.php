<?php

use App\Models\Branch;
use App\Models\BranchReview;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\File;
use App\Models\Organization;
use App\Omnify\Enums\FileStatusEnum;
use App\Services\Customer\BranchReviewService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'console_brand_id' => $this->brand->console_brand_id,
        'review_avg_rating' => null,
        'review_total_count' => 0,
    ]);

    $this->order = CustomerOrder::factory()->closed()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);
});

// =========================================================================
//  Happy path (T6.2)
// =========================================================================

it('submits a branch review with rating only', function () {
    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 4,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.rating', 4)
        ->assertJsonPath('data.comment', null);

    $this->assertDatabaseHas('branch_reviews', [
        'customer_order_id' => $this->order->id,
        'branch_id' => $this->branch->id,
        'rating' => 4,
    ]);
});

it('uploads review photos and returns temp file ids + urls', function () {
    Storage::fake(config('filesystems.uploads'));

    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/review-photos", [
        'photos' => [
            UploadedFile::fake()->image('dish1.jpg'),
            UploadedFile::fake()->image('dish2.jpg'),
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data' => [['id', 'url']]]);

    expect(File::where('collection', 'review')->where('status', FileStatusEnum::Temporary)->count())->toBe(2);
});

it('attaches uploaded photos to the branch review on submit', function () {
    Storage::fake(config('filesystems.uploads'));

    $uploaded = $this->postJson("/api/v1/customer/orders/{$this->order->id}/review-photos", [
        'photos' => [UploadedFile::fake()->image('dish.jpg')],
    ])->json('data');
    $fileId = $uploaded[0]['id'];

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 5,
        'photo_file_ids' => [$fileId],
    ])->assertStatus(201);

    $review = BranchReview::where('customer_order_id', $this->order->id)->first();
    $file = File::find($fileId);
    expect($file->fileable_type)->toBe($review->getMorphClass());
    expect($file->fileable_id)->toBe($review->id);
    expect($file->status)->toBe(FileStatusEnum::Permanent);
    expect($review->photos()->count())->toBe(1);
});

it('rejects non-image upload', function () {
    Storage::fake(config('filesystems.uploads'));

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/review-photos", [
        'photos' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
    ])->assertStatus(422);
});

it('stores aspect ratings + highlights (plan-026)', function () {
    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 5,
        'service_rating' => 4,
        'staff_rating' => 5,
        'space_rating' => 3,
        'highlights' => ['Hương vị đậm đà', 'Phục vụ nhanh'],
        'comment' => 'Tốt',
    ]);

    $response->assertStatus(201);

    $review = BranchReview::where('customer_order_id', $this->order->id)->first();
    expect($review->service_rating)->toEqual(4);
    expect($review->staff_rating)->toEqual(5);
    expect($review->space_rating)->toEqual(3);
    expect($review->highlights)->toBe(['Hương vị đậm đà', 'Phục vụ nhanh']);
});

it('returns aspect ratings + highlights in the store response (read-back, not write-only)', function () {
    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 5,
        'service_rating' => 4,
        'staff_rating' => 5,
        'space_rating' => 3,
        'highlights' => ['Hương vị đậm đà', 'Phục vụ nhanh'],
        'comment' => 'Tốt',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.service_rating', 4)
        ->assertJsonPath('data.staff_rating', 5)
        ->assertJsonPath('data.space_rating', 3)
        ->assertJsonPath('data.highlights', ['Hương vị đậm đà', 'Phục vụ nhanh'])
        ->assertJsonPath('data.photos', []);

    // Ratings must serialize as integers, not strings (VARCHAR column).
    expect($response->json('data.service_rating'))->toBeInt();
    expect($response->json('data.rating'))->toBeInt();
});

it('returns null aspect ratings + empty highlights when omitted', function () {
    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 4,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.service_rating', null)
        ->assertJsonPath('data.staff_rating', null)
        ->assertJsonPath('data.space_rating', null)
        ->assertJsonPath('data.highlights', [])
        ->assertJsonPath('data.photos', []);
});

it('returns aspect ratings + highlights + photos in the reviewable existing_review', function () {
    Storage::fake(config('filesystems.uploads'));

    $fileId = $this->postJson("/api/v1/customer/orders/{$this->order->id}/review-photos", [
        'photos' => [UploadedFile::fake()->image('dish.jpg')],
    ])->json('data.0.id');

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 5,
        'service_rating' => 4,
        'staff_rating' => 5,
        'space_rating' => 3,
        'highlights' => ['Hương vị đậm đà'],
        'photo_file_ids' => [$fileId],
        'comment' => 'Tốt',
    ])->assertStatus(201);

    $response = $this->getJson("/api/v1/customer/orders/{$this->order->id}/branch-reviewable");

    $response->assertOk()
        ->assertJsonPath('data.can_review', false)
        ->assertJsonPath('data.existing_review.service_rating', 4)
        ->assertJsonPath('data.existing_review.staff_rating', 5)
        ->assertJsonPath('data.existing_review.space_rating', 3)
        ->assertJsonPath('data.existing_review.highlights', ['Hương vị đậm đà'])
        ->assertJsonCount(1, 'data.existing_review.photos')
        ->assertJsonStructure(['data' => ['existing_review' => ['photos' => [['id', 'url']]]]]);
});

it('rejects aspect rating outside 1-5', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 5,
        'service_rating' => 9,
    ])->assertStatus(422);
});

it('submits a branch review with rating and comment', function () {
    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 5,
        'comment' => 'Excellent service!',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.rating', 5)
        ->assertJsonPath('data.comment', 'Excellent service!');
});

it('accepts all valid rating values 1-5', function (int $rating) {
    $order = CustomerOrder::factory()->closed()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->postJson("/api/v1/customer/orders/{$order->id}/branch-review", [
        'rating' => $rating,
    ])->assertStatus(201)
        ->assertJsonPath('data.rating', $rating);
})->with([1, 2, 3, 4, 5]);

it('updates branch aggregate after submission', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 4,
    ])->assertStatus(201);

    $this->branch->refresh();
    expect((float) $this->branch->review_avg_rating)->toEqual(4.0);
    expect($this->branch->review_total_count)->toEqual(1);
});

// =========================================================================
//  Reviewable endpoint (T6.3)
// =========================================================================

it('returns can_review=true for closed order without existing review', function () {
    $response = $this->getJson("/api/v1/customer/orders/{$this->order->id}/branch-reviewable");

    $response->assertOk()
        ->assertJsonPath('data.can_review', true)
        ->assertJsonPath('data.existing_review', null)
        ->assertJsonPath('data.branch.id', $this->branch->id)
        ->assertJsonPath('data.branch.name', $this->branch->name);
});

it('returns can_review=false with existing_review after submission', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 3,
        'comment' => 'Good',
    ])->assertStatus(201);

    $response = $this->getJson("/api/v1/customer/orders/{$this->order->id}/branch-reviewable");

    $response->assertOk()
        ->assertJsonPath('data.can_review', false)
        ->assertJsonPath('data.existing_review.comment', 'Good');

    expect($response->json('data.existing_review.rating'))->toEqual(3);
});

// =========================================================================
//  Validation (T6.4)
// =========================================================================

it('rejects missing rating', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rating']);
});

it('rejects rating below 1', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 0,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['rating']);
});

it('rejects rating above 5', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 6,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['rating']);
});

it('rejects non-integer rating', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 3.5,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['rating']);
});

it('rejects comment exceeding 2000 characters', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 4,
        'comment' => str_repeat('a', 2001),
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['comment']);
});

it('accepts null comment', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 4,
        'comment' => null,
    ])->assertStatus(201);
});

// =========================================================================
//  Authorization (T6.5)
// =========================================================================

it('returns 404 for non-existent order on POST', function () {
    $fakeId = '00000000-0000-0000-0000-000000000999';

    $this->postJson("/api/v1/customer/orders/{$fakeId}/branch-review", [
        'rating' => 4,
    ])->assertStatus(404)
        ->assertJsonPath('code', 'order_not_found');
});

it('returns 404 for non-existent order on GET reviewable', function () {
    $fakeId = '00000000-0000-0000-0000-000000000999';

    $this->getJson("/api/v1/customer/orders/{$fakeId}/branch-reviewable")
        ->assertStatus(404)
        ->assertJsonPath('code', 'order_not_found');
});

it('rejects review for open order', function () {
    $order = CustomerOrder::factory()->open()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->postJson("/api/v1/customer/orders/{$order->id}/branch-review", [
        'rating' => 4,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'order_not_closed');
});

it('rejects reviewable check for open order', function () {
    $order = CustomerOrder::factory()->open()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->getJson("/api/v1/customer/orders/{$order->id}/branch-reviewable")
        ->assertStatus(422)
        ->assertJsonPath('code', 'order_not_closed');
});

it('allows anonymous access (no auth required)', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 5,
    ])->assertStatus(201);

    $this->getJson("/api/v1/customer/orders/{$this->order->id}/branch-reviewable")
        ->assertOk();
});

it('attaches customer_id when authenticated', function () {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
            'rating' => 4,
        ])->assertStatus(201);

    $this->assertDatabaseHas('branch_reviews', [
        'customer_order_id' => $this->order->id,
        'customer_id' => $customer->id,
    ]);
});

// =========================================================================
//  Idempotency (T6.6)
// =========================================================================

it('returns 422 already_reviewed on duplicate submission', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 4,
    ])->assertStatus(201);

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 2,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'already_reviewed');

    // Only one review in DB
    expect(BranchReview::where('customer_order_id', $this->order->id)->count())->toEqual(1);
});

it('does not change aggregate on duplicate submission', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 5,
    ])->assertStatus(201);

    $this->branch->refresh();
    expect($this->branch->review_total_count)->toEqual(1);
    expect((float) $this->branch->review_avg_rating)->toEqual(5.0);

    // Second attempt
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 1,
    ])->assertStatus(422);

    $this->branch->refresh();
    expect($this->branch->review_total_count)->toEqual(1);
    expect((float) $this->branch->review_avg_rating)->toEqual(5.0);
});

it('has unique constraint on customer_order_id', function () {
    BranchReview::factory()->create([
        'customer_order_id' => $this->order->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'rating' => 4,
    ]);

    expect(fn () => BranchReview::factory()->create([
        'customer_order_id' => $this->order->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'rating' => 2,
    ]))->toThrow(QueryException::class);
});

// =========================================================================
//  Aggregate correctness (T6.7)
// =========================================================================

it('computes average across multiple orders for same branch', function () {
    // Order 1: rating 4
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 4,
    ])->assertStatus(201);

    // Order 2: rating 2
    $order2 = CustomerOrder::factory()->closed()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->postJson("/api/v1/customer/orders/{$order2->id}/branch-review", [
        'rating' => 2,
    ])->assertStatus(201);

    $this->branch->refresh();
    expect($this->branch->review_total_count)->toEqual(2);
    expect((float) $this->branch->review_avg_rating)->toEqual(3.0);
});

it('calculateAvgRating returns null when no reviews exist', function () {
    $service = app(BranchReviewService::class);
    expect($service->calculateAvgRating($this->branch->id))->toBeNull();
});

it('calculateAvgRating returns correct average', function () {
    BranchReview::factory()->create([
        'branch_id' => $this->branch->id,
        'customer_order_id' => $this->order->id,
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'rating' => 5,
    ]);

    $order2 = CustomerOrder::factory()->closed()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    BranchReview::factory()->create([
        'branch_id' => $this->branch->id,
        'customer_order_id' => $order2->id,
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'rating' => 3,
    ]);

    $service = app(BranchReviewService::class);
    expect($service->calculateAvgRating($this->branch->id))->toEqual(4.0);
});

it('does not leak reviews between branches', function () {
    // Review on this branch
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 5,
    ])->assertStatus(201);

    // Different branch
    $branch2 = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'console_brand_id' => $this->brand->console_brand_id,
        'review_avg_rating' => null,
        'review_total_count' => 0,
    ]);
    $order2 = CustomerOrder::factory()->closed()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $branch2->id,
    ]);

    $this->postJson("/api/v1/customer/orders/{$order2->id}/branch-review", [
        'rating' => 1,
    ])->assertStatus(201);

    // Each branch has its own aggregate
    $this->branch->refresh();
    $branch2->refresh();

    expect((float) $this->branch->review_avg_rating)->toEqual(5.0);
    expect($this->branch->review_total_count)->toEqual(1);
    expect((float) $branch2->review_avg_rating)->toEqual(1.0);
    expect($branch2->review_total_count)->toEqual(1);
});

it('correctly recalculates average with many reviews (not running average)', function () {
    // Create 100 reviews via factory with known ratings
    $ratings = [];
    for ($i = 0; $i < 100; $i++) {
        $order = CustomerOrder::factory()->closed()->create([
            'organization_id' => $this->organization->id,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
        ]);
        $rating = ($i % 5) + 1; // cycles 1,2,3,4,5
        $ratings[] = $rating;
        BranchReview::factory()->create([
            'customer_order_id' => $order->id,
            'branch_id' => $this->branch->id,
            'organization_id' => $this->organization->id,
            'brand_id' => $this->brand->id,
            'rating' => $rating,
        ]);
    }

    // Submit one more via API (rating 5)
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 5,
    ])->assertStatus(201);
    $ratings[] = 5;

    $expectedAvg = round(array_sum($ratings) / count($ratings), 2);

    $this->branch->refresh();
    expect($this->branch->review_total_count)->toEqual(101);
    expect((float) $this->branch->review_avg_rating)->toEqual($expectedAvg);
});

// =========================================================================
//  Edge cases + Display integration (T6.8)
// =========================================================================

it('includes review_avg_rating and review_total_count in branches list', function () {
    $this->branch->update([
        'is_active' => true,
        'review_avg_rating' => 4.50,
        'review_total_count' => 10,
    ]);

    $response = $this->getJson('/api/v1/customer/branches');

    $response->assertOk();
    $branch = collect($response->json('data'))->firstWhere('id', $this->branch->id);

    expect($branch)->not->toBeNull();
    expect($branch['review_avg_rating'])->toEqual(4.5);
    expect($branch['review_total_count'])->toEqual(10);
});

it('returns null review_avg_rating when branch has no reviews', function () {
    $this->branch->update(['is_active' => true]);

    $response = $this->getJson('/api/v1/customer/branches');

    $response->assertOk();
    $branch = collect($response->json('data'))->firstWhere('id', $this->branch->id);

    expect($branch)->not->toBeNull();
    expect($branch['review_avg_rating'])->toBeNull();
    expect($branch['review_total_count'])->toEqual(0);
});

it('rejects review for voided order', function () {
    $order = CustomerOrder::factory()->voided()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->postJson("/api/v1/customer/orders/{$order->id}/branch-review", [
        'rating' => 3,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'order_not_closed');
});

it('rejects reviewable check for voided order', function () {
    $order = CustomerOrder::factory()->voided()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->getJson("/api/v1/customer/orders/{$order->id}/branch-reviewable")
        ->assertStatus(422)
        ->assertJsonPath('code', 'order_not_closed');
});

it('rejects review for dining (non-closed) order', function () {
    $order = CustomerOrder::factory()->dining()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->postJson("/api/v1/customer/orders/{$order->id}/branch-review", [
        'rating' => 3,
    ])->assertStatus(422)
        ->assertJsonPath('code', 'order_not_closed');
});

// =========================================================================
//  Rate limiting (throttle:5,1) — plan-026 spam-review mitigation
// =========================================================================

it('throttles branch-review submissions at 5 requests per minute (throttle:5,1)', function () {
    // Other tests in this process share the array cache the limiter uses;
    // flush so this test owns a fresh 5-request budget for the route+IP key.
    Cache::flush();

    // Request 1 succeeds (201). The throttle counter increments on every
    // request that reaches the middleware, regardless of the response status,
    // so requests 2-5 (which land 422 already_reviewed) still consume budget.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 4,
    ])->assertStatus(201);

    foreach (range(2, 5) as $i) {
        $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
            'rating' => 4,
        ])->assertStatus(422)->assertJsonPath('code', 'already_reviewed');
    }

    // The 6th request is rejected by the rate limiter before reaching the
    // controller — it never gets a chance to return 422.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 4,
    ])->assertStatus(429);

    // The throttled request created nothing: exactly one review exists.
    expect(BranchReview::where('customer_order_id', $this->order->id)->count())->toEqual(1);
});

it('scopes the throttle budget per client (a distinct IP is not blocked by another IP hitting the limit)', function () {
    Cache::flush();

    // Client A burns its full budget against distinct orders.
    foreach (range(1, 6) as $i) {
        $order = CustomerOrder::factory()->closed()->create([
            'organization_id' => $this->organization->id,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson("/api/v1/customer/orders/{$order->id}/branch-review", ['rating' => 4]);
    }

    // Client A is now throttled.
    $orderA = CustomerOrder::factory()->closed()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->postJson("/api/v1/customer/orders/{$orderA->id}/branch-review", ['rating' => 4])
        ->assertStatus(429);

    // A different client (distinct IP) still has its own fresh budget.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", ['rating' => 5])
        ->assertStatus(201);
});

// =========================================================================
//  Concurrency — competing submitters on the SAME order (plan-026)
// =========================================================================

it('keeps only the first review when a second submitter races the same order (loser does not overwrite customer_id)', function () {
    // Winner: an anonymous submission commits first (customer_id stays null).
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
        'rating' => 5,
        'comment' => 'winner',
    ])->assertStatus(201);

    // Loser: a *logged-in* customer submits for the same order moments later.
    // The idempotency guard must reject it — and crucially must NOT rewrite the
    // stored review with the loser's rating/comment or stamp its customer_id.
    $customer = Customer::factory()->create();
    $this->actingAs($customer, 'customer')
        ->postJson("/api/v1/customer/orders/{$this->order->id}/branch-review", [
            'rating' => 1,
            'comment' => 'loser',
        ])->assertStatus(422)
        ->assertJsonPath('code', 'already_reviewed');

    // Exactly one review survived — the winner's, untouched.
    expect(BranchReview::where('customer_order_id', $this->order->id)->count())->toEqual(1);
    $review = BranchReview::where('customer_order_id', $this->order->id)->first();
    expect($review->rating)->toEqual(5);
    expect($review->comment)->toBe('winner');
    expect($review->customer_id)->toBeNull();

    // The branch aggregate reflects one review at the winner's rating, not two.
    $this->branch->refresh();
    expect($this->branch->review_total_count)->toEqual(1);
    expect((float) $this->branch->review_avg_rating)->toEqual(5.0);
});
