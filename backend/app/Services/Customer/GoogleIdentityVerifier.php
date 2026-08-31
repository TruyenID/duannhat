<?php

namespace App\Services\Customer;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * #1784 — xác minh ID token của Google cho ĐĂNG NHẬP KHÁCH.
 *
 * ## Hệ RIÊNG, không phải SSO của platform
 *
 * Khách hàng không đăng nhập bằng SSO nội bộ (`dxs/laravel-auth`) — đó là hệ của
 * NHÂN VIÊN. Lớp này cố ý không dùng lại `JwtVerifier` của gói đó: nó gắn cứng
 * vào `OidcDiscovery` trỏ issuer nội bộ, và nhét Google vào đấy sẽ trộn hai hệ
 * danh tính có vòng đời, chính sách và bề mặt tấn công khác hẳn nhau.
 *
 * ## Vì sao TỰ xác minh chứ không gọi `tokeninfo` của Google
 *
 * Endpoint `tokeninfo` là một lời gọi mạng cho MỖI lượt đăng nhập: Google có
 * giới hạn tần suất, và nó biến trang đăng nhập thành thứ phụ thuộc vào độ trễ
 * của một bên thứ ba. Xác minh cục bộ chỉ cần JWKS, và JWKS đổi vài tháng một
 * lần nên cache được.
 *
 * Cũng KHÔNG thêm thư viện JWT: `firebase/php-jwt` có trong `vendor/` nhưng chỉ
 * là phụ thuộc BẮC CẦU của `dxs/laravel-auth` và SDK PayPay. Dùng thẳng một gói
 * không khai trong `composer.json` là đặt mìn — ngày ai đó gỡ PayPay thì đăng
 * nhập Google chết, và không ai nối được hai việc đó với nhau. Còn khai thêm
 * phụ thuộc thì luật repo đòi phê duyệt riêng. `openssl_verify` có sẵn, và phần
 * việc thật chỉ là dựng khoá công khai từ JWK.
 *
 * ## Fail-closed
 *
 * Chưa cấu hình `services.google.client_id` thì `enabled()` trả `false` và
 * đường đăng nhập từ chối thẳng. Cùng khuôn `payments.stripe_terminal.enabled`:
 * một tính năng danh tính bật nửa vời còn tệ hơn tắt hẳn.
 */
class GoogleIdentityVerifier
{
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const JWKS_CACHE_KEY = 'google:oauth:jwks';

    /** Lệch đồng hồ cho phép, giây. */
    private const LEEWAY = 60;

    public function enabled(): bool
    {
        return (string) Config::get('services.google.client_id', '') !== '';
    }

