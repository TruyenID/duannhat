<?php

use App\Models\Post;
use App\Omnify\Enums\PostStatusEnum;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';
});

// =========================================================================
//  Visibility rules
// =========================================================================

it('lists only Published posts with past published_at', function () {
    Post::factory()->create([
        'organization_id' => $this->orgId,
        'slug' => 'visible',
        'status' => PostStatusEnum::Published,
        'published_at' => Carbon::now()->subDay(),
        'is_pinned' => false,
    ]);

    Post::factory()->create([
        'organization_id' => $this->orgId,
        'slug' => 'draft',
        'status' => PostStatusEnum::Draft,
        'published_at' => null,
    ]);

    Post::factory()->create([
        'organization_id' => $this->orgId,
        'slug' => 'archived',
        'status' => PostStatusEnum::Archived,
        'published_at' => Carbon::now()->subDay(),
    ]);

    Post::factory()->create([
        'organization_id' => $this->orgId,
        'slug' => 'scheduled',
        'status' => PostStatusEnum::Published,
        'published_at' => Carbon::now()->addDay(),
    ]);

    $response = $this->getJson('/api/v1/customer/posts');

    $response->assertOk();
    $slugs = collect($response->json('data'))->pluck('slug')->all();

    expect($slugs)->toEqual(['visible']);
});

// =========================================================================
//  Ordering
// =========================================================================

it('orders pinned posts before non-pinned and newest first within each group', function () {
    Post::factory()->create([
        'organization_id' => $this->orgId,
        'slug' => 'old-pinned',
        'status' => PostStatusEnum::Published,
        'published_at' => Carbon::now()->subDays(10),
        'is_pinned' => true,
    ]);

    Post::factory()->create([
        'organization_id' => $this->orgId,
        'slug' => 'newest',
        'status' => PostStatusEnum::Published,
        'published_at' => Carbon::now()->subHour(),
        'is_pinned' => false,
    ]);

    Post::factory()->create([
        'organization_id' => $this->orgId,
        'slug' => 'middle',
        'status' => PostStatusEnum::Published,
        'published_at' => Carbon::now()->subDays(2),
        'is_pinned' => false,
    ]);

    $response = $this->getJson('/api/v1/customer/posts');
    $slugs = collect($response->json('data'))->pluck('slug')->all();

    expect($slugs)->toEqual(['old-pinned', 'newest', 'middle']);
});

// =========================================================================
//  Query params
// =========================================================================

it('caps limit at 20 and defaults to 6', function () {
    Post::factory()->count(25)->create([
        'organization_id' => $this->orgId,
        'status' => PostStatusEnum::Published,
        'published_at' => Carbon::now()->subMinute(),
        'is_pinned' => false,
    ]);

    expect($this->getJson('/api/v1/customer/posts')->json('data'))->toHaveCount(6);
    expect($this->getJson('/api/v1/customer/posts?limit=3')->json('data'))->toHaveCount(3);
    expect($this->getJson('/api/v1/customer/posts?limit=100')->json('data'))->toHaveCount(20);
    expect($this->getJson('/api/v1/customer/posts?limit=0')->json('data'))->toHaveCount(1);
});

// =========================================================================
//  Response shape
// =========================================================================

it('returns expected fields per post', function () {
    Post::factory()->create([
        'organization_id' => $this->orgId,
        'slug' => 'shape-check',
        'status' => PostStatusEnum::Published,
        'published_at' => Carbon::now()->subHour(),
        'is_pinned' => false,
        'cover_image_url' => '/images/cover.jpg',
    ]);

    $response = $this->getJson('/api/v1/customer/posts');

    $response->assertOk()->assertJsonStructure([
        'data' => [
            '*' => [
                'id',
                'slug',
                'title',
                'excerpt',
                'cover_image_url',
                'published_at',
                'is_pinned',
            ],
        ],
    ]);

    expect($response->json('data.0'))->not->toHaveKey('brand');
});
