<?php

declare(strict_types=1);

use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\PrintJobConfig;
use App\Services\Print\Renderer\PrintKindPlan;
use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Print\Renderer\PrintLabels;
use App\Services\Print\Renderer\PrintRenderContext;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\PrintRenderer;
use App\Services\Print\Renderer\PrintRenderProfile;
use App\Services\Print\Renderer\PrintRenderSegment;
use App\Services\Print\Renderer\TaxLabels;

/**
 * plan-053 T5.1d slice 0 (#1897) — máy dispatch.
 *
 * Dùng plan GIẢ có chủ đích. Slice 0 không port emitter nào, nên kiểm cỗ máy
 * bằng emitter thật là chuyện của slice 1-3; thứ phải chứng minh ở đây là các
 * nhánh QUYẾT ĐỊNH — cái nào được vẽ, theo thứ tự nào, và cái gì xảy ra khi
 * definition nói thứ mà code không biết.
 *
 * Mỗi ca dưới đây ghim một nhánh mà bên Go có, và ghim vì lý do vận hành chứ
 * không vì độ phủ.
 */
function fakePlan(array $emitterIds, ?callable $prologue = null, ?callable $epilogue = null, int $width = 48): PrintKindPlan
{
    $emitters = [];

    foreach ($emitterIds as $id) {
        $emitters[$id] = static function (PrintRenderContext $c) use ($id): void {
            $c->encoder->line("[{$id}]");
        };
    }

    return new PrintKindPlan(
        defaultWidth: $width,
        emitters: $emitters,
        prologue: $prologue,
        epilogue: $epilogue,
    );
}

function rendererWith(string $kind, PrintKindPlan $plan): PrintRenderer
{
    $registry = new PrintKindRegistry;
    $registry->register($kind, $plan);

    return new PrintRenderer($registry);
}

function dataFor(string $kind, PrintJobConfig $config = new PrintJobConfig): PrintRenderData
{
    return new PrintRenderData(kind: $kind, config: $config);
}

it('#1897 kind không có plan thì NÉM, không trả về phiếu vẽ dở', function () {
    // Người gọi trả lời lỗi này bằng cách rơi về bản mặc định nhúng sẵn
    // (TR-14). Một phiếu in ra thiếu nửa dưới tệ hơn một phiếu không in ra:
    // cái thứ hai thì thu ngân biết, cái thứ nhất thì khách cầm đi.
    $renderer = rendererWith('receipt', fakePlan(['title']));

    expect(fn () => $renderer->render(['blocks' => []], dataFor('debt_slip'), new PrintRenderProfile, 'ja'))
        ->toThrow(RuntimeException::class);
});

it('#1897 kind lấy từ definition khi data không nói', function () {
    $renderer = rendererWith('receipt', fakePlan(['title']));

    $result = $renderer->render(
        ['kind' => 'receipt', 'blocks' => [['id' => 'title']]],
        new PrintRenderData(kind: '', config: new PrintJobConfig),
        new PrintRenderProfile,
        'ja',
    );

    expect($result->bytes())->toContain('[title]');
});

it('#1897 block bị TẮT thì không vẽ', function () {
    $renderer = rendererWith('receipt', fakePlan(['title', 'footer_text']));

    $result = $renderer->render(
        ['blocks' => [
            ['id' => 'title'],
            ['id' => 'footer_text', 'enabled' => false],
        ]],
        dataFor('receipt'),
        new PrintRenderProfile,
        'ja',
    );

    expect($result->bytes())->toContain('[title]')
        ->and($result->bytes())->not->toContain('[footer_text]');
});

it('#1897 block KHÔNG khai enabled thì mặc định BẬT', function () {
    // Mặc định ngược lại làm mọi definition viết trước khi có cờ in ra giấy
    // trắng — và nó sẽ trắng ở quán, không trắng ở đây.
    $renderer = rendererWith('receipt', fakePlan(['title']));

    $result = $renderer->render(['blocks' => [['id' => 'title']]], dataFor('receipt'), new PrintRenderProfile, 'ja');

    expect($result->bytes())->toContain('[title]');
});

