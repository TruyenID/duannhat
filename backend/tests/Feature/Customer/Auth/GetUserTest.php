<?php

use App\Models\Customer;

it('returns 401 without bearer token', function () {
    $this->getJson('/api/v1/customer/auth/user')->assertUnauthorized();
});

it('returns authenticated customer data', function () {
    $customer = Customer::factory()->selfRegistered()->create([
        'first_name' => 'Hanako',
        'email' => 'hanako@example.com',
    ]);
    $token = $customer->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/customer/auth/user')
        ->assertOk()
        ->assertJsonStructure(['data' => ['id', 'name', 'email']])
        ->assertJsonPath('data.email', 'hanako@example.com');
});

it('returns the correct customer id', function () {
    $customer = Customer::factory()->selfRegistered()->create();
    $token = $customer->createToken('test')->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/v1/customer/auth/user');

    expect($response->json('data.id'))->toBe($customer->id);
});

it('returns 401 after customer token is deleted', function () {
    $customer = Customer::factory()->selfRegistered()->create();
    $token = $customer->createToken('test')->plainTextToken;

    $customer->tokens()->delete();

    $this->withToken($token)
        ->getJson('/api/v1/customer/auth/user')
        ->assertUnauthorized();
});
