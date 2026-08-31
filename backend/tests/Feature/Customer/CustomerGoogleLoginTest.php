<?php

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * #1784 — đăng nhập khách bằng Google. Hệ RIÊNG, không dùng SSO nhân viên.
 *
 * Test tự sinh cặp khoá RSA và tự dựng JWKS, nên KHÔNG chạm mạng: một bộ test
 * xác thực mà phụ thuộc vào Google còn sống là bộ test sẽ đỏ vào ngày tệ nhất.
 */

const GOOGLE_CLIENT_ID = 'client-id-cua-minh.apps.googleusercontent.com';

function googleKeyPair(): array
{
    static $pair = null;
    if ($pair === null) {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privatePem);
        $details = openssl_pkey_get_details($res);
        $pair = ['private' => $privatePem, 'n' => $details['rsa']['n'], 'e' => $details['rsa']['e']];
    }

    return $pair;
}

function b64u(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function googleIdToken(array $overrides = [], string $alg = 'RS256', string $kid = 'kid-1'): string
{
    $pair = googleKeyPair();
    $header = b64u((string) json_encode(['alg' => $alg, 'kid' => $kid, 'typ' => 'JWT']));
    $payload = b64u((string) json_encode(array_merge([
        'iss' => 'https://accounts.google.com',
        'aud' => GOOGLE_CLIENT_ID,
        'sub' => 'google-sub-123',
        'email' => 'khach@example.test',
        'email_verified' => true,
        'name' => 'Khách Google',
        'iat' => time() - 10,
        'exp' => time() + 3600,
    ], $overrides)));

    openssl_sign($header.'.'.$payload, $signature, $pair['private'], OPENSSL_ALGO_SHA256);

    return $header.'.'.$payload.'.'.b64u($signature);
}

function fakeGoogleJwks(string $kid = 'kid-1'): void
{
    $pair = googleKeyPair();
    Http::fake([
        'https://www.googleapis.com/oauth2/v3/certs' => Http::response([
            'keys' => [[
                'kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256',
                'n' => b64u($pair['n']), 'e' => b64u($pair['e']),
            ]],
        ]),
    ]);
}

function googleLogin(string $token): TestResponse
{
    return test()->postJson('/api/v1/customer/auth/google', [
        'id_token' => $token,
        'device_name' => 'test',
    ]);
}

beforeEach(function () {
    Cache::flush();
    Config::set('services.google.client_id', GOOGLE_CLIENT_ID);
    fakeGoogleJwks();
});

it('#1784 TẮT khi chưa cấu hình client id — fail-closed', function () {
    // Một tính năng danh tính bật nửa vời còn tệ hơn tắt hẳn.
    Config::set('services.google.client_id', '');

    googleLogin(googleIdToken())->assertStatus(422)->assertJsonValidationErrors(['id_token']);
});

it('#1784 token hợp lệ TẠO tài khoản mới, đã xác nhận email', function () {
    $response = googleLogin(googleIdToken())->assertOk();

    expect($response->json('data.created'))->toBeTrue();

    $account = Customer::query()->where('google_id', 'google-sub-123')->first();
    expect($account)->not->toBeNull()
        ->and($account->hasVerifiedEmail())->toBeTrue()
        // Không mật khẩu: tài khoản này đăng nhập bằng Google.
        ->and($account->password)->toBeNull();
});

it('#1784 lần hai NHẬN LẠI đúng tài khoản, không tạo bản sao', function () {
    googleLogin(googleIdToken())->assertOk();
    $second = googleLogin(googleIdToken())->assertOk();

    expect($second->json('data.created'))->toBeFalse()
        ->and(Customer::query()->where('google_id', 'google-sub-123')->count())->toBe(1);
});

it('#1784 email TRÙNG tài khoản mật khẩu thì NỐI, không tạo hồ sơ thứ hai', function () {
    // Ruling 2. Google đã khẳng định người này kiểm soát hòm thư, mà hòm thư đó
    // chính là thứ #1783 dùng để trao lại quyền — họ vốn đã vào được tài khoản
    // này bằng đường dài hơn. Từ chối nối chỉ chia đôi điểm thưởng và lịch sử đơn.
    $existing = Customer::factory()->create([
        'email' => 'khach@example.test',
        'password' => 'MatKhau#12345',
        'email_verified_at' => now(),
    ]);

    googleLogin(googleIdToken())->assertOk();

    expect($existing->fresh()->google_id)->toBe('google-sub-123')
        ->and(Customer::query()->where('email', 'khach@example.test')->count())->toBe(1);
});

it('#1784 email Google CHƯA xác nhận thì TỪ CHỐI — không tạo, không nối', function () {
    // Ruling 1. `email_verified = false` nghĩa là Google cũng không biết người
    // này có sở hữu hòm thư hay không; nối theo địa chỉ đó là trao tài khoản
    // người khác cho ai đăng ký trùng địa chỉ.
    Customer::factory()->create(['email' => 'khach@example.test', 'password' => 'MatKhau#12345']);

    googleLogin(googleIdToken(['email_verified' => false]))
        ->assertStatus(422)->assertJsonValidationErrors(['id_token']);

    expect(Customer::query()->whereNotNull('google_id')->count())->toBe(0);
});

it('#1784 token của ứng dụng KHÁC bị từ chối (aud sai)', function () {
    // Bỏ kiểm `aud` là nhận cả token Google cấp cho app khác — kẻ tấn công chỉ
    // cần một app Google của riêng họ.
    googleLogin(googleIdToken(['aud' => 'app-cua-nguoi-khac.apps.googleusercontent.com']))
        ->assertStatus(422);
});

it('#1784 iss không phải Google thì từ chối', function () {
    googleLogin(googleIdToken(['iss' => 'https://ke-tan-cong.test']))->assertStatus(422);
});

it('#1784 token HẾT HẠN bị từ chối', function () {
    googleLogin(googleIdToken(['exp' => time() - 3600, 'iat' => time() - 7200]))->assertStatus(422);
});

it('#1784 alg none / HMAC bị từ chối — không đọc alg để chọn thuật toán', function (string $alg) {
    googleLogin(googleIdToken([], $alg))->assertStatus(422);
})->with(['none', 'HS256']);

it('#1784 chữ ký sai bị từ chối', function () {
    $token = googleIdToken();
    // Đổi một ký tự trong phần chữ ký.
    $parts = explode('.', $token);
    $parts[2] = strrev($parts[2]);

    googleLogin(implode('.', $parts))->assertStatus(422);
});

it('#1784 thông điệp lỗi KHÔNG phân biệt nguyên nhân kỹ thuật', function () {
    // Phân biệt được "aud sai" / "chữ ký hỏng" / "hết hạn" là bản đồ miễn phí
    // cho người đang dò. Chi tiết nằm ở log.
    $badAud = googleLogin(googleIdToken(['aud' => 'khac.apps.googleusercontent.com']))->json('errors.id_token.0');
    $expired = googleLogin(googleIdToken(['exp' => time() - 3600]))->json('errors.id_token.0');

    expect($badAud)->toBe($expired)->and($badAud)->toBe(__('auth.google_failed'));
});

it('#1784 email trùng NHIỀU tài khoản mật khẩu thì từ chối, không nối bừa', function () {
    Customer::factory()->create(['email' => 'khach@example.test', 'password' => 'MatKhau#12345']);
    Customer::factory()->create(['email' => 'khach@example.test', 'password' => 'MatKhau#12345']);

    googleLogin(googleIdToken())->assertStatus(422);

    expect(Customer::query()->whereNotNull('google_id')->count())->toBe(0);
});
