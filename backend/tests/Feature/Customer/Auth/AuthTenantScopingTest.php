<?php

use App\Models\Branch;
use App\Models\Customer;
use App\Services\Customer\CustomerAuthService;
use Illuminate\Validation\ValidationException;

// =============================================================================
// Finding 2 — login must not resolve a cross-tenant / password-less CRM record
// =============================================================================

it('logs in the self-service account even when a password-less CRM record shares the email', function () {
    // CRM record created FIRST (lower rowid) — a bare where('email')->first()
    // would return THIS password-less, org-scoped row and fail Hash::check.
    Customer::factory()->create([
        'email' => 'shared@example.com',
        'password' => null,
    ]);

    $account = Customer::factory()->selfRegistered()->create([
        'email' => 'shared@example.com',
        'password' => 'password123',
    ]);

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'shared@example.com',
        'password' => 'password123',
        'device_name' => 'iPhone',
    ])->assertOk()
        ->assertJsonPath('data.user.id', $account->id);
});

// =============================================================================
// Finding 1 — register guards the uniqueness race at the service layer, not
// only via the pre-write FormRequest validation rule.
// =============================================================================

it('rejects a second self-service registration for the same email at the service layer', function () {
    $service = app(CustomerAuthService::class);
    Branch::factory()->create(['slug' => 'shibuya']);

    $service->register([
        'first_name' => 'First',
        'email' => 'race@example.com',
        'password' => 'password123',
        'device_name' => 'iPhone',
        'branch_slug' => 'shibuya',
    ]);

    // Second call bypasses the FormRequest `unique` rule (as a concurrent request
    // that passed validation before the first write committed would). The service
    // must still refuse to create a duplicate auth account.
    expect(fn () => $service->register([
        'first_name' => 'Second',
        'email' => 'race@example.com',
        'password' => 'password456',
        'device_name' => 'iPad',
        'branch_slug' => 'shibuya',
    ]))->toThrow(ValidationException::class);

    expect(Customer::where('email', 'race@example.com')->count())->toBe(1);
});
