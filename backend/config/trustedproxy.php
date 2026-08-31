<?php

declare(strict_types=1);

/**
 * #2453 — proxy nào được phép nói hộ IP người gọi.
 *
 * ## Vì sao KHÔNG dùng `'*'`
 *
 * `trustProxies(at: '*')` nghe như "tin cả chuỗi" nhưng Laravel rẽ sang
 * `setTrustedProxyIpAddressesToTheCallingIp()` → `setTrustedProxies([REMOTE_ADDR])`:
 * **chỉ peer trực tiếp**. Symfony duyệt `X-Forwarded-For` từ phải sang, bỏ proxy
 * đã tin, trả về cái đầu tiên chưa tin — nên qua CDN nó trả về **IP edge**.
 *
 * Chụp header thật ở origin production 2026-08-11:
 *
 *     X-Forwarded-For = 2404:7a82:…:a1e6, 64.252.112.29, 43.206.19.6, 172.64.213.32
 *     REMOTE_ADDR     = 172.64.213.32          ← chỉ cái này được tin
 *     $request->ip()  = 43.206.19.6            ← phần tử THỨ BA, không phải người gọi
 *
 * ## Vì sao KHÔNG dùng `['0.0.0.0/0', '::/0']`
 *
 * Tin tất cả thì Symfony lấy phần tử **trái nhất** của chuỗi — mà phần tử trái
 * nhất là thứ **client tự gửi**. Kẻ tấn công chỉ cần đặt
 * `X-Forwarded-For: <IP của PayPay>` là qua allowlist. Với PayPay OPA, IP **là**
 * phép xác thực duy nhất (họ không ký payload), nên đó là biến một lỗi đọc IP
 * thành một lỗ hổng bỏ qua xác thực.
 *
 * ## Vì sao chỉ Cloudflare
 *
 * Origin `tempo-prod.godx.jp` **luôn** đứng sau Cloudflare (đo được:
 * `REMOTE_ADDR = 172.71.8.86`, một dải Cloudflare, kể cả khi gọi thẳng host
 * origin). Webhook PayPay đăng ký ở host này theo quyết định 2026-08-11, nên
 * chuỗi chỉ có MỘT tầng proxy:
 *
 *     PayPay → Cloudflare → origin
 *
 * ⚠️ Domain công khai `tempo.godx.jp` thì KHÁC: `/api/*` ở đó đi
 * **CloudFront → Cloudflare → origin** (hai CDN khác nhau trên cùng một domain;
 * `/` gốc là CloudFront phục vụ Next.js). Với danh sách này, request qua đường
 * đó sẽ phân giải ra **IP edge CloudFront**, không phải người gọi. Đó là hệ quả
 * CÓ CHỦ Ý của việc chốt webhook đi thẳng origin — muốn dùng domain công khai
 * cho webhook thì phải thêm dải CloudFront vào đây trước.
 *
 * ## Cập nhật danh sách
 *
 * Nguồn chính chủ: https://www.cloudflare.com/ips-v4 · https://www.cloudflare.com/ips-v6
 * Chụp 2026-08-11 (15 dải v4, 7 dải v6). Cloudflare đổi rất hiếm và có thông báo
 * trước; `TrustedProxiesTest` khẳng định hình dạng CIDR và khẳng định các IP edge
 * đã quan sát được nằm trong danh sách, nên một lần chép sai sẽ đỏ chứ không âm
 * thầm mở toang.
 */
return [

    'proxies' => [

        // ── Cloudflare IPv4 ────────────────────────────────────────────────
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',

        // ── Cloudflare IPv6 ────────────────────────────────────────────────
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',

        // ── Reverse proxy chạy CÙNG MÁY ────────────────────────────────────
        // Lý do `trustProxies` tồn tại từ đầu: cloudflared và Caddy kết thúc TLS
        // ở local, và thiếu chúng thì `$request->isSecure()` trả false ⇒
        // `Set-Cookie` tụt từ `SameSite=None; Secure` xuống `Lax` và mọi
        // triển khai qua tunnel gãy đăng nhập.
        //
        // An toàn trong production: origin chỉ nhận kết nối từ Internet qua
        // Cloudflare, nên không request ngoài nào có thể mang peer là địa chỉ
        // loopback hay dải riêng.
        '127.0.0.1',
        '::1',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fc00::/7',
    ],

];
