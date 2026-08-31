<?php

use App\Models\Customer;
use App\Notifications\Customer\VerifyCustomerEmail;
use App\Services\Customer\EmailVerificationCodeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * Xác nhận email bằng MÃ 6 CHỮ SỐ.
 *
 * Thư không còn mang link: khách gõ mã vào chính trang đang mở. File này giữ
 * cái cổng đó — mã phải đúng, phải còn hạn, phải dùng một lần, và endpoint
 * công khai này không được biến thành máy dò xem địa chỉ nào đã đăng ký.
 */

/** Lấy mã plaintext ra khỏi thư vừa gửi — đúng thứ khách đọc trong Gmail. */
function issuedCodeFor(Customer $customer): string
{
    return app(EmailVerificationCodeService::class)->issue($customer);
}

function unverifiedCustomer(string $email = 'chua-xac-nhan@example.com'): Customer
{
    return Customer::factory()->selfRegistered()->create([
        'email' => $email,
        'password' => 'password123',
        'email_verified_at' => null,
    ]);
}

// =============================================================================
// Đường hạnh phúc
// =============================================================================

it('verifies the email when the code is correct', function () {
    $customer = unverifiedCustomer();
    $code = issuedCodeFor($customer);

    $this->postJson('/api/v1/customer/auth/email/verify-code', [
        'email' => 'chua-xac-nhan@example.com',
        'code' => $code,
    ])
        ->assertOk()
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.status', 'ok');

    expect($customer->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('lets the customer log in right after verifying with the code', function () {
    $customer = unverifiedCustomer();
    $code = issuedCodeFor($customer);

    $this->postJson('/api/v1/customer/auth/email/verify-code', [
        'email' => $customer->email,
        'code' => $code,
    ])->assertOk();

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => $customer->email,
        'password' => 'password123',
        'device_name' => 'test',
    ])->assertOk()->assertJsonStructure(['data' => ['token']]);
});

// Mã 0 đứng đầu (`012345`) là khoảng 10% số mã. Nếu ở đâu đó nó bị ép về số thì
// những khách đó gõ đúng mã vẫn trượt — và không ai tái hiện được vì mã ngẫu nhiên.
it('accepts a code with leading zeros', function () {
    $customer = unverifiedCustomer();

    Cache::put('customer:verify-code:'.$customer->getKey(), [
        'hash' => Hash::make('012345'),
        'attempts' => 0,
        'expires_at' => now()->addMinutes(10)->getTimestamp(),
    ], now()->addMinutes(10));

    $this->postJson('/api/v1/customer/auth/email/verify-code', [
        'email' => $customer->email,
        'code' => '012345',
    ])->assertOk();

    expect($customer->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('reports an already verified address without failing', function () {
    $customer = Customer::factory()->selfRegistered()->create([
        'email' => 'da-xac-nhan@example.com',
        'password' => 'password123',
        'email_verified_at' => now(),
    ]);

    $this->postJson('/api/v1/customer/auth/email/verify-code', [
        'email' => $customer->email,
        'code' => '123456',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'already');
});

// =============================================================================
// Mã sai / hết hạn / dùng lại
// =============================================================================

it('rejects a wrong code and leaves the email unverified', function () {
    $customer = unverifiedCustomer();
    $code = issuedCodeFor($customer);

    $this->postJson('/api/v1/customer/auth/email/verify-code', [
        'email' => $customer->email,
        'code' => $code === '000000' ? '111111' : '000000',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'invalid')
        ->assertJsonValidationErrors(['code']);

    expect($customer->fresh()->hasVerifiedEmail())->toBeFalse();
});

// Mã dùng một lần. Để nó sống tiếp sau khi khớp nghĩa là bất kỳ ai đọc được thư
// đó về sau vẫn xác nhận lại được trong suốt phần hạn còn lại.
it('burns the code after a successful verification', function () {
    $customer = unverifiedCustomer();
    $code = issuedCodeFor($customer);

    $this->postJson('/api/v1/customer/auth/email/verify-code', [
        'email' => $customer->email, 'code' => $code,
    ])->assertOk();

    expect(Cache::has('customer:verify-code:'.$customer->getKey()))->toBeFalse();
});

it('rejects a code past its expiry', function () {
    Config::set('customer.verification.code_ttl_minutes', 10);

    $customer = unverifiedCustomer();
    $code = issuedCodeFor($customer);

    Carbon::setTestNow(now()->addMinutes(11));

    $this->postJson('/api/v1/customer/auth/email/verify-code', [
        'email' => $customer->email, 'code' => $code,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'expired');

    Carbon::setTestNow();

    expect($customer->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('kills the code after too many wrong attempts', function () {
    $customer = unverifiedCustomer();
    $code = issuedCodeFor($customer);
    $wrong = $code === '000000' ? '111111' : '000000';

    for ($i = 1; $i < EmailVerificationCodeService::MAX_ATTEMPTS; $i++) {
        $this->postJson('/api/v1/customer/auth/email/verify-code', [
            'email' => $customer->email, 'code' => $wrong,
        ])->assertJsonPath('reason', 'invalid');
    }

    $this->postJson('/api/v1/customer/auth/email/verify-code', [
        'email' => $customer->email, 'code' => $wrong,
    ])->assertJsonPath('reason', 'too_many_attempts');

    // Mã ĐÚNG cũng không còn tác dụng — mã đã bị huỷ, không phải chỉ bị từ chối.
    $this->postJson('/api/v1/customer/auth/email/verify-code', [
        'email' => $customer->email, 'code' => $code,
    ])->assertJsonPath('reason', 'expired');

    expect($customer->fresh()->hasVerifiedEmail())->toBeFalse();
});

// Gõ sai KHÔNG được gia hạn mã. Nếu mỗi lần sai lại đẩy hạn ra thêm 10 phút thì
// một vòng lặp dò mã sẽ giữ nó sống vô tận — đúng thứ mà hạn ngắn sinh ra để chặn.
it('does not extend the expiry on a wrong attempt', function () {
    Config::set('customer.verification.code_ttl_minutes', 10);

    $customer = unverifiedCustomer();
    $code = issuedCodeFor($customer);
    $wrong = $code === '000000' ? '111111' : '000000';

    Carbon::setTestNow(now()->addMinutes(9));

    $this->postJson('/api/v1/customer/auth/email/verify-code', [
        'email' => $customer->email, 'code' => $wrong,
    ])->assertJsonPath('reason', 'invalid');

    Carbon::setTestNow(now()->addMinutes(2));

    $this->postJson('/api/v1/customer/auth/email/verify-code', [
        'email' => $customer->email, 'code' => $code,
    ])->assertJsonPath('reason', 'expired');

    Carbon::setTestNow();
});

// =============================================================================
// Không lộ ai đã đăng ký
// =============================================================================

it('answers an unknown address exactly like a real one with no live code', function () {
    $stranger = $this->postJson('/api/v1/customer/auth/email/verify-code', [
        'email' => 'khong-ton-tai@example.com', 'code' => '123456',
    ]);

    $real = unverifiedCustomer('co-that@example.com');
    $known = $this->postJson('/api/v1/customer/auth/email/verify-code', [
        'email' => $real->email, 'code' => '123456',
    ]);

    expect($stranger->status())->toBe($known->status())
        ->and($stranger->json('reason'))->toBe($known->json('reason'))
        ->and($stranger->json('message'))->toBe($known->json('message'));
});

// =============================================================================
// Validation
// =============================================================================

it('rejects a malformed code without touching the account', function (string $code) {
    $customer = unverifiedCustomer();
    issuedCodeFor($customer);

    $this->postJson('/api/v1/customer/auth/email/verify-code', [
        'email' => $customer->email, 'code' => $code,
    ])->assertUnprocessable()->assertJsonValidationErrors(['code']);
})->with([
    'quá ngắn' => '12345',
    'quá dài' => '1234567',
    'có chữ' => '12a456',
    'rỗng' => '',
]);

it('trims whitespace pasted around the code', function () {
    $customer = unverifiedCustomer();
    $code = issuedCodeFor($customer);

    $this->postJson('/api/v1/customer/auth/email/verify-code', [
        'email' => $customer->email, 'code' => '  '.$code.' ',
    ])->assertOk();
});

// =============================================================================
// Thư mang mã, không mang link
// =============================================================================

it('mails a fresh six-digit code on resend', function () {
    Notification::fake();

    $customer = unverifiedCustomer();

    $this->postJson('/api/v1/customer/auth/email/resend', ['email' => $customer->email])
        ->assertOk();

    Notification::assertSentTo($customer, VerifyCustomerEmail::class, function ($notification) use ($customer) {
        $mail = $notification->toMail($customer);
        $body = collect($mail->introLines)->merge($mail->outroLines)
            ->map(fn ($line) => (string) $line)->implode(' ');

        // Mã hiện ra ở thân thư…
        expect($body)->toMatch('/\b\d{6}\b/')
            // …và KHÔNG còn nút bấm nào: `action()` là thứ dựng link, và một
            // thư mang cả hai đường sẽ khiến khách bấm link thay vì gõ mã.
            ->and($mail->actionUrl)->toBeNull();

        return true;
    });
});

// `issue()` ghi đè mã cũ, nên nếu notification tự phát mã bên trong `toMail()`
// thì mã in ra trong thư sẽ KHÁC mã vừa được lưu — và không mã nào dùng được.
it('mails exactly the code that was stored', function () {
    Notification::fake();

    $customer = unverifiedCustomer();
    $customer->sendEmailVerificationNotification();

    Notification::assertSentTo($customer, VerifyCustomerEmail::class, function ($notification) use ($customer) {
        $body = collect($notification->toMail($customer)->introLines)
            ->map(fn ($line) => (string) $line)->implode(' ');

        preg_match('/\b(\d{6})\b/', $body, $matches);

        return app(EmailVerificationCodeService::class)->verify($customer, $matches[1])
            === EmailVerificationCodeService::RESULT_OK;
    });
});
