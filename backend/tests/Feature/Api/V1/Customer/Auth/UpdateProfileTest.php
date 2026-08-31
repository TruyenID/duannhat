<?php

use App\Models\AuditLog;
use App\Models\Customer;
use App\Omnify\Enums\CustomerGenderEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->customer = Customer::factory()->selfRegistered()->create([
        'first_name' => 'Taro',
        'last_name' => 'Yamada',
        'phone' => '090-1234-5678',
        'password' => 'password',
    ]);
    $this->token = $this->customer->createToken('test')->plainTextToken;
});

it('updates first_name', function () {
    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'first_name' => 'Anna',
    ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Anna');

    expect($this->customer->fresh()->first_name)->toBe('Anna');
});

it('clears phone when sent as empty string', function () {
    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'phone' => '',
    ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertOk();
    expect($this->customer->fresh()->phone)->toBeNull();
});

it('updates multiple fields at once', function () {
    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'first_name' => 'Hanako',
        'last_name' => 'Suzuki',
        'phone' => '080-9999-0000',
    ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertOk()
        ->assertJsonPath('data.phone', '080-9999-0000');

    $customer = $this->customer->fresh();
    expect($customer->first_name)->toBe('Hanako')
        ->and($customer->last_name)->toBe('Suzuki')
        ->and($customer->phone)->toBe('080-9999-0000');
});

it('rejects email field with 422', function () {
    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'email' => 'new@email.com',
    ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('rejects password field with 422', function () {
    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'password' => 'new-password',
    ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

it('rejects first_name over 100 chars', function () {
    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'first_name' => str_repeat('a', 101),
    ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('first_name');
});

it('rejects clearing first_name with an empty string (NOT NULL column)', function () {
    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'first_name' => '',
    ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('first_name');

    expect($this->customer->fresh()->first_name)->toBe('Taro');
});

it('rejects clearing first_name with null (NOT NULL column)', function () {
    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'first_name' => null,
    ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('first_name');

    expect($this->customer->fresh()->first_name)->toBe('Taro');
});

it('rejects phone longer than the 20-char column', function () {
    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'phone' => str_repeat('9', 21),
    ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('phone');

    expect($this->customer->fresh()->phone)->toBe('090-1234-5678');
});

it('logs a profile_updated audit entry with a diff of changed fields', function () {
    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'first_name' => 'Anna',
    ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertOk();

    $log = AuditLog::where('action', 'profile_updated')
        ->where('auditable_id', $this->customer->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->auditable_type)->toBe($this->customer->getMorphClass())
        ->and($log->metadata['changes']['first_name'])->toBe('Anna')
        ->and($log->metadata['original']['first_name'])->toBe('Taro');
});

it('does not log a profile_updated audit entry when nothing changed', function () {
    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'first_name' => 'Taro',
    ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertOk();

    expect(AuditLog::where('action', 'profile_updated')->count())->toBe(0);
});

it('updates birthday and gender', function () {
    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'birthday' => '2005-09-14',
        'gender' => 'female',
    ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertOk()
        ->assertJsonPath('data.birthday', '2005-09-14')
        ->assertJsonPath('data.gender', 'female');

    $customer = $this->customer->fresh();
    expect($customer->birthday->toDateString())->toBe('2005-09-14')
        ->and($customer->gender)->toBe(CustomerGenderEnum::Female);
});

it('serialises birthday as a plain date, not a timestamp', function () {
    $this->customer->update(['birthday' => '2005-09-14']);

    $this->getJson('/api/v1/customer/auth/user', ['Authorization' => "Bearer {$this->token}"])
        ->assertOk()
        ->assertJsonPath('data.birthday', '2005-09-14');
});

it('clears birthday and gender when sent as empty strings', function () {
    $this->customer->update(['birthday' => '2005-09-14', 'gender' => 'male']);

    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'birthday' => '',
        'gender' => '',
    ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertOk();

    $customer = $this->customer->fresh();
    expect($customer->birthday)->toBeNull()
        ->and($customer->gender)->toBeNull();
});

it('rejects a gender outside the enum', function () {
    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'gender' => 'unicorn',
    ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('gender');
});

it('rejects a birthday in the future', function () {
    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'birthday' => now()->addDay()->toDateString(),
    ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('birthday');

    expect($this->customer->fresh()->birthday)->toBeNull();
});

it('leaves birthday and gender untouched when the field is absent', function () {
    $this->customer->update(['birthday' => '2005-09-14', 'gender' => 'female']);

    $this->patchJson('/api/v1/customer/auth/user', [
        'first_name' => 'Anna',
    ], ['Authorization' => "Bearer {$this->token}"])->assertOk();

    $customer = $this->customer->fresh();
    expect($customer->birthday->toDateString())->toBe('2005-09-14')
        ->and($customer->gender)->toBe(CustomerGenderEnum::Female);
});

it('returns 401 without token', function () {
    $response = $this->patchJson('/api/v1/customer/auth/user', [
        'first_name' => 'Anna',
    ]);

    $response->assertUnauthorized();
});
