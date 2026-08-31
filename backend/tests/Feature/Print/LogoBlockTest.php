<?php

declare(strict_types=1);

use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\LogoBlock;
use App\Services\Print\Renderer\PrintJobConfig;
use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Print\Renderer\PrintLabels;
use App\Services\Print\Renderer\PrintRenderContext;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\TaxLabels;

/**
 * #1957 mảnh C — emitter khối `logo`.
 *
 * Hai điều được canh, theo thứ tự mức thiệt hại:
 *
 * 1. **TR-40** — một hệ thống chưa ai tải logo lên phải in ra byte **y hệt hôm
 *    nay**. Đó là điều khiến mảnh C triển khai được mà không đụng vào một quán
 *    nào đang chạy. Sai chỗ này thì mọi phiếu của mọi quán đổi cùng lúc.
 * 2. **Hợp đồng byte với Go** — thứ tự `align → raster → align(left)`. Lệch một
 *    phía là hai bên in ra hai tờ giấy khác nhau từ cùng một definition, và
 *    `print_cloud_parity_test` chỉ báo "hash không khớp" chứ không nói bản nào.
 */

/** Bitmap 1-bit đóng gói, giống thứ `ImageRasteriser` phát ra. */
function packedRaster(int $widthDots, int $rows): string
{
    return str_repeat(str_repeat("\xF0", intdiv($widthDots + 7, 8)), $rows);
}

/**
 * Phần byte mà emitter THÊM VÀO, không phải toàn bộ luồng.
 *
 * Một `Escpos` mới đã mang sẵn `ESC @` (init) từ constructor, nên so với chuỗi
 * rỗng là so nhầm mốc — và một test so nhầm mốc sẽ xanh vì lý do sai.
 */
function emitted(PrintRenderContext $ctx, callable $fn): string
{
    $before = $ctx->encoder->bytes();
    $fn();

    return substr($ctx->encoder->bytes(), strlen($before));
}

function ctxWithImages(array $images): PrintRenderContext
{
    return new PrintRenderContext(
        encoder: new Escpos,
        definition: ['blocks' => []],
        data: new PrintRenderData(kind: 'receipt', config: new PrintJobConfig),
        config: new PrintJobConfig,
        locale: 'ja',
        width: 48,
        japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'),
        tax: TaxLabels::forLocale('ja'),
        images: $images,
    );
}

it('TR-40 — không có ảnh thì KHÔNG phát byte nào', function () {
    $ctx = ctxWithImages([]);

    $out = emitted($ctx, fn () => LogoBlock::emit($ctx, ['id' => 'logo', 'source' => 'brand_logo', 'max_width_dots' => 576]));

    // Đây là ca quan trọng nhất của cả mảnh C: mọi quán chưa tải logo phải in ra
    // byte y hệt hôm nay. Một lệnh align lạc vào đây cũng là một byte thay đổi
    // trên MỌI phiếu của MỌI quán.
    expect($out)->toBe('');
});

it('không có `source` thì không phát gì', function () {
    $ctx = ctxWithImages(['brand_logo:576' => ['width' => 200, 'data' => packedRaster(200, 4)]]);

    $out = emitted($ctx, fn () => LogoBlock::emit($ctx, ['id' => 'logo', 'max_width_dots' => 576]));

    expect($out)->toBe('');
});

it('có ảnh thì phát align → raster → align(left)', function () {
    $raster = packedRaster(200, 4);
    $ctx = ctxWithImages(['brand_logo:576' => ['width' => 200, 'data' => $raster]]);

    $out = emitted($ctx, fn () => LogoBlock::emit($ctx, ['id' => 'logo', 'source' => 'brand_logo', 'max_width_dots' => 576]));

    expect($out)->toStartWith(Escpos::ALIGN_CENTER)
        ->and($out)->toEndWith(Escpos::ALIGN_LEFT)
        // `GS v 0` — lệnh raster. Nếu byte này biến mất thì logo không in ra.
        ->and($out)->toContain("\x1D\x76\x30");
});

