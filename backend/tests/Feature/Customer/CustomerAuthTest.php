<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;

// =========================================================================
//  Happy path
// =========================================================================

it('registers a customer and waits for email verification', function () {
    // Đăng ký luôn gắn với một cửa hàng kể từ #1505; kể từ #1680 nó KHÔNG
    // phát token — tài khoản chỉ dùng được sau khi bấm link trong thư.
    Branch::factory()->create(['slug' => 'shibuya']);

    $response = $this->postJson('/api/v1/customer/auth/register', [
        'first_name' => 'Taro',
        'email' => 'taro@example.com',
        // SĐT bắt buộc + mật khẩu theo chính sách mới kể từ #1780.
        'phone' => '0912345678',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'device_name' => 'test',
        'branch_slug' => 'shibuya',
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.email', 'taro@example.com')
        ->assertJsonPath('data.verification_required', true);

    $this->assertDatabaseHas('customers', ['email' => 'taro@example.com', 'email_verified_at' => null]);
    $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_type' => 'Customer']);
});

it('logs in an existing customer and returns user + token', function () {
    Customer::factory()->selfRegistered()->create(['email' => 'taro@example.com', 'password' => 'password123']);

    $response = $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'taro@example.com',
        'password' => 'password123',
        'device_name' => 'test',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']]);
});

it('logs out an authenticated customer', function () {
    $account = Customer::factory()->selfRegistered()->create();
    $token = $account->createToken('test')->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/v1/customer/auth/logout');

    $response->assertNoContent();
    $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $account->id]);
});

it('returns the authenticated customer user data', function () {
    $account = Customer::factory()->selfRegistered()->create(['first_name' => 'Hanako', 'email' => 'hanako@example.com']);
    $token = $account->createToken('test')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/customer/auth/user');

    $response->assertOk()
        ->assertJson(['data' => ['id' => $account->id, 'name' => 'Hanako', 'email' => 'hanako@example.com']]);
});

// =========================================================================
//  Validation
// =========================================================================

it('returns 422 when email is missing on register', function () {
    $response = $this->postJson('/api/v1/customer/auth/register', [
        'name' => 'Test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'device_name' => 'test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('email');
});

it('returns 422 when password is too short', function () {
    $response = $this->postJson('/api/v1/customer/auth/register', [
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
        'device_name' => 'test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('password');
});

it('returns 422 when password confirmation does not match', function () {
    $response = $this->postJson('/api/v1/customer/auth/register', [
        'name' => 'Test',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'different123',
        'device_name' => 'test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('password');
});

it('returns 422 when email is already taken', function () {
    Customer::factory()->selfRegistered()->create(['email' => 'taken@example.com']);

    $response = $this->postJson('/api/v1/customer/auth/register', [
        'name' => 'Test',
        'email' => 'taken@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'device_name' => 'test',
    ]);

    $response->assertStatus(422)->assertJsonValidationErrors('email');
});

// =========================================================================
//  Authorization
// =========================================================================

it('returns 401 when accessing logout without token', function () {
    $this->postJson('/api/v1/customer/auth/logout')->assertUnauthorized();
});

it('returns 401 when accessing user without token', function () {
    $this->getJson('/api/v1/customer/auth/user')->assertUnauthorized();
});

it('returns 401 when a staff token is used on customer guard', function () {
    $staff = User::factory()->create();
    $staffToken = $staff->createToken('staff')->plainTextToken;

    $this->withToken($staffToken)->getJson('/api/v1/customer/auth/user')->assertUnauthorized();
});

// =========================================================================
//  Error handling
// =========================================================================

it('returns 422 when login credentials are wrong', function () {
    Customer::factory()->selfRegistered()->create(['email' => 'test@example.com', 'password' => 'password123']);

    $response = $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'test@example.com',
        'password' => 'wrongpassword',
        'device_name' => 'test',
    ]);

    $response->assertStatus(422);
});

// =========================================================================
//  Side effects
// =========================================================================

it('sets tokenable_type to Customer when a verified customer logs in', function () {
    Customer::factory()->selfRegistered()->create([
        'email' => 'test@example.com',
        'password' => 'password123',
    ]);

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password123',
        'device_name' => 'test',
    ])->assertOk();

    $this->assertDatabaseHas('personal_access_tokens', [
        'tokenable_type' => 'Customer',
    ]);
});

it('deletes the token row on logout', function () {
    $account = Customer::factory()->selfRegistered()->create();
    $token = $account->createToken('test')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/customer/auth/logout');

    expect($account->tokens()->count())->toBe(0);
});
