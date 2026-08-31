<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d slice 0 (#1897) — một đoạn của phiếu, gắn với block đã sinh ra
 * nó.
 *
 * `blockId` mang hai giá trị canh biên đặc biệt, `__prologue__` và
 * `__epilogue__`, vì hai phần đó không thuộc block nào nhưng vẫn phải nằm
 * trong chuỗi đoạn để phép ghép lại ra đúng phiếu.
 */
final class PrintRenderSegment
{
    public const PROLOGUE = '__prologue__';

    public const EPILOGUE = '__epilogue__';

    public function __construct(
        public readonly string $blockId,
        public readonly string $bytes,
    ) {}
}
