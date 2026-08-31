<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Notifications\Customer\VerifyCustomerEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * #1680 — tài khoản chỉ dùng được sau khi khách bấm link xác nhận trong thư.
 *
 * File này giữ đúng cái cổng đó: đăng nhập bị chặn khi chưa xác nhận, link
 * xác nhận trả khách về Customer Web, và endpoint gửi lại không nói cho người
 * lạ biết địa chỉ nào đã đăng ký.
 */
function signedVerificationUrl(Customer $customer, array $extra = []): string
{
    return URL::temporarySignedRoute(
        'api.v1.customer.verification.verify',
        now()->addMinutes(60),
        [
            'id' => $customer->getKey(),
            'hash' => sha1((string) $customer->email),
            ...$extra,
        ],
    );
}

// =============================================================================
// Cổng đăng nhập
// =============================================================================

it('blocks login while the email is not verified', function () {
    Customer::factory()->selfRegistered()->create([
        'email' => 'chua-xac-nhan@example.com',
        'password' => 'password123',
        'email_verified_at' => null,
    ]);

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'chua-xac-nhan@example.com',
        'password' => 'password123',
        'device_name' => 'test',
    ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'email_not_verified')
        ->assertJsonPath('email', 'chua-xac-nhan@example.com');

    $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_type' => 'Customer']);
});

// Mật khẩu sai phải ra "sai thông tin đăng nhập" như trước, KHÔNG ra
// "chưa xác nhận" — nếu không thì endpoint này thành máy dò tài khoản.
it('does not reveal the verification state when the password is wrong', function () {
    Customer::factory()->selfRegistered()->create([
        'email' => 'chua-xac-nhan@example.com',
        'password' => 'password123',
        'email_verified_at' => null,
    ]);

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'chua-xac-nhan@example.com',
        'password' => 'sai-mat-khau',
        'device_name' => 'test',
    ])->assertStatus(422);
});

it('lets a verified customer log in', function () {
    Customer::factory()->selfRegistered()->create([
        'email' => 'da-xac-nhan@example.com',
        'password' => 'password123',
        'email_verified_at' => now(),
    ]);

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'da-xac-nhan@example.com',
        'password' => 'password123',
        'device_name' => 'test',
    ])
        ->assertOk()
        ->assertJsonStructure(['data' => ['user' => ['id', 'email'], 'token']]);
});

// Vòng đầy đủ: đăng ký → bấm link → đăng nhập được. Đây là thứ khách thật đi.
it('opens login after the customer follows the verification link', function () {
    Branch::factory()->create(['slug' => 'shibuya']);

    // SĐT bắt buộc + mật khẩu theo chính sách mới kể từ #1780.
    $this->postJson('/api/v1/customer/auth/register', [
        'first_name' => 'Vong',
        'email' => 'vong@example.com',
        'phone' => '0912345678',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'device_name' => 'test',
        'branch_slug' => 'shibuya',
    ])->assertAccepted();

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'vong@example.com',
        'password' => 'Password123!',
        'device_name' => 'test',
    ])->assertStatus(403);

    $customer = Customer::where('email', 'vong@example.com')->firstOrFail();
    $this->get(signedVerificationUrl($customer))->assertOk();

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'vong@example.com',
        'password' => 'Password123!',
        'device_name' => 'test',
    ])->assertOk();
});

// =============================================================================
// Link xác nhận
// =============================================================================

