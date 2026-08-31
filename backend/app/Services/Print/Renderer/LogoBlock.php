<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * #1957 mảnh C — emitter cho khối `logo`.
 *
 * MỘT nơi duy nhất, dùng chung cho cả 13 kind. Không phải để gọn: khối này phải
 * phát ra byte **giống hệt** phía Go (`emitLogo` trong print_renderer.go), và
 * mười ba bản sao của cùng một chuỗi lệnh là mười ba cơ hội để một bản lệch đi
 * mà `print_cloud_parity_test` chỉ báo "hash không khớp" chứ không nói bản nào.
 *
 * ## Không có ảnh là chuyện BÌNH THƯỜNG, không phải lỗi (TR-05)
 *
 * Brand chưa tải logo, máy chưa từng online, ảnh gốc mất khỏi storage — cả ba
 * đều kết thúc ở đây: **không phát byte nào**. Phiếu vẫn in, chỉ thiếu khối.
 * Một exception ở đây sẽ leo lên thành "không in được phiếu", tức lấy doanh thu
 * của quán đổi lấy một cái logo.
 *
 * ## TR-40 — bật/tắt phải quyết định TẤT CẢ
 *
 * Khối không có trong definition, hoặc `enabled=false`, thì renderer không gọi
 * tới đây. Và khi tới đây mà không có ảnh, byte phát ra là RỖNG — nên một hệ
 * thống chưa ai tải logo lên in ra byte y hệt hôm nay. Đó là điều khiến mảnh C
 * có thể triển khai mà không đụng vào một quán nào đang chạy.
 */
final class LogoBlock
{
    /**
     * Bề rộng mặc định khi definition không khai `max_width_dots`.
     *
     * Trùng khổ 80mm in được. Không chọn "vừa đúng ảnh": người thiết kế mẫu
     * không khai bề rộng nghĩa là "to hết mức giấy cho phép", còn thu nhỏ theo
     * kích thước tình cờ của tệp được tải lên sẽ khiến đổi ảnh làm đổi bố cục.
     */
    private const DEFAULT_MAX_WIDTH_DOTS = 576;

    /**
     * Phát khối logo, hoặc không phát gì.
     *
     * @param  array<string, mixed>  $block  khối trong definition đã chuẩn hoá
     */
    public static function emit(PrintRenderContext $ctx, array $block): void
    {
        $source = $block['source'] ?? null;
        if (! is_string($source) || $source === '') {
            return;
        }

        $width = self::widthOf($block);

        $image = $ctx->image($source, $width);
        if ($image === null) {
            return;
        }

        // Thứ tự lệnh dưới đây LÀ hợp đồng với phía Go. Đổi nó ở một phía là làm
        // hai bên in ra hai tờ giấy khác nhau từ cùng một definition.
        $ctx->encoder->align(self::alignOf($block));
        $ctx->encoder->raster($image['width'], $image['data']);
        $ctx->encoder->align(Escpos::ALIGN_LEFT);
    }

    /** @param  array<string, mixed>  $block */
    private static function widthOf(array $block): int
    {
        $raw = $block['max_width_dots'] ?? null;

        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return self::DEFAULT_MAX_WIDTH_DOTS;
    }

    /** @param  array<string, mixed>  $block */
    private static function alignOf(array $block): string
    {
        // Mặc định CĂN GIỮA, khác với mọi khối chữ (mặc định trái). Một logo lệch
        // trái trên giấy 80mm trông như lỗi in, và không ai thiết kế phiếu lại
        // muốn thế — nên mặc định phải là thứ người ta sẽ chọn.
        return match ($block['align'] ?? 'center') {
            'left' => Escpos::ALIGN_LEFT,
            'right' => Escpos::ALIGN_RIGHT,
            default => Escpos::ALIGN_CENTER,
        };
    }
}
