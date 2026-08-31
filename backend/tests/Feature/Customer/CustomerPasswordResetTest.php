<?php

use App\Models\Customer;
use App\Notifications\Customer\ResetCustomerPassword;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/*
 * #1783 — luồng quên / đặt lại mật khẩu của khách.
 *
 * Tính chất quan trọng nhất KHÔNG phải "đặt lại được mật khẩu" — mà là **form
 * này không được trở thành máy dò tài khoản**. Một endpoint công khai trả lời
 * khác nhau cho địa chỉ có và không có tài khoản là cách rẻ nhất để liệt kê
 * khách hàng của một quán.
 */

beforeEach(function () {
    Notification::fake();
    Config::set('customer.web_url', 'https://khach.example.test');
});

function resetAccount(array $attrs = []): Customer
{
    return Customer::factory()->create(array_merge([
        'email' => 'khach@example.test',
        'password' => 'MatKhauCu#12345',
        'email_verified_at' => now(),
    ], $attrs));
}

function issuedToken(Customer $account): string
{
    return Password::broker('customer_accounts')->getRepository()->create($account);
}

it('#1783 địa chỉ CÓ và KHÔNG có tài khoản trả về CÙNG một phản hồi', function () {
    resetAccount();

    $withAccount = $this->postJson('/api/v1/customer/auth/password/forgot', ['email' => 'khach@example.test']);
    $withoutAccount = $this->postJson('/api/v1/customer/auth/password/forgot', ['email' => 'khonghe@example.test']);

    expect($withAccount->status())->toBe($withoutAccount->status())
        ->and($withAccount->json('message'))->toBe($withoutAccount->json('message'));
});

it('#1783 chỉ gửi thư cho địa chỉ THẬT SỰ có tài khoản', function () {
    // Phản hồi giống nhau, nhưng hành vi bên trong thì không — nếu không thì
    // test trên chỉ chứng minh "hai endpoint đều hỏng giống nhau".
    $account = resetAccount();

    $this->postJson('/api/v1/customer/auth/password/forgot', ['email' => 'khonghe@example.test']);
    Notification::assertNothingSent();

    $this->postJson('/api/v1/customer/auth/password/forgot', ['email' => $account->email]);
    Notification::assertSentTo($account, ResetCustomerPassword::class);
});

it('#1783 KHÔNG chọn hàng CRM không mật khẩu che mất tài khoản thật', function () {
    // `customers` là bảng đa-tenant dùng chung, `email` nullable và KHÔNG unique.
    // `login()` đã né bẫy này bằng `whereNotNull('password')`; nếu đường đặt lại
    // mật khẩu đi qua provider mặc định của broker thì nó tra email trần và có
    // thể trúng hàng CRM — khách sẽ không bao giờ nhận được thư.
    Customer::factory()->create(['email' => 'chung@example.test', 'password' => null]);
    $real = resetAccount(['email' => 'chung@example.test']);

    $this->postJson('/api/v1/customer/auth/password/forgot', ['email' => 'chung@example.test']);

    Notification::assertSentTo($real, ResetCustomerPassword::class);
});

it('#1783 token hợp lệ đổi được mật khẩu', function () {
    $account = resetAccount();
    $token = issuedToken($account);

    $this->postJson('/api/v1/customer/auth/password/reset', [
        'token' => $token,
        'email' => $account->email,
        'password' => 'MatKhauMoi#67890',
        'password_confirmation' => 'MatKhauMoi#67890',
    ])->assertOk();

    expect(Hash::check('MatKhauMoi#67890', $account->fresh()->password))->toBeTrue();
});

it('#1783 token chỉ dùng được MỘT lần', function () {
    $account = resetAccount();
    $token = issuedToken($account);
    $payload = [
        'token' => $token,
        'email' => $account->email,
        'password' => 'MatKhauMoi#67890',
        'password_confirmation' => 'MatKhauMoi#67890',
    ];

    $this->postJson('/api/v1/customer/auth/password/reset', $payload)->assertOk();
    $this->postJson('/api/v1/customer/auth/password/reset', $payload)->assertStatus(422);
});

