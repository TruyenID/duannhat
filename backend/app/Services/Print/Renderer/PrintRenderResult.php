<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d slice 0 (#1897) — kết quả một lượt render: chuỗi byte đầy đủ
 * cộng với các ĐOẠN theo block.
 *
 * Bất biến duy nhất mà mọi thứ khác dựa vào: **ghép `bytes` của các đoạn theo
 * thứ tự phải ra đúng `bytes()`**. Đoạn đầu là `__prologue__` và tính từ OFFSET
 * 0 chứ không phải từ độ dài hiện có của encoder — chuỗi khởi tạo máy in mà
 * encoder ghi lúc dựng thuộc về prologue. Bỏ qua nó thì các đoạn ghép lại
 * không thành phiếu, và đó đúng là thứ mà đường truyền raster (T5.3) dựa vào.
 *
 * Ghim bất biến này TỪ SLICE 0, trước khi có raster, là cố ý: nó rẻ bây giờ và
 * không sửa lại được sau — một renderer đã sinh ra đoạn lệch thì mọi phiếu đã
 * in bằng nó đều không kiểm chứng lại được.
 */
final class PrintRenderResult
{
    /** @param list<PrintRenderSegment> $segments */
    public function __construct(
        public readonly array $segments,
        private readonly string $bytes,
    ) {}

    /** Toàn bộ chuỗi ESC/POS. */
    public function bytes(): string
    {
        return $this->bytes;
    }

    /** Ghép các đoạn lại — phải bằng {@see bytes()}. Dùng để tự kiểm. */
    public function reassembled(): string
    {
        return implode('', array_map(static fn (PrintRenderSegment $s): string => $s->bytes, $this->segments));
    }
}
