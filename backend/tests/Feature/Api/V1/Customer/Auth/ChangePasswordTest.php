<?php

use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->customer = Customer::factory()->selfRegistered()->create([
        'password' => 'old-secret-123',
    ]);
    $this->token = $this->customer->createToken('test')->plainTextToken;
});

it('changes password with correct current password', function () {
    $response = $this->withoutMiddleware(ThrottleRequests::class)
        ->postJson('/api/v1/customer/auth/password', [
            'current_password' => 'old-secret-123',
            'password' => 'New-secret-456',
            'password_confirmation' => 'New-secret-456',
        ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertNoContent();

    expect(Hash::check('New-secret-456', $this->customer->fresh()->password))->toBeTrue();
});

it('logs a password_changed audit entry with no payload', function () {
    $response = $this->withoutMiddleware(ThrottleRequests::class)
        ->postJson('/api/v1/customer/auth/password', [
            'current_password' => 'old-secret-123',
            'password' => 'New-secret-456',
            'password_confirmation' => 'New-secret-456',
        ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertNoContent();

    $log = AuditLog::where('action', 'password_changed')
        ->where('auditable_id', $this->customer->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->auditable_type)->toBe($this->customer->getMorphClass())
        ->and($log->metadata)->toBeNull();
});

it('revokes other tokens when logout_other_devices is true', function () {
    $this->customer->createToken('other-device');

    expect($this->customer->tokens()->count())->toBe(2);

    $response = $this->withoutMiddleware(ThrottleRequests::class)
        ->postJson('/api/v1/customer/auth/password', [
            'current_password' => 'old-secret-123',
            'password' => 'New-secret-456',
            'password_confirmation' => 'New-secret-456',
            'logout_other_devices' => true,
        ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertNoContent();

    // Only the current token should remain
    expect($this->customer->tokens()->count())->toBe(1);
});

it('keeps other tokens when logout_other_devices is false', function () {
    $this->customer->createToken('other-device');

    expect($this->customer->tokens()->count())->toBe(2);

    $response = $this->withoutMiddleware(ThrottleRequests::class)
        ->postJson('/api/v1/customer/auth/password', [
            'current_password' => 'old-secret-123',
            'password' => 'New-secret-456',
            'password_confirmation' => 'New-secret-456',
            'logout_other_devices' => false,
        ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertNoContent();

    // Both tokens should remain
    expect($this->customer->tokens()->count())->toBe(2);
});

it('rejects wrong current password', function () {
    $response = $this->withoutMiddleware(ThrottleRequests::class)
        ->postJson('/api/v1/customer/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'New-secret-456',
            'password_confirmation' => 'New-secret-456',
        ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('current_password');
});

it('rejects same password as current', function () {
    $response = $this->withoutMiddleware(ThrottleRequests::class)
        ->postJson('/api/v1/customer/auth/password', [
            'current_password' => 'old-secret-123',
            'password' => 'old-secret-123',
            'password_confirmation' => 'old-secret-123',
        ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

// #1780 — đổi mật khẩu dùng CÙNG chính sách với lúc đăng ký. Nếu chỉ siết ở
// đăng ký thì khách hạ ngay mật khẩu xuống 8 ký tự ở màn này, tức luật mới chỉ
// tồn tại trong đúng một form. Mỗi ca dưới đây trượt đúng MỘT điều kiện.
it('rejects a new password that fails exactly one policy rule', function (string $password) {
    $response = $this->withoutMiddleware(ThrottleRequests::class)
        ->postJson('/api/v1/customer/auth/password', [
            'current_password' => 'old-secret-123',
            'password' => $password,
            'password_confirmation' => $password,
        ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    expect(Hash::check('old-secret-123', $this->customer->fresh()->password))->toBeTrue();
})->with([
    'quá ngắn' => 'Ab1!efgh',
    'không có chữ hoa' => 'new-secret-456',
    'không có số' => 'New-secret-abc',
    'không có ký tự đặc biệt' => 'Newsecret4567',
]);

it('rejects mismatched confirmation', function () {
    $response = $this->withoutMiddleware(ThrottleRequests::class)
        ->postJson('/api/v1/customer/auth/password', [
            'current_password' => 'old-secret-123',
            'password' => 'New-secret-456',
            'password_confirmation' => 'different-456',
        ], ['Authorization' => "Bearer {$this->token}"]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

it('rate-limits password change to 3 attempts per minute (throttle:3,1)', function () {
    // Throttle middleware is deliberately LEFT ON here (every other test in this
    // file disables it). Three wrong-current-password attempts are accepted by
    // the limiter (each 422); the 4th within the same minute is blocked (429).
    $payload = [
        'current_password' => 'wrong-password',
        'password' => 'New-secret-456',
        'password_confirmation' => 'New-secret-456',
    ];
    $headers = ['Authorization' => "Bearer {$this->token}"];

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $this->postJson('/api/v1/customer/auth/password', $payload, $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
    }

    $this->postJson('/api/v1/customer/auth/password', $payload, $headers)
        ->assertStatus(429);

    // The throttle must not have let any password change through.
    expect(Hash::check('old-secret-123', $this->customer->fresh()->password))->toBeTrue();
});

it('returns 401 without token', function () {
    $response = $this->withoutMiddleware(ThrottleRequests::class)
        ->postJson('/api/v1/customer/auth/password', [
            'current_password' => 'old-secret-123',
            'password' => 'New-secret-456',
            'password_confirmation' => 'New-secret-456',
        ]);

    $response->assertUnauthorized();
});
