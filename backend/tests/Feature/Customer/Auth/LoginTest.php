<?php

use App\Models\Customer;

// =============================================================================
// Validation
// =============================================================================

it('rejects missing email', function () {
    $this->postJson('/api/v1/customer/auth/login', [
        'password' => 'secret123',
        'device_name' => 'iPhone',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

it('rejects invalid email format', function () {
    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'not-an-email',
        'password' => 'secret123',
        'device_name' => 'iPhone',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

it('rejects missing password', function () {
    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'test@example.com',
        'device_name' => 'iPhone',
    ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
});

it('rejects missing device_name', function () {
    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'test@example.com',
        'password' => 'secret123',
    ])->assertUnprocessable()->assertJsonValidationErrors(['device_name']);
});

// =============================================================================
// Happy path
// =============================================================================

it('returns 200 with user + token on correct credentials', function () {
    Customer::factory()->selfRegistered()->create([
        'email' => 'user@example.com',
        'password' => 'password123',
    ]);

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'user@example.com',
        'password' => 'password123',
        'device_name' => 'iPhone',
    ])->assertOk()
        ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']]);
});

it('creates a new token on each login with a different device_name', function () {
    $customer = Customer::factory()->selfRegistered()->create([
        'email' => 'multi@example.com',
        'password' => 'password123',
    ]);

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'multi@example.com',
        'password' => 'password123',
        'device_name' => 'iPhone',
    ])->assertOk();

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'multi@example.com',
        'password' => 'password123',
        'device_name' => 'iPad',
    ])->assertOk();

    expect($customer->tokens()->count())->toBe(2);
});

// =============================================================================
// Wrong credentials
// =============================================================================

it('returns 422 for non-existent email', function () {
    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'password123',
        'device_name' => 'iPhone',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

it('returns 422 for wrong password', function () {
    Customer::factory()->selfRegistered()->create([
        'email' => 'wrong@example.com',
        'password' => 'correct123',
    ]);

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'wrong@example.com',
        'password' => 'wrong-pass',
        'device_name' => 'iPhone',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

it('email lookup is case-sensitive (User@example.com ≠ user@example.com)', function () {
    Customer::factory()->selfRegistered()->create([
        'email' => 'user@example.com',
        'password' => 'password123',
    ]);

    // Different case → should NOT find the customer (Eloquent default).
    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'User@example.com',
        'password' => 'password123',
        'device_name' => 'iPhone',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

// #1680 đảo luật này. Bản cũ ghi "allows login for unverified email
// (soft-gate via email_verified flag)": đăng nhập được, chỉ mang theo một cờ
// `email_verified: false` mà không tầng nào đọc — tức là không có cổng nào.
// Giờ cổng nằm ở chính đường đăng nhập.
it('blocks login for unverified email', function () {
    Customer::factory()->unverified()->create([
        'email' => 'unverified@example.com',
        'password' => 'password123',
    ]);

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'unverified@example.com',
        'password' => 'password123',
        'device_name' => 'iPhone',
    ])->assertStatus(403)
        ->assertJsonPath('code', 'email_not_verified');
});

it('does not reveal whether email exists in the error message', function () {
    $responseNoUser = $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'password123',
        'device_name' => 'iPhone',
    ]);

    Customer::factory()->selfRegistered()->create([
        'email' => 'exists@example.com',
        'password' => 'correct123',
    ]);

    $responseWrongPass = $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'exists@example.com',
        'password' => 'wrongpass',
        'device_name' => 'iPhone',
    ]);

    expect($responseNoUser->json('errors.email.0'))
        ->toBe($responseWrongPass->json('errors.email.0'));
});
