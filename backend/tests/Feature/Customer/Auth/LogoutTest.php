<?php

use App\Models\Customer;

// =============================================================================
// Auth guard
// =============================================================================

it('returns 401 when no bearer token is provided', function () {
    $this->postJson('/api/v1/customer/auth/logout')->assertUnauthorized();
});

// =============================================================================
// Happy path
// =============================================================================

it('logs out and returns 204', function () {
    $customer = Customer::factory()->selfRegistered()->create();
    $token = $customer->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/customer/auth/logout')
        ->assertNoContent();
});

it('removes the token from personal_access_tokens after logout', function () {
    $customer = Customer::factory()->selfRegistered()->create();
    $tokenPlain = $customer->createToken('test')->plainTextToken;

    $this->withToken($tokenPlain)
        ->postJson('/api/v1/customer/auth/logout')
        ->assertNoContent();

    $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $customer->id]);
});

it('only invalidates the current token, leaving others in DB', function () {
    $customer = Customer::factory()->selfRegistered()->create();
    $token1Plain = $customer->createToken('device-1')->plainTextToken;
    $customer->createToken('device-2');

    $this->withToken($token1Plain)->postJson('/api/v1/customer/auth/logout')->assertNoContent();

    expect($customer->tokens()->count())->toBe(1);
});

it('deletes exactly one token per logout call', function () {
    $customer = Customer::factory()->selfRegistered()->create();
    $customer->createToken('device-1');
    $customer->createToken('device-2');
    $customer->createToken('device-3');

    $token = $customer->createToken('device-4')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/customer/auth/logout')->assertNoContent();

    expect($customer->tokens()->count())->toBe(3);
});
