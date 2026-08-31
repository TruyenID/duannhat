<?php

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/*
 * #1782 · #1783 · #1784 — ba đường đăng nhập khách được dựng RIÊNG, mỗi cái có
 * test riêng và đều xanh. Chưa gì chứng minh chúng sống được CÙNG NHAU.
 *
 * Đó mới là chỗ lỗi hay nằm: cả ba cùng ghi lên `customers`, cùng đọc `email`,
 * và cùng có một vị từ "hàng nào là tài khoản thật" mà nếu ba chỗ hiểu khác
 * nhau thì khách rơi vào khe giữa — đăng nhập được bằng đường này, mất tài khoản
 * ở đường kia.
 *
 * File này chỉ chứa các phép giao nhau. Hành vi của từng đường nằm ở
 * `CustomerPhoneLoginTest`, `CustomerPasswordResetTest`, `CustomerGoogleLoginTest`.
 */

const COMPOSE_CLIENT_ID = 'compose-client.apps.googleusercontent.com';

function composeKeyPair(): array
{
    static $pair = null;
    if ($pair === null) {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $privatePem);
        $d = openssl_pkey_get_details($res);
        $pair = ['private' => $privatePem, 'n' => $d['rsa']['n'], 'e' => $d['rsa']['e']];
    }

    return $pair;
}

function composeB64(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function composeGoogleToken(string $email, string $sub = 'sub-compose'): string
{
    $pair = composeKeyPair();
    $h = composeB64((string) json_encode(['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT']));
    $p = composeB64((string) json_encode([
        'iss' => 'https://accounts.google.com', 'aud' => COMPOSE_CLIENT_ID, 'sub' => $sub,
        'email' => $email, 'email_verified' => true, 'name' => 'Khách',
        'iat' => time() - 5, 'exp' => time() + 3600,
    ]));
    openssl_sign($h.'.'.$p, $sig, $pair['private'], OPENSSL_ALGO_SHA256);

    return $h.'.'.$p.'.'.composeB64($sig);
}

beforeEach(function () {
    Cache::flush();
    Notification::fake();
    Config::set('services.google.client_id', COMPOSE_CLIENT_ID);
    Config::set('customer.web_url', 'https://khach.example.test');

    $pair = composeKeyPair();
    Http::fake(['https://www.googleapis.com/oauth2/v3/certs' => Http::response([
        'keys' => [['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256',
            'n' => composeB64($pair['n']), 'e' => composeB64($pair['e'])]],
    ])]);

    $this->account = Customer::factory()->create([
        'email' => 'giaonhau@example.test',
        'phone' => '0912345678',
        'password' => 'MatKhauCu#12345',
        'email_verified_at' => now(),
    ]);
});

it('nối Google KHÔNG làm mất đường đăng nhập bằng mật khẩu', function () {
    // Nối là `forceFill(['google_id' => …])`. Nếu nó vô tình chạm `password`
    // hoặc `email` thì khách "đăng nhập Google xong là mất mật khẩu" — kiểu hỏng
    // chỉ lộ ra khi có người thử cả hai đường.
    $this->postJson('/api/v1/customer/auth/google', [
        'id_token' => composeGoogleToken('giaonhau@example.test'),
        'device_name' => 'test',
    ])->assertOk();

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'giaonhau@example.test',
        'password' => 'MatKhauCu#12345',
        'device_name' => 'test',
    ])->assertOk();
});

it('nối Google KHÔNG làm mất đường đăng nhập bằng số điện thoại', function () {
    $this->postJson('/api/v1/customer/auth/google', [
        'id_token' => composeGoogleToken('giaonhau@example.test'),
        'device_name' => 'test',
    ])->assertOk();

    $this->postJson('/api/v1/customer/auth/login', [
        'identifier' => '0912345678',
        'password' => 'MatKhauCu#12345',
        'device_name' => 'test',
    ])->assertOk();
});