    /**
     * @return array{sub: string, email: string, email_verified: bool, name: string|null}
     *
     * @throws RuntimeException token không hợp lệ — thông điệp KHÔNG lộ ra API
     */
    public function verify(string $idToken): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Google sign-in chưa được cấu hình.');
        }

        [$header, $payload] = $this->decodeAndVerifySignature($idToken);
        unset($header);

        $now = time();

        if (! in_array((string) ($payload['iss'] ?? ''), self::ISSUERS, true)) {
            throw new RuntimeException('iss không phải Google.');
        }

        // `aud` phải là ĐÚNG client id của mình. Bỏ kiểm này là nhận cả token
        // Google cấp cho ứng dụng KHÁC — kẻ tấn công chỉ cần một app Google của
        // riêng họ là đăng nhập được vào đây.
        if ((string) ($payload['aud'] ?? '') !== (string) Config::get('services.google.client_id')) {
            throw new RuntimeException('aud không khớp client id.');
        }

        if ((int) ($payload['exp'] ?? 0) + self::LEEWAY < $now) {
            throw new RuntimeException('Token hết hạn.');
        }

        if ((int) ($payload['iat'] ?? 0) - self::LEEWAY > $now) {
            throw new RuntimeException('Token phát hành ở tương lai.');
        }

        $email = (string) ($payload['email'] ?? '');
        if ($email === '') {
            throw new RuntimeException('Token không có email.');
        }

        return [
            'sub' => (string) ($payload['sub'] ?? ''),
            'email' => $email,
            // Google gửi bool hoặc chuỗi "true" tuỳ đường.
            'email_verified' => filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
            'name' => isset($payload['name']) ? (string) $payload['name'] : null,
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function decodeAndVerifySignature(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('JWT sai định dạng.');
        }

        [$h, $p, $s] = $parts;
        $header = json_decode((string) self::b64($h), true);
        $payload = json_decode((string) self::b64($p), true);
        $signature = self::b64($s);

        if (! is_array($header) || ! is_array($payload) || $signature === null) {
            throw new RuntimeException('JWT không giải mã được.');
        }

        // CHỈ chấp nhận RS256. Không có nhánh `none`, và không đọc `alg` để chọn
        // thuật toán — đó là lỗ hổng thay-alg kinh điển: kẻ tấn công đặt
        // `alg: none` hoặc đổi sang HMAC rồi ký bằng chính khoá công khai.
        if (($header['alg'] ?? '') !== 'RS256') {
            throw new RuntimeException('alg phải là RS256.');
        }

        $pem = $this->publicKeyPem((string) ($header['kid'] ?? ''));

        $ok = openssl_verify($h.'.'.$p, $signature, $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new RuntimeException('Chữ ký không hợp lệ.');
        }

        return [$header, $payload];
    }

    /** Khoá công khai của Google theo `kid`, dạng PEM. */
    private function publicKeyPem(string $kid): string
    {
        $keys = $this->jwks();

        if (! isset($keys[$kid])) {
            // Google xoay khoá; `kid` lạ nghĩa là cache cũ, không phải token xấu.
            $keys = $this->jwks(fresh: true);
        }

        if (! isset($keys[$kid])) {
            throw new RuntimeException('Không tìm thấy khoá công khai cho kid.');
        }

        return $keys[$kid];
    }

    /**
     * @return array<string, string> kid → PEM
     */
    private function jwks(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::JWKS_CACHE_KEY);
        }

        return Cache::remember(self::JWKS_CACHE_KEY, now()->addHours(6), function (): array {
            $response = Http::timeout(5)->get(self::JWKS_URL);
            if (! $response->successful()) {
                throw new RuntimeException('Không lấy được JWKS của Google.');
            }

            $out = [];
            foreach ((array) ($response->json('keys') ?? []) as $key) {
                if (($key['kty'] ?? '') !== 'RSA' || ! isset($key['n'], $key['e'], $key['kid'])) {
                    continue;
                }
                $out[(string) $key['kid']] = self::rsaPem((string) $key['n'], (string) $key['e']);
            }

            return $out;
        });
    }

    /**
     * Dựng PEM khoá công khai RSA từ modulus + exponent của JWK.
     *
     * Đây là toàn bộ phần "phải tự làm" khi không dùng thư viện JWT: gói `n`/`e`
     * vào một `SubjectPublicKeyInfo` DER rồi base64. Mã ASN.1 dưới đây là dạng
     * cố định cho RSA nên không cần bộ mã hoá tổng quát.
     */
    private static function rsaPem(string $n, string $e): string
    {
        $modulus = (string) self::b64($n);
        $exponent = (string) self::b64($e);

        // INTEGER phải dương: bit cao bật thì thêm byte 0x00 dẫn đầu.
        if (ord($modulus[0]) > 0x7F) {
            $modulus = "\x00".$modulus;
        }

        $seq = self::der(0x02, $modulus).self::der(0x02, $exponent);
        $bitString = self::der(0x03, "\x00".self::der(0x30, $seq));
        // OID 1.2.840.113549.1.1.1 (rsaEncryption) + NULL
        $algo = self::der(0x30, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00");
        $der = self::der(0x30, $algo.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function der(int $tag, string $value): string
    {
        $len = strlen($value);
        if ($len < 0x80) {
            $lenBytes = chr($len);
        } else {
            $hex = ltrim(dechex($len), '0');
            $hex = strlen($hex) % 2 === 1 ? '0'.$hex : $hex;
            $bytes = (string) hex2bin($hex);
            $lenBytes = chr(0x80 | strlen($bytes)).$bytes;
        }

        return chr($tag).$lenBytes.$value;
    }

    private static function b64(string $input): ?string
    {
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
