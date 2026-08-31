<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d slice 2 (#1932) — block chữ do BRAND soạn.
 *
 * Đối ứng của `emitAuthoredText` (workstation `print_renderer.go`). Đây là
 * emitter duy nhất mà nội dung in ra hoàn toàn là lời của brand — câu cảm ơn,
 * dòng giờ mở cửa, dòng khuyến mại. Nó cũng chính là lý do cả cái registry này
 * tồn tại.
 *
 * Vì vậy nó CỐ Ý chỉ hỗ trợ chữ, canh lề và in đậm. Giàu hơn thế thì thành một
 * ngôn ngữ, và một ngôn ngữ trên tờ hoá đơn là một đường để in ra một con số
 * không ai truy được nguồn.
 *
 * ── Canh giữa tính theo bề rộng NỘI DUNG, không giao cho máy in ───────────
 *
 * Nếu gọi chế độ canh giữa của chính máy in, dòng chữ sẽ canh theo bề rộng in
 * được VẬT LÝ và trôi lệch so với mọi dòng khác vốn đang nằm trong lề trái do
 * `setLeftMargin` đặt. Tự đệm khoảng trắng giữ nó cùng khung với phần còn lại.
 *
 * ── Sở hữu ────────────────────────────────────────────────────────────────
 *
 * Ba block của họ bill (`header_text`/`footer_text`/`greeting`) và hai block
 * của họ docs (`footer_text` của `debt_slip` và `table_paid`) cùng trỏ vào một
 * hàm bên Go. Ở PHP nó là class riêng vì cùng lý do: ba hàm chép nhau là ba
 * cách canh lề sẽ trôi khỏi nhau.
 */
final class AuthoredText
{
    /** @param array<string, mixed> $block */
    public static function emit(PrintRenderContext $ctx, array $block): void
    {
        $text = trim(Definition::resolveText($block, $ctx->locale, $ctx->width < 42));

        if ($text === '') {
            return;
        }

        $bold = ($block['bold'] ?? false) === true;

        if ($bold) {
            $ctx->encoder->bold(true);
        }

        $align = (string) ($block['align'] ?? '');

        foreach (Layout::wrapText($text, $ctx->width) as $line) {
            $pad = match ($align) {
                'center' => max(intdiv($ctx->width - Layout::displayWidth($line), 2), 0),
                'right' => max($ctx->width - Layout::displayWidth($line), 0),
                default => 0,
            };

            $ctx->encoder->line(Layout::spaces($pad).$line);
        }

        if ($bold) {
            $ctx->encoder->bold(false);
        }
    }
}
