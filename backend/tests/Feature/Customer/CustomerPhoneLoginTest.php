<?php

use App\Console\Commands\SurveyDuplicateCustomerPhones;
use App\Models\Customer;
use App\Services\Customer\CustomerLoginIdentifier;
use Illuminate\Testing\TestResponse;

/*
 * #1782 — đăng nhập bằng SỐ ĐIỆN THOẠI.
 *
 * Tính chất phải giữ bằng mọi giá: **không bao giờ xác thực một định danh mơ
 * hồ**. `customers.phone` không unique, nên một số có thể ứng với nhiều tài
 * khoản; chọn bừa hàng đầu tiên nghĩa là có thể đăng nhập vào tài khoản NGƯỜI
 * KHÁC nếu mật khẩu tình cờ khớp.
 */

function phoneAccount(array $attrs = []): Customer
{
    return Customer::factory()->create(array_merge([
        'email' => 'khach'.uniqid().'@example.test',
        'phone' => '0901234567',
        'password' => 'MatKhau#12345',
        'email_verified_at' => now(),
    ], $attrs));
}

function loginWith(array $payload): TestResponse
{
    return test()->postJson('/api/v1/customer/auth/login', array_merge([
        'password' => 'MatKhau#12345',
        'device_name' => 'test',
    ], $payload));
}

it('#1782 đăng nhập bằng EMAIL vẫn y như cũ', function () {
    // Hợp đồng cũ không được vỡ: bản customer-web đang chạy ngoài kia vẫn gửi
    // `email`, và backend deploy TRƯỚC frontend.
    $account = phoneAccount();

    loginWith(['email' => $account->email])->assertOk();
});

it('#1782 đăng nhập bằng SỐ ĐIỆN THOẠI', function () {
    phoneAccount(['phone' => '0901234567']);

    loginWith(['identifier' => '0901234567'])->assertOk();
});

it('#1782 số gõ ở nhiều DẠNG khác nhau đều vào được', function (string $typed) {
    // Khách không có cách nào đoán ra kiểu viết mà ai đó đã nhập lúc tạo hồ sơ.
    phoneAccount(['phone' => '0901234567']);

    loginWith(['identifier' => $typed])->assertOk();
})->with(['0901234567', '090 1234 567', '090-1234-567', '+84901234567']);

it('#1782 SỐ TRÙNG thì TỪ CHỐI, không chọn bừa một tài khoản', function () {
    // Đây là ca mà issue để treo. Chọn hàng đầu tiên = có thể đăng nhập vào tài
    // khoản người khác nếu mật khẩu tình cờ khớp.
    phoneAccount(['phone' => '0901234567']);
    phoneAccount(['phone' => '0901234567']);

    loginWith(['identifier' => '0901234567'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['identifier']);
});

it('#1782 số trùng nhưng chỉ MỘT hàng đăng nhập được thì vẫn vào', function () {
    // Hàng CRM không mật khẩu trùng số là chuyện bình thường và vô hại — gộp
    // chúng vào phép đếm mơ hồ sẽ khoá oan những khách hoàn toàn hợp lệ.
    Customer::factory()->create(['phone' => '0901234567', 'password' => null]);
    phoneAccount(['phone' => '0901234567']);

    loginWith(['identifier' => '0901234567'])->assertOk();
});

it('#1782 thông điệp số-trùng nói THẲNG phải dùng email', function () {
    // Nếu chỉ báo "sai mật khẩu" thì nhóm khách này không đời nào đoán ra lý do.
    phoneAccount(['phone' => '0901234567']);
    phoneAccount(['phone' => '0901234567']);

    $message = loginWith(['identifier' => '0901234567'])->json('errors.identifier.0');

    expect($message)->toBe(__('auth.phone_ambiguous'))
        ->and(strtolower((string) $message))->toContain('email');
});

it('#1782 KHÔNG đoán mã quốc gia từ số không có dấu +', function () {
    // `84…` có thể là mã quốc gia VN, mà cũng có thể là số nội địa Nhật bắt đầu
    // bằng 84. Đoán sai ở đây là đăng nhập trúng tài khoản người khác.
    $variants = CustomerLoginIdentifier::phoneVariants('84901234567');

    expect($variants)->not->toContain('0901234567');
});

it('#1782 lệnh khảo sát đếm ĐÚNG hàng đăng nhập được', function () {
    Customer::factory()->create(['phone' => '0908888888', 'password' => null]);
    Customer::factory()->create(['phone' => '0908888888', 'password' => null]);
    phoneAccount(['phone' => '0909999999']);
    phoneAccount(['phone' => '0909999999']);

    $rows = app(SurveyDuplicateCustomerPhones::class)->exactDuplicates();
    $phones = array_column($rows, 'phone');

    // Hai hàng CRM trùng số KHÔNG phải vấn đề — không ai đăng nhập bằng chúng.
    expect($phones)->not->toContain('0908888888')
        ->and($phones)->toContain('0909999999');
});

it('#1782 email vẫn phân biệt được với số, không nhầm nhánh', function () {
    expect(CustomerLoginIdentifier::parse('a@b.test')->isEmail)->toBeTrue()
        ->and(CustomerLoginIdentifier::parse('0901234567')->isEmail)->toBeFalse();
});
