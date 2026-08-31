<?php

declare(strict_types=1);

use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Print\TemplateValidator;

/**
 * #1949 — catalog và registry là HAI danh sách, và trước bài này không cổng nào
 * so chúng.
 *
 * ── Vì sao ba cổng đang có đều mù chỗ này ────────────────────────────────
 *
 * `PrintContractParityTest`   so registry PHP ↔ registry Go — hai bên khớp NHAU,
 *                             nên cùng thiếu một block thì vẫn xanh.
 * `print_cloud_parity_test`   render bản export rồi so HASH — hai bên cùng bỏ
 *                             qua block lạ, nên hash vẫn khớp.
 * lượt render thử lúc publish CHẠY THÀNH CÔNG; nó chỉ không vẽ gì.
 *
 * Kết quả: một block có trong catalog mà không renderer nào vẽ đi qua toàn bộ
 * hệ thống mà không chỗ nào kêu. Người phát hiện là chủ quán cầm tờ giấy thiếu
 * một khối.
 *
 * ── Bài này ghim NỢ, không đòi sạch ─────────────────────────────────────
 *
 * Danh sách dưới đây là hiện trạng đo được, SINH bằng chính phép so, không gõ
 * tay. Nó chỉ được CO LẠI:
 *
 *   - thêm một block vào catalog mà quên emitter  ⇒ ĐỎ
 *   - viết emitter cho một block đang nợ mà quên hạ danh sách  ⇒ ĐỎ
 *
 * Chiều thứ hai quan trọng ngang chiều thứ nhất: không có nó, nợ đã trả rồi vẫn
 * nằm trong danh sách và chỗ đó thôi được canh.
 *
 * Cổng CHẶN người dùng nằm ở {@see TemplateValidator}
 * (`BLOCK_NOT_RENDERABLE`) — nó chỉ chặn block đang BẬT. Bài này canh cả block
 * đang tắt, vì một khối tắt hôm nay là khối ai đó bật ngày mai.
 */

/** Kind chưa có plan nào trong registry — nợ ở tầng khác, không phải thiếu emitter. */
const NO_PLAN = '__NO_PLAN__';

/** Nợ đã biết — nguồn DUY NHẤT là config, dùng chung với TemplateValidator. */
function catalogRenderableDebt(): array
{
    return (array) config('print_blocks.renderable_debt', []);
}

/** @return array<string, list<string>|string> kind => block thiếu emitter */
function catalogRenderableGaps(): array
{
    $kinds = (array) config('print_blocks.kinds', []);
    $shape = app(PrintKindRegistry::class)->toContractShape();
    $gaps = [];

    foreach ($kinds as $kind => $spec) {
        $emitters = $shape[$kind]['blocks'] ?? null;

        if ($emitters === null) {
            $gaps[$kind] = NO_PLAN;

            continue;
        }

        $missing = array_values(array_diff((array) ($spec['blocks'] ?? []), $emitters));

        if ($missing !== []) {
            $gaps[$kind] = $missing;
        }
    }

    ksort($gaps);

    return $gaps;
}

it('#1949 không kind nào mọc thêm block thiếu emitter', function () {
    $gaps = catalogRenderableGaps();

    $new = [];
    foreach ($gaps as $kind => $missing) {
        $allowed = catalogRenderableDebt()[$kind] ?? [];

        if ($missing === NO_PLAN) {
            if ($allowed !== NO_PLAN) {
                $new[] = "{$kind}: chưa có plan trong registry";
            }

            continue;
        }

        $extra = is_array($allowed) ? array_diff($missing, $allowed) : $missing;

        foreach ($extra as $block) {
            $new[] = "{$kind}.{$block}";
        }
    }

    expect($new)->toBe([], implode("\n", [
        'Catalog khai block mà không renderer nào vẽ. Nó sẽ biến mất khỏi tờ giấy',
        'mà KHÔNG có lỗi nào — viết emitter, hoặc bỏ block khỏi kind trong',
        '`config/print_blocks.php`. Xem #1949.',
        ...$new,
    ]));
});

it('#1949 nợ đã trả phải được hạ khỏi danh sách', function () {
    $gaps = catalogRenderableGaps();

    $stale = [];
    foreach (catalogRenderableDebt() as $kind => $allowed) {
        $actual = $gaps[$kind] ?? [];

        if ($allowed === NO_PLAN) {
            if ($actual !== NO_PLAN) {
                $stale[] = "{$kind}: giờ ĐÃ có plan — bỏ dòng NO_PLAN";
            }

            continue;
        }

        $fixed = is_array($actual) ? array_diff($allowed, $actual) : $allowed;

        foreach ($fixed as $block) {
            $stale[] = "{$kind}.{$block}: đã có emitter — bỏ khỏi danh sách nợ";
        }
    }

    expect($stale)->toBe([], implode("\n", [
        'Danh sách nợ đã cũ. Không hạ thì chỗ vừa dọn thôi được canh:',
        ...$stale,
    ]));
});

it('#1949 danh sách nợ KHÔNG rỗng — rỗng nghĩa là phép đo hỏng', function () {
    // Không có ca này thì một `catalogRenderableGaps()` trả rỗng (config đổi
    // hình dạng, registry không nạp được) làm cả hai bài trên xanh vĩnh viễn.
    expect(catalogRenderableDebt())->not->toBe([]);
    expect((array) config('print_blocks.kinds', []))->not->toBe([]);
});