it('mặc định CĂN GIỮA, không phải căn trái như khối chữ', function () {
    $ctx = ctxWithImages(['brand_logo:576' => ['width' => 200, 'data' => packedRaster(200, 2)]]);

    $out = emitted($ctx, fn () => LogoBlock::emit($ctx, ['id' => 'logo', 'source' => 'brand_logo', 'max_width_dots' => 576]));

    // Một logo lệch trái trên giấy 80mm trông như lỗi in. Mặc định phải là thứ
    // người thiết kế phiếu sẽ chọn, không phải thứ rẻ nhất để cài.
    expect($out)->toStartWith(Escpos::ALIGN_CENTER);
});

it('align khai rõ được tôn trọng', function () {
    foreach ([['left', Escpos::ALIGN_LEFT], ['right', Escpos::ALIGN_RIGHT], ['center', Escpos::ALIGN_CENTER]] as [$name, $cmd]) {
        $ctx = ctxWithImages(['brand_logo:576' => ['width' => 200, 'data' => packedRaster(200, 2)]]);

        $out = emitted($ctx, fn () => LogoBlock::emit($ctx, ['id' => 'logo', 'source' => 'brand_logo', 'max_width_dots' => 576, 'align' => $name]));

        expect($out)->toStartWith($cmd, "align={$name}");
    }
});

it('không khai `max_width_dots` thì dùng 576, không phải bề rộng của ảnh', function () {
    // Người thiết kế mẫu không khai bề rộng nghĩa là "to hết mức giấy cho phép".
    // Co theo kích thước tình cờ của tệp được tải lên sẽ khiến đổi ảnh làm đổi
    // bố cục — thứ không ai chờ đợi khi chỉ thay một cái logo.
    $ctx = ctxWithImages(['brand_logo:576' => ['width' => 200, 'data' => packedRaster(200, 2)]]);

    $out = emitted($ctx, fn () => LogoBlock::emit($ctx, ['id' => 'logo', 'source' => 'brand_logo']));

    expect($out)->not->toBe('');
});

it('ảnh ở bề rộng KHÁC không bị dùng nhầm', function () {
    // Khoá là "{source}:{width}". Rơi về một bề rộng khác sẽ in ra một logo sai
    // kích thước mà không báo gì — tệ hơn là không in.
    $ctx = ctxWithImages(['brand_logo:384' => ['width' => 384, 'data' => packedRaster(384, 4)]]);

    $out = emitted($ctx, fn () => LogoBlock::emit($ctx, ['id' => 'logo', 'source' => 'brand_logo', 'max_width_dots' => 576]));

    expect($out)->toBe('');
});

it('`logo` KHÔNG còn nằm trong renderable_debt của bất kỳ kind nào', function () {
    // Ratchet #1949 chỉ cho nợ CO LẠI. Đây là phép đo trực tiếp cho mảnh C.
    foreach ((array) config('print_blocks.renderable_debt') as $kind => $debt) {
        if (! is_array($debt)) {
            continue; // 'kitchen' => '__NO_PLAN__' — nợ ở tầng khác
        }
        expect($debt)->not->toContain('logo', "kind {$kind} vẫn khai logo là nợ");
    }
});

it('mọi kind CÓ PLAN đều có emitter cho `logo`', function () {
    $registry = app(PrintKindRegistry::class);
    $missing = [];

    foreach (array_keys((array) config('print_blocks.renderable_debt')) as $kind) {
        $plan = $registry->planFor((string) $kind);
        if ($plan !== null && $plan->emitterFor('logo') === null) {
            $missing[] = $kind;
        }
    }

    expect($missing)->toBe([], 'kind thiếu emitter logo: '.implode(', ', $missing));
});

it('`max_width_dots` dạng CHUỖI SỐ vẫn được tôn trọng', function () {
    // JSON của definition đi qua nhiều tầng (Cloud → feed → SQLite → Go → lại
    // Cloud cho bản xem trước); một số nguyên quay về thành chuỗi là chuyện
    // thường. Coi "576" khác 576 sẽ lặng lẽ rơi về bề rộng mặc định.
    $ctx = ctxWithImages(['brand_logo:384' => ['width' => 384, 'data' => packedRaster(384, 2)]]);

    $out = emitted($ctx, fn () => LogoBlock::emit($ctx, [
        'id' => 'logo', 'source' => 'brand_logo', 'max_width_dots' => '384',
    ]));

    expect($out)->not->toBe('');
});