it('#1897 block LẠ bị bỏ qua chứ không làm hỏng cả phiếu', function () {
    // Bất đối xứng CÓ CHỦ ĐÍCH so với "kind không có plan": thiếu một block là
    // mất một phần phiếu; thiếu cả plan là không biết phiếu này trông ra sao.
    $renderer = rendererWith('receipt', fakePlan(['title']));

    $result = $renderer->render(
        ['blocks' => [
            ['id' => 'title'],
            ['id' => 'block_tu_ban_code_moi_hon'],
        ]],
        dataFor('receipt'),
        new PrintRenderProfile,
        'ja',
    );

    expect($result->bytes())->toContain('[title]');
});

it('#1897 thứ tự in theo DEFINITION, không theo thứ tự đăng ký emitter', function () {
    // Thứ tự block là quyết định của brand. Nếu renderer đi theo thứ tự đăng
    // ký thì trình soạn thảo template thành đồ trang trí.
    $renderer = rendererWith('receipt', fakePlan(['a', 'b', 'c']));

    $result = $renderer->render(
        ['blocks' => [['id' => 'c'], ['id' => 'a'], ['id' => 'b']]],
        dataFor('receipt'),
        new PrintRenderProfile,
        'ja',
    );

    $order = array_map(
        static fn (PrintRenderSegment $s): string => $s->blockId,
        array_values(array_filter(
            $result->segments,
            static fn (PrintRenderSegment $s): bool => ! str_starts_with($s->blockId, '__'),
        )),
    );

    expect($order)->toBe(['c', 'a', 'b']);
});

it('#1897 prologue chạy TRƯỚC mọi block, epilogue chạy SAU', function () {
    $renderer = rendererWith('receipt', fakePlan(
        ['title'],
        prologue: static fn (PrintRenderContext $c) => $c->encoder->line('<pro>'),
        epilogue: static fn (PrintRenderContext $c) => $c->encoder->line('<epi>'),
    ));

    $result = $renderer->render(['blocks' => [['id' => 'title']]], dataFor('receipt'), new PrintRenderProfile, 'ja');
    $bytes = $result->bytes();

    expect(strpos($bytes, '<pro>'))->toBeLessThan(strpos($bytes, '[title]'))
        ->and(strpos($bytes, '[title]'))->toBeLessThan(strpos($bytes, '<epi>'));
});

it('#1897 GHÉP các đoạn lại phải ra ĐÚNG phiếu — bất biến của đường raster', function () {
    // Đoạn đầu tính từ offset 0 chứ không từ độ dài hiện có của encoder: chuỗi
    // khởi tạo máy in mà `new Escpos` vừa ghi thuộc về prologue. Bỏ qua nó thì
    // các đoạn ghép lại KHÔNG thành phiếu — và đó đúng là thứ T5.3 sẽ dựa vào.
    $renderer = rendererWith('receipt', fakePlan(
        ['a', 'b'],
        prologue: static fn (PrintRenderContext $c) => $c->encoder->line('<pro>'),
        epilogue: static fn (PrintRenderContext $c) => $c->encoder->fullCut(),
    ));

    $result = $renderer->render(
        ['blocks' => [['id' => 'a'], ['id' => 'b']]],
        dataFor('receipt'),
        new PrintRenderProfile,
        'ja',
    );

    expect($result->reassembled())->toBe($result->bytes());
});

it('#1897 block không sinh byte nào thì KHÔNG tạo đoạn rỗng', function () {
    $registry = new PrintKindRegistry;
    $registry->register('receipt', new PrintKindPlan(
        defaultWidth: 48,
        emitters: [
            'noisy' => static fn (PrintRenderContext $c) => $c->encoder->line('x'),
            'silent' => static function (): void {},
        ],
    ));

    $result = (new PrintRenderer($registry))->render(
        ['blocks' => [['id' => 'silent'], ['id' => 'noisy']]],
        dataFor('receipt'),
        new PrintRenderProfile,
        'ja',
    );

    $ids = array_map(static fn (PrintRenderSegment $s): string => $s->blockId, $result->segments);

    expect($ids)->not->toContain('silent')
        ->and($ids)->toContain('noisy');
});