it('#1783 token SAI và địa chỉ KHÔNG tồn tại cho cùng một lỗi', function () {
    $account = resetAccount();

    $badToken = $this->postJson('/api/v1/customer/auth/password/reset', [
        'token' => 'khong-phai-token', 'email' => $account->email,
        'password' => 'MatKhauMoi#67890', 'password_confirmation' => 'MatKhauMoi#67890',
    ]);
    $noAccount = $this->postJson('/api/v1/customer/auth/password/reset', [
        'token' => 'khong-phai-token', 'email' => 'khonghe@example.test',
        'password' => 'MatKhauMoi#67890', 'password_confirmation' => 'MatKhauMoi#67890',
    ]);

    expect($badToken->status())->toBe($noAccount->status())
        ->and($badToken->json('errors'))->toBe($noAccount->json('errors'));
});

it('#1783 đặt lại mật khẩu ĐÓNG DẤU đã xác nhận email (#1680)', function () {
    // Bấm được link trong thư chứng minh quyền kiểm soát hòm thư, đúng bằng thứ
    // link xác nhận chứng minh. Không đóng dấu thì khách vừa đặt lại xong VẪN
    // không đăng nhập được, mà thư xác nhận cũ thì đã trôi mất — ngõ cụt.
    $account = resetAccount(['email_verified_at' => null]);
    $token = issuedToken($account);

    $this->postJson('/api/v1/customer/auth/password/reset', [
        'token' => $token, 'email' => $account->email,
        'password' => 'MatKhauMoi#67890', 'password_confirmation' => 'MatKhauMoi#67890',
    ])->assertOk();

    expect($account->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('#1783 đặt lại mật khẩu GIẾT mọi phiên cũ', function () {
    // Kịch bản của luồng này là "có thể ai đó đã vào được tài khoản".
    $account = resetAccount();
    $account->createToken('thiet-bi-cu');
    expect($account->tokens()->count())->toBe(1);

    $token = issuedToken($account);
    $this->postJson('/api/v1/customer/auth/password/reset', [
        'token' => $token, 'email' => $account->email,
        'password' => 'MatKhauMoi#67890', 'password_confirmation' => 'MatKhauMoi#67890',
    ])->assertOk();

    expect($account->tokens()->count())->toBe(0);
});

it('#1783 KHÔNG gửi thư khi customer.web_url chưa cấu hình', function () {
    // Thư chứa link hỏng tệ hơn không gửi: khách bấm, không tới đâu, và kết luận
    // hệ thống hỏng chứ không nghĩ tới cấu hình thiếu.
    Config::set('customer.web_url', '');
    $account = resetAccount();

    $this->postJson('/api/v1/customer/auth/password/forgot', ['email' => $account->email])->assertOk();

    Notification::assertNothingSent();
});

it('#1783 link trong thư trỏ về CUSTOMER-WEB kèm token, email và locale', function () {
    $account = resetAccount();

    $url = ResetCustomerPassword::customerWebResetUrl($account, 'token-mau');

    expect($url)->toStartWith('https://khach.example.test/')
        ->and($url)->toContain('/reset-password?')
        ->and($url)->toContain('token=token-mau')
        ->and($url)->toContain(urlencode((string) $account->email))
        ->and($url)->toContain('locale=');
});

it('#1783 đặt lại mật khẩu dùng ĐÚNG luật mạnh của đăng ký', function () {
    // Bản đầu dùng `Password::defaults()` (tối thiểu 8) trong khi đăng ký dùng
    // `StrongCustomerPassword` (10 + hoa + chữ-và-số + ký tự đặc biệt). Đo được:
    // `abcd1234` qua endpoint đặt lại, HTTP 200. Luồng sinh ra để CỨU tài khoản
    // hoá ra là đường hạ cấp mật khẩu của nó.
    $account = resetAccount();
    $token = issuedToken($account);

    $this->postJson('/api/v1/customer/auth/password/reset', [
        'token' => $token, 'email' => $account->email,
        'password' => 'abcd1234', 'password_confirmation' => 'abcd1234',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);
});
