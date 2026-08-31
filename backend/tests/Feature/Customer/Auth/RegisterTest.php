<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Organization;
use App\Notifications\Customer\VerifyCustomerEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

// Khách chỉ đăng ký được từ URL có slug cửa hàng (#1505), nên mọi happy path
// dưới đây phải gửi kèm `branch_slug`. Một chi nhánh dùng chung cho cả file;
// test nào cần cấu hình riêng (brand/organization, chi nhánh đã tắt) thì tự
// dựng chi nhánh của nó.
beforeEach(function () {
    Branch::factory()->create(['slug' => 'shibuya']);
});

/**
 * Payload hợp lệ tối thiểu theo luật hiện hành (#1780).
 *
 * Có helper vì luật đăng ký đã đổi hai lần (#1505 thêm `branch_slug`, #1780
 * thêm `phone` + siết mật khẩu) và mỗi lần lại phải sửa hai chục payload chép
 * tay. Test nào đang kiểm một field thì `unset` đúng field đó.
 *
 * `Password123!` thoả cả bốn điều kiện của `StrongCustomerPassword`: ≥10 ký
 * tự, có chữ hoa, có cả chữ lẫn số, có ký tự đặc biệt.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registerPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Taro',
        'email' => 'test@example.com',
        'phone' => '0912345678',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'device_name' => 'iPhone',
        'branch_slug' => 'shibuya',
    ], $overrides);
}

// =============================================================================
// Validation
// =============================================================================

it('rejects missing first_name', function () {
    $payload = registerPayload();
    unset($payload['first_name']);

    $this->postJson('/api/v1/customer/auth/register', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['first_name']);
});

it('rejects first_name over 100 characters', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'first_name' => str_repeat('A', 101),
    ]))->assertUnprocessable()->assertJsonValidationErrors(['first_name']);
});

it('accepts null last_name', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'last_name' => null,
        'email' => 'taro@example.com',
    ]))->assertAccepted();
});

it('rejects last_name over 100 characters', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'last_name' => str_repeat('B', 101),
    ]))->assertUnprocessable()->assertJsonValidationErrors(['last_name']);
});

it('rejects missing email', function () {
    $payload = registerPayload();
    unset($payload['email']);

    $this->postJson('/api/v1/customer/auth/register', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

it('rejects invalid email format', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'not-an-email',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

it('rejects duplicate email', function () {
    Customer::factory()->selfRegistered()->create(['email' => 'dupe@example.com']);

    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'dupe@example.com',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

// =============================================================================
// Số điện thoại (#1780)
// =============================================================================

// Form đăng ký mới bắt buộc SĐT. Trước #1780 field này `nullable`, nên test này
// là chỗ duy nhất ghi lại rằng việc đó đã đổi có chủ đích.
it('rejects a registration with no phone', function () {
    $payload = registerPayload(['email' => 'nophone@example.com']);
    unset($payload['phone']);

    $this->postJson('/api/v1/customer/auth/register', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['phone']);

    $this->assertDatabaseMissing('customers', ['email' => 'nophone@example.com']);
});

// `customers.phone` là VARCHAR(20). Rule cũ cho `max:50`, nên 21-50 ký tự qua
// được validate rồi mới chết ở tầng DB — 500 thay cho một 422 đọc được.
it('rejects a phone longer than the 20-char column', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'longphone@example.com',
        'phone' => str_repeat('0', 21),
    ]))->assertUnprocessable()->assertJsonValidationErrors(['phone']);
});

it('persists the phone number', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'phone@example.com',
        'phone' => '+84912345678',
    ]))->assertAccepted();

    $this->assertDatabaseHas('customers', [
        'email' => 'phone@example.com',
        'phone' => '+84912345678',
    ]);
});

// =============================================================================
// Chính sách mật khẩu (#1780)
// =============================================================================

it('rejects missing password', function () {
    $payload = registerPayload();
    unset($payload['password'], $payload['password_confirmation']);

    $this->postJson('/api/v1/customer/auth/register', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['password']);
});

it('rejects missing password_confirmation', function () {
    $payload = registerPayload();
    unset($payload['password_confirmation']);

    $this->postJson('/api/v1/customer/auth/register', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['password']);
});

it('rejects mismatched password_confirmation', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'password_confirmation' => 'Different123!',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['password']);
});

// Bốn ca dưới đây khớp 1-1 với checklist customer-web vẽ dưới ô mật khẩu. Mỗi
// mật khẩu chỉ trượt ĐÚNG MỘT điều kiện — nếu trượt hai thì test vẫn xanh khi
// một trong hai luật bị gỡ mất.
it('rejects a password that fails exactly one policy rule', function (string $password) {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'weak@example.com',
        'password' => $password,
        'password_confirmation' => $password,
    ]))->assertUnprocessable()->assertJsonValidationErrors(['password']);

    $this->assertDatabaseMissing('customers', ['email' => 'weak@example.com']);
})->with([
    'quá ngắn (9 ký tự, đủ 3 điều kiện còn lại)' => 'Passw1rd!',
    'không có chữ hoa' => 'password123!',
    'không có số' => 'PasswordAbc!',
    'không có ký tự đặc biệt' => 'Password1234',
]);

// Mật khẩu 8 ký tự từng hợp lệ (rule cũ `min:8`) — ghim lại rằng nó KHÔNG còn
// hợp lệ, vì đây chính là thứ mọi test cũ trong file này từng dùng.
it('rejects the old 8-character minimum', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'old@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['password']);
});

// Đếm KÝ TỰ chứ không đếm byte: 10 ký tự tiếng Việt có dấu là ~13-20 byte, nên
// `strlen` vẫn cho qua một mật khẩu 7 ký tự.
it('counts characters, not bytes, for the length rule', function () {
    $password = 'Mậtkhẩu1!'; // 9 ký tự, nhưng dài hơn 10 byte

    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'utf8@example.com',
        'password' => $password,
        'password_confirmation' => $password,
    ]))->assertUnprocessable()->assertJsonValidationErrors(['password']);
});

it('reports every failing policy rule at once', function () {
    $response = $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'password' => 'abc',
        'password_confirmation' => 'abc',
    ]))->assertUnprocessable();

    // ngắn + thiếu hoa + thiếu số + thiếu ký tự đặc biệt = 4 dòng lỗi.
    expect($response->json('errors.password'))->toHaveCount(4);
});

it('rejects missing device_name', function () {
    $payload = registerPayload();
    unset($payload['device_name']);

    $this->postJson('/api/v1/customer/auth/register', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['device_name']);
});

// =============================================================================
// Ngày sinh + giới tính (#1780) — tuỳ chọn, nhưng nhận được lúc đăng ký
// =============================================================================

it('persists birthday and gender when supplied', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'profile@example.com',
        'birthday' => '1990-04-15',
        'gender' => 'male',
    ]))->assertAccepted();

    $customer = Customer::where('email', 'profile@example.com')->first();

    expect($customer->birthday->toDateString())->toBe('1990-04-15')
        ->and($customer->gender->value)->toBe('male');
});

it('registers fine with neither birthday nor gender', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'noprofile@example.com',
    ]))->assertAccepted();

    $this->assertDatabaseHas('customers', [
        'email' => 'noprofile@example.com',
        'birthday' => null,
        'gender' => null,
    ]);
});

// Form HTML gửi ô trống là `""`, không phải `null`. Không normalise thì rule
// `date`/`enum` trượt và khách ăn 422 cho hai trường họ cố ý bỏ trống.
it('treats empty-string birthday and gender as not declared', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'blank@example.com',
        'birthday' => '',
        'gender' => '',
    ]))->assertAccepted();

    $this->assertDatabaseHas('customers', [
        'email' => 'blank@example.com',
        'birthday' => null,
        'gender' => null,
    ]);
});

it('rejects a birthday in the future', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'future@example.com',
        'birthday' => now()->addDay()->toDateString(),
    ]))->assertUnprocessable()->assertJsonValidationErrors(['birthday']);
});

it('rejects a gender outside the enum', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'badgender@example.com',
        'gender' => 'khong-co-that',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['gender']);
});

// =============================================================================
// Chương trình thành viên (#1780)
// =============================================================================

it('stores the membership choice when the customer opts out', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'optout@example.com',
        'loyalty_opted_in' => false,
    ]))->assertAccepted();

    $this->assertDatabaseHas('customers', [
        'email' => 'optout@example.com',
        'loyalty_opted_in' => false,
    ]);
});

it('stores the membership choice when the customer opts in', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'optin@example.com',
        'loyalty_opted_in' => true,
    ]))->assertAccepted();

    $this->assertDatabaseHas('customers', [
        'email' => 'optin@example.com',
        'loyalty_opted_in' => true,
    ]);
});

// Client cũ (và mọi caller không phải form đăng ký) không biết field này. Rơi
// về `true` là hành vi y hệt trước khi có cột — mọi khách đều tích điểm.
it('defaults membership to opted-in when the field is absent', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'legacy@example.com',
    ]))->assertAccepted();

    $this->assertDatabaseHas('customers', [
        'email' => 'legacy@example.com',
        'loyalty_opted_in' => true,
    ]);
});

// =============================================================================
// Happy path
// =============================================================================

// #1680 — đăng ký KHÔNG còn phát token. Trả 202 + địa chỉ email để client hiện
// màn "hãy kiểm tra hộp thư", việc còn lại nằm ở link trong thư.
it('registers a customer and returns 202 without a token', function () {
    $response = $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'first_name' => 'Yamada',
        'email' => 'yamada@example.com',
    ]));

    $response->assertAccepted()
        ->assertJsonPath('data.email', 'yamada@example.com')
        ->assertJsonPath('data.verification_required', true)
        ->assertJsonMissingPath('data.token');
});

it('persists customer to the database', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'first_name' => 'Tanaka',
        'email' => 'tanaka@example.com',
    ]))->assertAccepted();

    $this->assertDatabaseHas('customers', ['email' => 'tanaka@example.com']);
});

it('stores hashed password (Hash::check returns true) and not plain text', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'first_name' => 'Hash',
        'email' => 'hash@example.com',
        'password' => 'Plaintext123!',
        'password_confirmation' => 'Plaintext123!',
    ]))->assertAccepted();

    $customer = Customer::where('email', 'hash@example.com')->first();
    expect($customer->password)->not->toBe('Plaintext123!')
        ->and(Hash::check('Plaintext123!', $customer->password))->toBeTrue()
        ->and(Hash::check('Wrong-pass1!', $customer->password))->toBeFalse();
});

// Cái này là bản đảo của test cũ ("tạo token mang tên device_name"): cổng
// #1680 chỉ có nghĩa nếu đăng ký KHÔNG để lại phiên nào dùng được.
it('creates no access token at all on register', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'first_name' => 'Token',
        'email' => 'token@example.com',
        'device_name' => 'My iPad',
    ]))->assertAccepted();

    $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'My iPad']);
    expect(Customer::where('email', 'token@example.com')->first()->tokens()->count())->toBe(0);
});

it('leaves the new account unverified', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'first_name' => 'Unverified',
        'email' => 'unverified@example.com',
    ]))->assertAccepted();

    $this->assertDatabaseHas('customers', [
        'email' => 'unverified@example.com',
        'email_verified_at' => null,
    ]);
});

it('sends the verification email on register', function () {
    Notification::fake();

    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'first_name' => 'Mail',
        'email' => 'mail@example.com',
    ]))->assertAccepted();

    Notification::assertSentTo(
        Customer::where('email', 'mail@example.com')->first(),
        VerifyCustomerEmail::class,
    );
});

// =============================================================================
// Cửa hàng đăng ký (#1505)
// =============================================================================

it('rejects a registration with no shop', function () {
    $payload = registerPayload(['email' => 'noshop@example.com']);
    unset($payload['branch_slug']);

    $this->postJson('/api/v1/customer/auth/register', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['branch_slug']);

    $this->assertDatabaseMissing('customers', ['email' => 'noshop@example.com']);
});

it('rejects a shop slug that does not exist', function () {
    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'ghost@example.com',
        'branch_slug' => 'khong-co-that',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['branch_slug']);

    $this->assertDatabaseMissing('customers', ['email' => 'ghost@example.com']);
});

// Chi nhánh đã tắt không nằm trong GET /customer/branches, nên slug của nó tới
// được đây chỉ có thể là URL cũ hoặc gõ tay — không phải cửa hàng đang nhận khách.
it('rejects a shop that is no longer active', function () {
    Branch::factory()->create(['slug' => 'da-dong-cua', 'is_active' => false]);

    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'email' => 'closed@example.com',
        'branch_slug' => 'da-dong-cua',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['branch_slug']);

    $this->assertDatabaseMissing('customers', ['email' => 'closed@example.com']);
});

// Cốt lõi của #1505: trước đây cả ba khoá này đều NULL với khách tự đăng ký.
it('stamps branch, brand and organization resolved from the shop slug', function () {
    $organization = Organization::factory()->create();
    $brand = Brand::factory()->create([
        'console_organization_id' => $organization->console_organization_id,
    ]);
    $branch = Branch::factory()->create([
        'slug' => 'ginza',
        'console_organization_id' => $organization->console_organization_id,
        'console_brand_id' => $brand->console_brand_id,
    ]);

    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'first_name' => 'Ginza',
        'email' => 'ginza@example.com',
        'branch_slug' => 'ginza',
    ]))->assertAccepted();

    $this->assertDatabaseHas('customers', [
        'email' => 'ginza@example.com',
        'branch_id' => $branch->id,
        'brand_id' => $brand->id,
        'organization_id' => $organization->id,
    ]);
});

// Chi nhánh chưa gắn brand/organization là cấu hình dở dang phía HQ — khách
// không chịu trách nhiệm cho việc đó, vẫn cho đăng ký và chỉ để trống khoá
// nào thực sự không suy ra được.
it('still registers when the shop has no brand or organization row', function () {
    $branch = Branch::factory()->create([
        'slug' => 'chua-cau-hinh',
        'console_brand_id' => null,
    ]);

    $this->postJson('/api/v1/customer/auth/register', registerPayload([
        'first_name' => 'Solo',
        'email' => 'solo@example.com',
        'branch_slug' => 'chua-cau-hinh',
    ]))->assertAccepted();

    $this->assertDatabaseHas('customers', [
        'email' => 'solo@example.com',
        'branch_id' => $branch->id,
        'brand_id' => null,
        'organization_id' => null,
    ]);
});
