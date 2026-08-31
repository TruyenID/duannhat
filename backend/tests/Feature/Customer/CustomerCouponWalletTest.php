<?php

/**
 * #1441 — ví coupon của khách + trang FAQ.
 *
 *   GET /api/v1/customer/me/coupons        coupon cá nhân + lịch sử đã dùng
 *   GET /api/v1/customer/posts?category=   nguồn của trang Câu hỏi thường gặp
 *   GET /api/v1/customer/posts/{slug}      một bài, kèm nội dung
 *
 * Cộng luật SỞ HỮU: coupon đổi từ điểm chỉ đúng chủ dùng được.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PointReward;
use App\Models\Post;
use App\Models\PostCategory;
use App\Omnify\Enums\CouponStatusEnum;
use App\Omnify\Enums\PostStatusEnum;
use App\Services\Promotion\CouponService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->customer = Customer::factory()->selfRegistered()->create();
    $this->token = $this->customer->createToken('test')->plainTextToken;
});

function personalCoupon(Customer $owner, array $attrs = []): Coupon
{
    return Coupon::factory()->create([
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
        'customer_id' => $owner->id,
        'code' => 'PT'.strtoupper(Str::random(8)),
        'discount_type' => 'fixed',
        'discount_value' => 500,
        'max_discount_cap' => null,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 1,
        'usage_limit_per_customer' => 1,
        'times_used' => 0,
        'status' => CouponStatusEnum::Draft,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(30),
        ...$attrs,
    ]);
}

// =============================================================================
// Ví coupon
// =============================================================================

it('trả về coupon cá nhân của khách đang đăng nhập', function () {
    personalCoupon($this->customer);

    $this->withToken($this->token)
        ->getJson('/api/v1/customer/me/coupons')
        ->assertOk()
        ->assertJsonCount(1, 'data.available')
        ->assertJsonCount(0, 'data.expired')
        ->assertJsonPath('data.available.0.from_point_reward', false);
});

it('không trả về coupon cá nhân của khách khác', function () {
    personalCoupon(Customer::factory()->selfRegistered()->create());

    $this->withToken($this->token)
        ->getJson('/api/v1/customer/me/coupons')
        ->assertOk()
        ->assertJsonCount(0, 'data.available');
});

it('không liệt kê mã khuyến mãi công khai', function () {
    // Mã HQ phát công khai (customer_id null) KHÔNG được xuất hiện trong ví:
    // một endpoint trả về mọi mã còn hiệu lực là một endpoint phát tán mã.
    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'customer_id' => null,
        'code' => 'WELCOME10',
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(30),
        'times_used' => 0,
    ]);

    $this->withToken($this->token)
        ->getJson('/api/v1/customer/me/coupons')
        ->assertOk()
        ->assertJsonCount(0, 'data.available');
});

it('xếp coupon hết hạn hoặc đã tiêu hết lượt vào nhóm expired', function () {
    personalCoupon($this->customer, ['valid_until' => now()->subDay()]);
    personalCoupon($this->customer, ['times_used' => 1, 'usage_limit_total' => 1]);

    $this->withToken($this->token)
        ->getJson('/api/v1/customer/me/coupons')
        ->assertOk()
        ->assertJsonCount(0, 'data.available')
        ->assertJsonCount(2, 'data.expired');
});

// =============================================================================
// Sở hữu — coupon cá nhân không phải vé vô danh
// =============================================================================

it('từ chối khi khách khác dùng coupon cá nhân', function () {
    $coupon = personalCoupon($this->customer);
    $stranger = Customer::factory()->selfRegistered()->create();

    $result = app(CouponService::class)->preview(
        $coupon->code,
        $this->brand->id,
        $this->branch->id,
        $stranger->id,
        10000,
    );

    expect($result['is_valid'])->toBeFalse()
        ->and($result['error_code'])->toBe('coupon_not_yours');
});

it('cho phép đúng chủ dùng coupon cá nhân', function () {
    $coupon = personalCoupon($this->customer);

    $result = app(CouponService::class)->preview(
        $coupon->code,
        $this->brand->id,
        $this->branch->id,
        $this->customer->id,
        10000,
    );

    expect($result['is_valid'])->toBeTrue();
});

it('từ chối khi không xác định được khách', function () {
    $coupon = personalCoupon($this->customer);

    $result = app(CouponService::class)->preview(
        $coupon->code,
        $this->brand->id,
        $this->branch->id,
        null,
        10000,
    );

    expect($result['is_valid'])->toBeFalse()
        ->and($result['error_code'])->toBe('customer_required');
});

it('giấu coupon đổi-từ-điểm khỏi danh sách quản trị của HQ', function () {
    $reward = PointReward::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'discount_type' => 'fixed',
        'max_discount_cap' => null,
    ]);
    personalCoupon($this->customer, ['point_reward_id' => $reward->id]);
    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'HQCAMPAIGN',
        'point_reward_id' => null,
    ]);

    $service = app(CouponService::class);

    expect($service->list(['brand_id' => $this->brand->id])->total())->toBe(1)
        ->and($service->list(['brand_id' => $this->brand->id, 'include_point_rewards' => true])->total())->toBe(2);
});

// =============================================================================
// FAQ (posts)
// =============================================================================

it('lọc bài viết theo category và kèm nội dung cho trang FAQ', function () {
    $faq = PostCategory::factory()->create(['slug' => 'faq', 'organization_id' => $this->orgId]);
    $news = PostCategory::factory()->create(['slug' => 'news', 'organization_id' => $this->orgId]);

    Post::factory()->count(2)->create([
        'organization_id' => $this->orgId,
        'category_id' => $faq->id,
        'status' => PostStatusEnum::Published,
        'published_at' => now()->subDay(),
        'content' => 'Câu trả lời đầy đủ',
    ]);
    Post::factory()->create([
        'organization_id' => $this->orgId,
        'category_id' => $news->id,
        'status' => PostStatusEnum::Published,
        'published_at' => now()->subDay(),
    ]);

    $this->getJson('/api/v1/customer/posts?category=faq&with_content=1&limit=50')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.content', 'Câu trả lời đầy đủ');
});

it('không trả nội dung khi không lọc theo category', function () {
    // Chặn `?limit=100&with_content=1` biến endpoint công khai thành lệnh dump
    // toàn bộ nội dung site.
    Post::factory()->create([
        'organization_id' => $this->orgId,
        'status' => PostStatusEnum::Published,
        'published_at' => now()->subDay(),
        'content' => 'bí mật',
    ]);

    $this->getJson('/api/v1/customer/posts?with_content=1')
        ->assertOk()
        ->assertJsonMissingPath('data.0.content');
});

it('trả một bài viết theo slug kèm nội dung', function () {
    $post = Post::factory()->create([
        'organization_id' => $this->orgId,
        'slug' => 'gio-mo-cua',
        'status' => PostStatusEnum::Published,
        'published_at' => now()->subDay(),
        'content' => 'Mở 9:00 – 22:00',
    ]);

    $this->getJson('/api/v1/customer/posts/'.$post->slug)
        ->assertOk()
        ->assertJsonPath('data.content', 'Mở 9:00 – 22:00');
});

it('trả 404 cho bài chưa xuất bản dù đoán trúng slug', function () {
    $post = Post::factory()->create([
        'organization_id' => $this->orgId,
        'slug' => 'ban-nhap',
        'published_at' => null,
    ]);

    $this->getJson('/api/v1/customer/posts/'.$post->slug)->assertNotFound();
});

it('trả 404 cho bài hẹn giờ chưa tới lúc', function () {
    $post = Post::factory()->create([
        'organization_id' => $this->orgId,
        'slug' => 'hen-gio',
        'status' => PostStatusEnum::Published,
        'published_at' => now()->addDays(3),
    ]);

    $this->getJson('/api/v1/customer/posts/'.$post->slug)->assertNotFound();
});
