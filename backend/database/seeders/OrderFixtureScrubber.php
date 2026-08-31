<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * Ẩn danh fixture ĐƠN HÀNG (`fixtures/orders/*.json`) — bước BẮT BUỘC giữa
 * "chụp production" và "commit" (#2220).
 *
 * Chạy:
 *
 *     php database/seeders/fixtures/orders/_scrub_orders.php
 *
 * ## Vì sao lớp này tồn tại
 *
 * Ảnh chụp 2026-08-05 được commit NGUYÊN BẢN (PR #2219): 11 email nhân viên
 * thật kèm 11 cặp `console_access_token`/`console_refresh_token` phát bởi
 * `id.godx.jp` với `aud: betoya-tempo-production`, 28 tên + 28 số điện thoại
 * khách mang về, và 287 `qr_token` (235 bàn + 52 đơn) — thứ mà chính repo này
 * giấu khỏi mọi API response (`CustomerOrder::$hidden`,
 * `CustomerOrderResource`: *"never expose it through"*). Ai đọc được repo là mở
 * được phiên gọi món của một bàn production.
 *
 * Giá trị của ảnh chụp là **HÌNH DẠNG** — 11 nhân viên · 235 bàn · 6 ca · 20
 * phiên bàn · 52 đơn · 65 dòng · 5 thanh toán, đủ trạng thái và đủ ràng buộc
 * chéo. Con người thật không phải là giá trị đó, nên chỉ họ bị thay.
 *
 * ## Chỉ đụng cột NGƯỜI và cột BÍ MẬT
 *
 * Tiền, thuế, trạng thái, mốc thời gian, id và khoá ngoại giữ NGUYÊN — đó là
 * hình dạng cần bảo toàn, và `tax_rate`/`tax_amount` trên dòng đơn là snapshot
 * bất biến (#1099). Hai cột token bị XOÁ hẳn khỏi JSON, không phải gán null:
 * cột vắng mặt thì seeder không có đường ghi đè token trên một DB đang sống, và
 * `grep console_access_token` trên thư mục fixture trả về 0.
 *
 * ## Xác định, không ngẫu nhiên
 *
 * Giá trị thay thế là hàm của `id` (HMAC-SHA256, khoá cố định) hoặc của vị trí
 * hàng — nên chạy lại cho ra y hệt file, và
 * `tests/Feature/Seeders/SeederFixturesCarryNoProductionSecretsTest.php` kiểm
 * được bằng cách so file trên đĩa với {@see self::scrub()}. Khoá dưới đây KHÔNG
 * phải bí mật: nó chỉ để sinh chuỗi giả ổn định.
 *
 * Không dùng Laravel: script chạy được trên cây làm việc trước khi `git add`.
 */
final class OrderFixtureScrubber
{
    private const SALT = 'tempo-order-fixture-scrub-v1';

    /** Bảng chữ base62 giống `App\Support\QrTokenGenerator`. */
    private const BASE62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** Các file bị ẩn danh. File khác trong thư mục không mang cột người nào. */
    public const FILES = ['users.json', 'customer_orders.json', 'tables.json'];

    public static function directory(): string
    {
        return __DIR__.'/fixtures/orders';
    }

    /**
     * Dạng ĐÃ ẨN DANH của một file fixture — hàm thuần, không ghi đĩa.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function scrub(string $file, array $rows): array
    {
        foreach ($rows as $index => $row) {
            $rows[$index] = match ($file) {
                'users.json' => self::scrubUser($row, $index + 1),
                'customer_orders.json' => self::scrubOrder($row, $index + 1),
                'tables.json' => self::scrubTable($row),
                default => $row,
            };
        }

        return $rows;
    }

    /** Ghi lại cả ba file dưới dạng đã ẩn danh. */
    public static function scrubAll(): void
    {
        foreach (self::FILES as $file) {
            $path = self::directory().'/'.$file;
            $original = (string) file_get_contents($path);
            /** @var array<int, array<string, mixed>> $rows */
            $rows = json_decode($original, true, flags: JSON_THROW_ON_ERROR);

            file_put_contents($path, self::encode(self::scrub($file, $rows), self::indentWidth($original)));

            printf("  %-24s %d hàng\n", $file, count($rows));
        }
    }

    /**
     * Bề rộng thụt lề của file — các file trong thư mục này KHÔNG giống nhau
     * (`users.json` 2, phần còn lại 4). Áp một con số cứng thì diff nuốt trọn
     * file và không ai soi được hàng nào thật sự đổi.
     */
    public static function indentWidth(string $json): int
    {
        preg_match('/\n( +)/', $json, $match);

        return strlen($match[1] ?? '    ');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function encode(array $rows, int $indent): string
    {
        $json = (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // `JSON_PRETTY_PRINT` thụt cứng 4 khoảng trắng. An toàn khi quy đổi:
        // chuỗi JSON không bao giờ chứa xuống dòng thật (\n được escape), nên
        // mọi khoảng trắng đầu dòng đều là thụt lề cấu trúc.
        if ($indent !== 4) {
            $json = (string) preg_replace_callback(
                '/^ +/m',
                static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 4) * $indent),
                $json,
            );
        }

        return $json."\n";
    }

    /** qr_token mới: 32 ký tự base62, đúng hình dạng QrTokenGenerator sinh ra. */
    public static function qrToken(string $rowId): string
    {
        $digest = hash_hmac('sha256', 'qr_token:'.$rowId, self::SALT);
        $token = '';
        for ($i = 0; $i < 32; $i++) {
            $token .= self::BASE62[hexdec($digest[$i * 2].$digest[$i * 2 + 1]) % 62];
        }

        return $token;
    }

    /**
     * Nhân viên thật. `.test` là TLD dành riêng (RFC 2606) nên email sinh ra
     * KHÔNG THỂ là địa chỉ của ai.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function scrubUser(array $row, int $n): array
    {
        unset($row['console_access_token'], $row['console_refresh_token'], $row['console_token_expires_at']);
        $row['name'] = sprintf('Demo Staff %02d', $n);
        $row['email'] = sprintf('staff%02d@example.test', $n);
        $row['remember_token'] = null;

        return $row;
    }

    /**
     * Khách mang về + qr_token của đơn. Giữ nguyên tính NULL: đơn không khai
     * tên thì vẫn không khai — đó cũng là một phần hình dạng.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function scrubOrder(array $row, int $n): array
    {
        if (($row['customer_takeaway_name'] ?? null) !== null) {
            $row['customer_takeaway_name'] = sprintf('Demo Guest %02d', $n);
        }
        if (($row['customer_takeaway_phone'] ?? null) !== null) {
            // `000-` không bao giờ là đầu số hợp lệ ở Nhật hay Việt Nam.
            $row['customer_takeaway_phone'] = sprintf('000-0000-%04d', $n);
        }
        if (($row['customer_takeaway_email'] ?? null) !== null) {
            $row['customer_takeaway_email'] = sprintf('guest%02d@example.test', $n);
        }

        return self::scrubTable($row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function scrubTable(array $row): array
    {
        if (($row['qr_token'] ?? null) !== null) {
            $row['qr_token'] = self::qrToken((string) $row['id']);
        }

        return $row;
    }
}