it('marks the email verified when the signed link is followed', function () {
    $customer = Customer::factory()->selfRegistered()->create(['email_verified_at' => null]);

    $this->get(signedVerificationUrl($customer))->assertOk();

    expect($customer->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('redirects back to customer web with the shop and locale carried in the link', function () {
    Config::set('customer.web_url', 'https://khach.example.com');
    $customer = Customer::factory()->selfRegistered()->create(['email_verified_at' => null]);

    $this->get(signedVerificationUrl($customer, ['locale' => 'vi', 'shop' => 'ginza']))
        ->assertRedirect('https://khach.example.com/vi/login/ginza?verified=ok');

    expect($customer->fresh()->hasVerifiedEmail())->toBeTrue();
});

// Khách đăng ký từ trước #1505 không gắn cửa hàng nào — không được dựng ra
// một URL /login/ cụt, phải đưa về chỗ chọn cửa hàng.
it('redirects to select-branch when the link carries no shop', function () {
    Config::set('customer.web_url', 'https://khach.example.com');
    $customer = Customer::factory()->selfRegistered()->create(['email_verified_at' => null]);

    $this->get(signedVerificationUrl($customer, ['locale' => 'ja']))
        ->assertRedirect('https://khach.example.com/ja/select-branch?verified=ok&next=login');
});

it('reports an expired link as expired, not as broken', function () {
    Config::set('customer.web_url', 'https://khach.example.com');
    $customer = Customer::factory()->selfRegistered()->create(['email_verified_at' => null]);

    $url = signedVerificationUrl($customer, ['locale' => 'ja', 'shop' => 'ginza']);

    Carbon::setTestNow(now()->addMinutes(61));
    $this->get($url)->assertRedirect('https://khach.example.com/ja/login/ginza?verified=expired');
    Carbon::setTestNow();

    expect($customer->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('refuses a link whose signature was tampered with', function () {
    Config::set('customer.web_url', 'https://khach.example.com');
    $customer = Customer::factory()->selfRegistered()->create(['email_verified_at' => null]);

    $url = signedVerificationUrl($customer, ['locale' => 'ja', 'shop' => 'ginza']);

    $this->get($url.'x')->assertRedirect('https://khach.example.com/ja/login/ginza?verified=invalid');

    expect($customer->fresh()->hasVerifiedEmail())->toBeFalse();
});

// Chữ ký hỏng nghĩa là locale/shop trong query cũng là dữ liệu người lạ —
// không được ghép thẳng vào đường dẫn trả về.
it('does not let a broken link steer the redirect path', function () {
    Config::set('customer.web_url', 'https://khach.example.com');
    $customer = Customer::factory()->selfRegistered()->create(['email_verified_at' => null]);

    $this->get(sprintf(
        '/api/v1/customer/auth/verify/%s/%s?signature=deadbeef&locale=%s&shop=%s',
        $customer->getKey(),
        sha1((string) $customer->email),
        urlencode('../../evil.example.com'),
        urlencode('a/../../b'),
    ))->assertRedirect('https://khach.example.com/ja/select-branch?verified=invalid&next=login');
});

it('says already-verified instead of failing when the link is used twice', function () {
    Config::set('customer.web_url', 'https://khach.example.com');
    $customer = Customer::factory()->selfRegistered()->create(['email_verified_at' => null]);

    $url = signedVerificationUrl($customer, ['locale' => 'en', 'shop' => 'ginza']);

    $this->get($url)->assertRedirect('https://khach.example.com/en/login/ginza?verified=ok');
    $this->get($url)->assertRedirect('https://khach.example.com/en/login/ginza?verified=already');
});

// Máy dev / test không cắm Customer Web: giữ nguyên câu trả lời JSON thay vì
// chuyển hướng vào một URL rỗng.
it('answers in JSON when no customer web url is configured', function () {
    Config::set('customer.web_url', '');
    $customer = Customer::factory()->selfRegistered()->create(['email_verified_at' => null]);

    $this->get(signedVerificationUrl($customer))
        ->assertOk()
        ->assertJsonPath('status', 'ok');
});

// =============================================================================
// Gửi lại thư
// =============================================================================

it('resends the verification email without any token', function () {
    Notification::fake();
    $customer = Customer::factory()->selfRegistered()->create([
        'email' => 'gui-lai@example.com',
        'email_verified_at' => null,
    ]);

    $this->postJson('/api/v1/customer/auth/email/resend', ['email' => 'gui-lai@example.com'])
        ->assertOk();

    Notification::assertSentTo($customer, VerifyCustomerEmail::class);
});

it('does not resend to an address that is already verified', function () {
    Notification::fake();
    Customer::factory()->selfRegistered()->create([
        'email' => 'xong-roi@example.com',
        'email_verified_at' => now(),
    ]);

    $this->postJson('/api/v1/customer/auth/email/resend', ['email' => 'xong-roi@example.com'])
        ->assertOk();

    Notification::assertNothingSent();
});

// Endpoint công khai: câu trả lời cho địa chỉ không tồn tại phải giống hệt câu
// trả lời cho địa chỉ có thật, nếu không nó thành máy dò danh sách khách.
it('answers the same for an address that never registered', function () {
    Notification::fake();
    $known = $this->postJson('/api/v1/customer/auth/email/resend', ['email' => 'nguoi-la@example.com']);

    Customer::factory()->selfRegistered()->create([
        'email' => 'co-that@example.com',
        'email_verified_at' => null,
    ]);
    $unknown = $this->postJson('/api/v1/customer/auth/email/resend', ['email' => 'co-that@example.com']);

    expect($known->status())->toBe($unknown->status())
        ->and($known->json('message'))->toBe($unknown->json('message'));

    Notification::assertSentTimes(VerifyCustomerEmail::class, 1);
});
