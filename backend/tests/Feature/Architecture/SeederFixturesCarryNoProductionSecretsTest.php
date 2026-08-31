<?php

declare(strict_types=1);

use Database\Seeders\OrderFixtureScrubber;

/**
 * Fixture seeder được commit vào git và git thì không quên gì cả — nên một ảnh
 * chụp production dán thẳng vào đây là sự cố, không phải lỗi phong cách.
 *
 * Đã xảy ra thật (#2220 / PR #2219): `fixtures/orders` mang 11 email nhân viên
 * kèm 11 cặp access/refresh token còn sống của `id.godx.jp`
 * (`aud: betoya-tempo-production`), 28 tên + số điện thoại khách, và 287
 * `qr_token` — token mà `CustomerOrder::$hidden` và `CustomerOrderResource`
 * cố tình không cho lọt ra bất kỳ API response nào. Revert commit KHÔNG thu hồi
 * được thứ đã lộ, nên rào phải nằm TRƯỚC lúc commit.
 *
 * Hai lớp kiểm, cố ý khác nhau:
 *
 * 1. **Hình dạng bí mật** — bắt được JWT, cột token, email/điện thoại thật.
 * 2. **So với bản ẩn danh** — bắt được thứ lớp 1 KHÔNG thể bắt: `qr_token`
 *    production và `qr_token` giả trông y hệt nhau (base62 32 ký tự). Chỉ có
 *    cách so với {@see OrderFixtureScrubber::scrub()} mới biết ai chưa chạy
 *    scrubber.
 *
 * ## Nợ CÓ TÊN, ngoài rào (#2241 — gỡ đoạn này khi nó đóng)
 *
 * `catalog/tables.json` mang **235 `qr_token` production còn sống** (trùng khít
 * tập vừa gỡ khỏi `orders/tables.json` — hai bản chụp của cùng 235 bàn). KHÔNG
 * phép kiểm nào ở đây bắt được nó: lớp 1 mù với qr_token, lớp 2 chỉ phủ
 * `OrderFixtureScrubber::FILES` (orders/). Và KHÔNG dọn kiểu #2220 được:
 * `CatalogSnapshotSeeder` chạy nhánh production và upsert `qr_token` — sinh lại
 * fixture là xoay mã QR đã dán trên bàn thật. #2241 giữ việc đó.
 * (`brands.json`/#2230 từng nằm cùng đợt này nhưng hoá ra là dữ liệu chết —
 * đã XOÁ, xem `_dump_catalog.php`.)
 *
 * File này nằm ở `tests/Feature/Architecture/` CÓ CHỦ ĐÍCH (#2220 review vòng
 * 1): đó là thư mục duy nhất `arch-gate` chạy trên MỌI PR vào `dev` — ratchet
 * nằm ngoài nó chỉ nổ ở lượt `dev → main`, tức SAU khi bí mật đã published,
 * đúng đường PR #2219 đã đi.
 */
$fixtureFiles = static fn (): array => array_merge(
    glob(database_path('seeders/fixtures/orders/*.json')) ?: [],
    glob(database_path('seeders/fixtures/catalog/*.json')) ?: [],
);

it('carries no JSON Web Token in any seeder fixture', function () use ($fixtureFiles) {
    // #2230 ĐÃ XONG: `catalog/brands.json` (ngoại lệ có tên duy nhất của bài
    // này — mang `reverb_app_secret` Laravel-encrypt, cũng mở đầu `eyJ`) hoá ra
    // là DỮ LIỆU CHẾT — không seeder nào đọc nó — nên bị XOÁ hẳn cùng entry
    // 'brands' trong `_dump_catalog.php`. Không còn ngoại lệ nào: mọi fixture
    // đều phải sạch `eyJ`.
    $offenders = [];

    foreach ($fixtureFiles() as $path) {
        // `eyJ` là base64 của `{"` — mọi JWT bắt đầu như vậy.
        if (preg_match('/"eyJ[A-Za-z0-9_-]{20,}/', (string) file_get_contents($path)) === 1) {
            $offenders[] = basename($path);
        }
    }

    expect($offenders)->toBe([]);
});

it('carries no console token column in the order fixtures', function () use ($fixtureFiles) {
    foreach ($fixtureFiles() as $path) {
        $contents = (string) file_get_contents($path);

        foreach (['console_access_token', 'console_refresh_token'] as $column) {
            expect(str_contains($contents, $column))->toBeFalse(
                sprintf('%s vẫn mang cột %s — chạy _scrub_orders.php', basename($path), $column),
            );
        }
    }
});

it('carries no address outside the reserved example domains', function () use ($fixtureFiles) {
    $found = [];

    foreach ($fixtureFiles() as $path) {
        preg_match_all('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', (string) file_get_contents($path), $matches);
        foreach ($matches[0] as $address) {
            // RFC 2606 giữ riêng example.{test,com,net,org} — địa chỉ ở đó
            // không thể thuộc về ai.
            if (preg_match('/@(.+\.)?example\.(test|com|net|org)$/', $address) !== 1) {
                $found[] = $address;
            }
        }
    }

    expect(array_values(array_unique($found)))->toBe([]);
});

it('carries no dialable phone number', function () use ($fixtureFiles) {
    $found = [];

    foreach ($fixtureFiles() as $path) {
        // Nhóm giữa 2–4 chữ số, không phải 3–4: ảnh chụp thật mang cả
        // `0966-50-6438` (đầu số cố định 4 chữ số), và một regex hẹp hơn bỏ sót
        // đúng 13 trong 28 số — đo trên chính bản chụp gốc.
        preg_match_all('/"(0\d{1,4}-\d{2,4}-\d{4})"/', (string) file_get_contents($path), $matches);
        foreach ($matches[1] as $number) {
            // `000-` không phải đầu số hợp lệ ở bất kỳ đâu — đó là chuỗi giả
            // mà scrubber sinh ra.
            if (! str_starts_with($number, '000-')) {
                $found[] = $number;
            }
        }
    }

    expect(array_values(array_unique($found)))->toBe([]);
});

it('matches its own anonymised form byte for byte', function (string $file) {
    $path = OrderFixtureScrubber::directory().'/'.$file;
    $onDisk = (string) file_get_contents($path);

    /** @var array<int, array<string, mixed>> $rows */
    $rows = json_decode($onDisk, true, flags: JSON_THROW_ON_ERROR);

    $scrubbed = OrderFixtureScrubber::encode(
        OrderFixtureScrubber::scrub($file, $rows),
        OrderFixtureScrubber::indentWidth($onDisk),
    );

    expect($onDisk)->toBe(
        $scrubbed,
        sprintf(
            '%s lệch với bản ẩn danh — ảnh chụp mới chưa qua '
            .'`php database/seeders/fixtures/orders/_scrub_orders.php`. '
            .'qr_token production và qr_token giả trông giống hệt nhau, nên đây là '
            .'phép kiểm DUY NHẤT bắt được chúng.',
            $file,
        ),
    );
})->with(OrderFixtureScrubber::FILES);