it('#1897 thang giải bề rộng: profile → config → paper của definition → mặc định kind', function (
    PrintRenderProfile $profile,
    PrintJobConfig $config,
    array $definition,
    int $expected,
) {
    $seen = 0;
    $registry = new PrintKindRegistry;
    $registry->register('receipt', new PrintKindPlan(
        defaultWidth: 42,
        emitters: ['w' => static function (PrintRenderContext $c) use (&$seen): void {
            $seen = $c->width;
        }],
    ));

    (new PrintRenderer($registry))->render(
        $definition + ['blocks' => [['id' => 'w']]],
        dataFor('receipt', $config),
        $profile,
        'ja',
    );

    expect($seen)->toBe($expected);
})->with([
    // Máy in đứng trước cấu hình quán vì nó là sự thật VẬT LÝ.
    'profile thắng tất cả' => [new PrintRenderProfile(columns: 32), new PrintJobConfig(paperWidth: 40), ['paper' => ['columns_58mm' => 30]], 32],
    'config khi profile im' => [new PrintRenderProfile, new PrintJobConfig(paperWidth: 40), ['paper' => ['columns_58mm' => 30]], 40],
    'paper của definition khi cả hai im' => [new PrintRenderProfile(paper: '58mm'), new PrintJobConfig, ['paper' => ['columns_58mm' => 30]], 30],
    'mặc định của kind khi không ai nói' => [new PrintRenderProfile, new PrintJobConfig, [], 42],
]);

it('#1897 locale lạ được chuẩn hoá về ja TRƯỚC khi tới emitter', function () {
    // Emitter không được tự chuẩn hoá: hai emitter chuẩn hoá khác nhau là cách
    // một phiếu in ra nửa tiếng Nhật nửa tiếng Anh.
    $seen = '';
    $registry = new PrintKindRegistry;
    $registry->register('receipt', new PrintKindPlan(
        defaultWidth: 48,
        emitters: ['l' => static function (PrintRenderContext $c) use (&$seen): void {
            $seen = $c->locale;
        }],
    ));

    (new PrintRenderer($registry))->render(
        ['blocks' => [['id' => 'l']]],
        dataFor('receipt'),
        new PrintRenderProfile,
        'ja-JP',
    );

    expect($seen)->toBe('ja');
});

it('#1897 japaneseDoc tới từ KIND, không từ locale (#1493)', function () {
    // Trước #1493 các emitter rẽ theo `locale == "ja"`, nên NGÔN NGỮ GIAO DIỆN
    // của thu ngân quyết định CHỨNG TỪ NÀO được in: quán Việt để tiếng Nhật ra
    // chứng từ Nhật. Ca này ghim rằng trục đó đã tách.
    $seen = null;
    $registry = new PrintKindRegistry;
    $registry->register('qualified_simplified_invoice', new PrintKindPlan(
        defaultWidth: 48,
        emitters: ['j' => static function (PrintRenderContext $c) use (&$seen): void {
            $seen = $c->japaneseDoc;
        }],
        japaneseDoc: true,
    ));

    // Locale VIỆT, kind Nhật ⇒ vẫn là chứng từ Nhật.
    (new PrintRenderer($registry))->render(
        ['blocks' => [['id' => 'j']]],
        dataFor('qualified_simplified_invoice'),
        new PrintRenderProfile,
        'vi',
    );

    expect($seen)->toBeTrue();
});

it('#1897 reprintNumber âm bị kẹp về 0 ở MỘT chỗ, không phải ở từng emitter', function () {
    // Hai emitter cùng đọc số này (dấu bản sao của họ bill và của họ chứng
    // từ). Một cái tự kẹp còn cái kia không là cách hai tờ giấy của cùng một
    // lần in mang hai con số.
    $context = new PrintRenderContext(
        encoder: new Escpos,
        definition: [],
        data: new PrintRenderData(kind: 'receipt', config: new PrintJobConfig, reprintNumber: -3),
        config: new PrintJobConfig,
        locale: 'ja',
        width: 48,
        japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'),
        tax: TaxLabels::forLocale('ja'),
    );

    expect($context->reprintNumber())->toBe(0);
});