it('đặt lại mật khẩu xong thì đăng nhập được bằng CẢ email lẫn số điện thoại', function () {
    // `resetPassword` xoá sạch token và ghi mật khẩu mới. Nếu nó ghi qua một
    // đường không chuẩn hoá thì đăng nhập bằng SĐT — vốn tra bằng `whereIn` các
    // biến thể — có thể trượt trong khi email vẫn vào được.
    $token = Password::broker('customer_accounts')->getRepository()->create($this->account);

    $this->postJson('/api/v1/customer/auth/password/reset', [
        'token' => $token, 'email' => $this->account->email,
        'password' => 'MatKhauMoi#67890', 'password_confirmation' => 'MatKhauMoi#67890',
    ])->assertOk();

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => $this->account->email, 'password' => 'MatKhauMoi#67890', 'device_name' => 't',
    ])->assertOk();

    $this->postJson('/api/v1/customer/auth/login', [
        'identifier' => '0912345678', 'password' => 'MatKhauMoi#67890', 'device_name' => 't',
    ])->assertOk();
});

it('tài khoản CHỈ-Google không xin được link đặt lại mật khẩu — khoảng trống đã biết', function () {
    // Ghim khoảng trống ĐÃ GHI trong #1784 thay vì để nó âm thầm đổi chiều:
    // `sendPasswordResetLink` lọc `whereNotNull('password')`, nên tài khoản chỉ
    // đăng nhập bằng Google không nhận được thư. Đúng về mặt logic (không có
    // mật khẩu để quên), nhưng nếu sau này ai đó nới vị từ đó thì test này đỏ và
    // buộc phải nghĩ về hệ quả: gửi link đặt-lại cho một tài khoản chưa từng có
    // mật khẩu là một luồng ĐẶT mật khẩu, không phải đặt lại.
    $this->postJson('/api/v1/customer/auth/google', [
        'id_token' => composeGoogleToken('chigoogle@example.test', 'sub-only-google'),
        'device_name' => 'test',
    ])->assertOk();

    Notification::fake();
    $this->postJson('/api/v1/customer/auth/password/forgot', ['email' => 'chigoogle@example.test'])->assertOk();

    Notification::assertNothingSent();
});

it('mật khẩu cũ CHẾT sau khi đặt lại, kể cả khi đăng nhập bằng số điện thoại', function () {
    $token = Password::broker('customer_accounts')->getRepository()->create($this->account);
    $this->postJson('/api/v1/customer/auth/password/reset', [
        'token' => $token, 'email' => $this->account->email,
        'password' => 'MatKhauMoi#67890', 'password_confirmation' => 'MatKhauMoi#67890',
    ])->assertOk();

    $this->postJson('/api/v1/customer/auth/login', [
        'identifier' => '0912345678', 'password' => 'MatKhauCu#12345', 'device_name' => 't',
    ])->assertStatus(422);

    expect(Hash::check('MatKhauCu#12345', $this->account->fresh()->password))->toBeFalse();
});

it('số điện thoại TRÙNG không chặn được đường email của chính người đó', function () {
    // #1782 từ chối đăng nhập bằng SĐT khi trùng. Nhưng email của họ vẫn phải
    // vào được — nếu không thì một người thứ hai nhập trùng số sẽ khoá tài khoản
    // của người thứ nhất, và đó là một đường tấn công rẻ tiền.
    Customer::factory()->create([
        'email' => 'nguoikhac@example.test', 'phone' => '0912345678',
        'password' => 'MatKhauKhac#12345', 'email_verified_at' => now(),
    ]);

    $this->postJson('/api/v1/customer/auth/login', [
        'identifier' => '0912345678', 'password' => 'MatKhauCu#12345', 'device_name' => 't',
    ])->assertStatus(422);

    $this->postJson('/api/v1/customer/auth/login', [
        'email' => 'giaonhau@example.test', 'password' => 'MatKhauCu#12345', 'device_name' => 't',
    ])->assertOk();
});
