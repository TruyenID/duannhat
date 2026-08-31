<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * #2453 — `$request->ip()` phải trả về NGƯỜI GỌI, không phải edge CDN.
 *
 * ## Đo được gì trên production 2026-08-11
 *
 * Chụp nguyên tập header ở origin (script tạm, đã xoá). Với
 * `trustProxies(at: '*')`:
 *
 *     X-Forwarded-For = 2404:7a82:…:a1e6, 64.252.112.29, 43.206.19.6, 172.64.213.32
 *     REMOTE_ADDR     = 172.64.213.32
 *     $request->ip()  = 43.206.19.6        ← phần tử THỨ BA
 *
 * `'*'` không tin cả chuỗi — nó rẽ sang `setTrustedProxies([REMOTE_ADDR])`, tức
 * chỉ peer trực tiếp. Symfony bỏ cái đã tin rồi trả về cái kế bên. Đó là lý do
 * 100% webhook PayPay bị chối: `PayPayWebhookSourceVerifier` so IP allowlist với
 * một IP edge đổi mỗi request.
 *
 * Ba bài dưới ghim ba mặt của cùng một quyết định: đọc ĐÚNG người gọi, KHÔNG cho
 * giả mạo, và ghi lại rành mạch cái mà cấu hình này CỐ Ý không phủ.
 */
function ipSeenBy(array $server): string
{
    $request = Request::create('/api/v1/webhooks/paypay', 'POST', server: $server);

    (new TrustProxies)->handle($request, fn (Request $r): Request => $r);

    return (string) $request->ip();
}

it('#2453 — qua Cloudflare, IP người gọi được đọc đúng', function () {
    // Chuỗi thật của đường đã chốt cho webhook: PayPay → Cloudflare → origin.
    $ip = ipSeenBy([
        'REMOTE_ADDR' => '172.64.213.32',                 // edge Cloudflare
        'HTTP_X_FORWARDED_FOR' => '203.0.113.77, 172.64.213.32',
    ]);

    expect($ip)->toBe('203.0.113.77');
});

it('#2453 — IPv6 người gọi cũng đọc đúng', function () {
    // Đúng hình dạng đã chụp được: người gọi IPv6, edge IPv4.
    $ip = ipSeenBy([
        'REMOTE_ADDR' => '172.71.8.86',
        'HTTP_X_FORWARDED_FOR' => '2404:7a82:d240:a100:39a0:739d:6bba:a1e6, 172.71.8.86',
    ]);

    expect($ip)->toBe('2404:7a82:d240:a100:39a0:739d:6bba:a1e6');
});

it('#2453 — KHÔNG cho giả mạo: peer lạ thì header bị bỏ qua hoàn toàn', function () {
    // Đây là bài quan trọng nhất. Nếu ai đó "sửa" config thành 0.0.0.0/0 cho
    // tiện, bài này đỏ — vì lúc đó phần tử trái nhất (do client tự khai) sẽ
    // thắng, và IP allowlist của PayPay trở thành thứ bất kỳ ai cũng qua được.
    $ip = ipSeenBy([
        'REMOTE_ADDR' => '198.51.100.9',                  // KHÔNG thuộc dải nào được tin
        'HTTP_X_FORWARDED_FOR' => '203.0.113.77',         // client tự khai
    ]);

    expect($ip)->toBe('198.51.100.9');
});

it('#2453 — chuỗi hai CDN của domain công khai CỐ Ý không phân giải ra người gọi', function () {
    // `tempo.godx.jp/api/*` đi CloudFront → Cloudflare → origin. Chỉ Cloudflare
    // được tin, nên kết quả dừng ở edge CloudFront. Ghim lại để nó là một quyết
    // định có hồ sơ, không phải một điều bất ngờ: webhook đã chốt đăng ký ở
    // `tempo-prod.godx.jp`. Muốn dùng domain công khai thì phải thêm dải
    // CloudFront vào `config/trustedproxy.php` TRƯỚC.
    $ip = ipSeenBy([
        'REMOTE_ADDR' => '172.64.213.32',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.77, 64.252.112.29, 43.206.19.6, 172.64.213.32',
    ]);

    expect($ip)->toBe('43.206.19.6');
});

it('#2453 — reverse proxy cùng máy vẫn được tin (cloudflared / Caddy)', function () {
    // Lý do `trustProxies` tồn tại từ đầu — mất nó thì `isSecure()` false và
    // Set-Cookie tụt SameSite, gãy đăng nhập qua tunnel.
    $ip = ipSeenBy([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.77, 127.0.0.1',
    ]);

    expect($ip)->toBe('203.0.113.77');
});

it('#2453 — TLS ở upstream vẫn được nhận ra qua X-Forwarded-Proto', function () {
    $request = Request::create('/api/v1/webhooks/paypay', 'POST', server: [
        'REMOTE_ADDR' => '172.64.213.32',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.77, 172.64.213.32',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]);

    (new TrustProxies)->handle($request, fn (Request $r): Request => $r);

    expect($request->isSecure())->toBeTrue();
});

it('#2453 — danh sách proxy có hình dạng hợp lệ và không mở toang', function () {
    $proxies = config('trustedproxy.proxies');

    expect($proxies)->toBeArray()->not->toBeEmpty();

    foreach ($proxies as $cidr) {
        expect($cidr)->toBeString();

        // Cấm tuyệt đối hai dải "tin tất cả" — chúng biến phần tử do client tự
        // khai thành nguồn chân lý.
        expect($cidr)->not->toBe('0.0.0.0/0')->not->toBe('::/0')->not->toBe('*');

        [$address] = array_pad(explode('/', (string) $cidr, 2), 2, null);
        expect(filter_var($address, FILTER_VALIDATE_IP))->not->toBeFalse("dải không hợp lệ: {$cidr}");
    }

    // Hai IP edge ĐÃ QUAN SÁT được trên production phải nằm trong danh sách —
    // nếu một lần cập nhật chép thiếu dải `172.64.0.0/13` thì bài này đỏ.
    expect($proxies)->toContain('172.64.0.0/13')->toContain('162.158.0.0/15');
});
