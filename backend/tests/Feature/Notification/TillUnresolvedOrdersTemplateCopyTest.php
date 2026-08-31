<?php

declare(strict_types=1);

/**
 * #2737 — nửa còn lại của #2721.
 *
 * PR #2735 đã tách `outstanding_count` / `pending_close_count` và hạ
 * `priority` xuống `low` cho ca "đơn kẹt `paying`/`checkout` NHƯNG đã thu đủ
 * tiền". Cái chưa đổi là CÂU CHỮ: template `till.unresolved_orders` nói
 * 「未収 {{outstanding_amount}} {{currency_code}}」, nên ca này in ra
 * 「未収 0 JPY」 — người nhận đọc thành một lỗ tiền không có thật.
 *
 * `TemplateRenderer` không có cú pháp điều kiện (`substitute()` chỉ là một
 * `preg_replace_callback` trên `{{token}}`), nên "copy riêng" bắt buộc là một
 * KEY riêng: `till.unresolved_orders.pending_close`.
 *
 * Bài ghim ở đây đo bản render THẬT với đúng bộ params mà emitter phát ra ở ca
 * đó — kể cả `outstanding_amount = '0'` — và đòi con số 0 KHÔNG được xuất hiện
 * trong bất kỳ locale nào.
 */

use App\Models\NotificationTemplate;
use App\Services\Notification\TemplateRenderer;
use Database\Seeders\SystemNotificationTemplateSeeder;

/**
 * Đúng bộ params `TillSessionService::notifyUnresolvedOrders()` phát ra khi
 * `outstanding_count === 0` (PR #2735). `outstanding_amount` cố ý để '0' —
 * emitter vẫn gửi nó, và cả bài này lẫn người nhận đều không được thấy nó.
 *
 * @return array<string, mixed>
 */
function pendingCloseOnlyParams(): array
{
    return [
        'shop_name' => '本郷店',
        'order_count' => 3,
        'outstanding_amount' => '0',
        'currency_code' => 'JPY',
        'outstanding_order_count' => 0,
        'pending_close_count' => 3,
        'order_codes' => '#A1, #A2, #A3',
    ];
}

function renderPendingCloseTemplate(string $locale): array
{
    app(SystemNotificationTemplateSeeder::class)->run();

    $tpl = NotificationTemplate::query()
        ->where('key', 'till.unresolved_orders.pending_close')
        ->first();

    expect($tpl)->not->toBeNull('Seeder phải tạo key till.unresolved_orders.pending_close');

    return app(TemplateRenderer::class)->render($tpl, pendingCloseOnlyParams(), $locale);
}

it('ja: bản pending-close-only không in số tiền chưa thu và không dùng chữ 未収', function () {
    ['title' => $title, 'body' => $body] = renderPendingCloseTemplate('ja');

    expect($body)->not->toContain('未収')
        ->and($body)->not->toContain('0')          // không con số tiền nào lọt vào
        ->and($body)->not->toContain('JPY')
        ->and($body)->toContain('会計は済んでいます')
        ->and($body)->toContain('不足金はありません')
        ->and($body)->toContain('3 件')            // {{pending_close_count}} đã nội suy
        ->and($title)->toContain('3 件')
        ->and($title)->toContain('本郷店');
});

it('en: bản pending-close-only không nói outstanding và không in số tiền', function () {
    ['title' => $title, 'body' => $body] = renderPendingCloseTemplate('en');

    expect(strtolower($body))->not->toContain('outstanding')
        ->and(strtolower($body))->not->toContain('short of')
        ->and($body)->not->toContain('0')
        ->and($body)->not->toContain('JPY')
        ->and($body)->toContain('Payment is complete')
        ->and($body)->toContain('3 order(s)')
        ->and($title)->toContain('Bills to close: 3');
});

it('vi: bản pending-close-only không nói "còn thiếu" và không in số tiền', function () {
    ['title' => $title, 'body' => $body] = renderPendingCloseTemplate('vi');

    expect($body)->not->toContain('còn thiếu')
        ->and($body)->not->toContain('0')
        ->and($body)->not->toContain('JPY')
        ->and($body)->toContain('Đã thu đủ tiền')
        ->and($body)->toContain('3 đơn')
        ->and($title)->toContain('Đơn chỉ cần đóng: 3');
});

it('mọi {{token}} của bản pending-close-only đều nằm trong params emitter thật sự gửi', function () {
    // Sai một chữ trong tên param thì `substitute()` trả CHUỖI RỖNG và chỉ
    // `Log::warning` — hỏng im lặng, không ai thấy. Bài này bắt nó lúc build.
    app(SystemNotificationTemplateSeeder::class)->run();

    $tpl = NotificationTemplate::query()
        ->where('key', 'till.unresolved_orders.pending_close')
        ->firstOrFail();

    $known = array_keys(pendingCloseOnlyParams());
    $content = (array) $tpl->content;

    foreach (['ja', 'en', 'vi'] as $locale) {
        foreach (['title', 'body'] as $slot) {
            preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', (string) $content[$locale][$slot], $m);
            expect($m[1])->each->toBeIn($known, "{$locale}.{$slot} dùng param emitter không gửi");
        }
    }
});

it('params_schema của bản pending-close-only khai pending_close_count và KHÔNG khai needs_close_only', function () {
    // `needs_close_only` là cờ TỪNG DÒNG đơn trong `$preview['orders']`, không
    // phải param template — khai nó ở đây là mời một chuỗi rỗng vào chuông.
    $tpl = collect(SystemNotificationTemplateSeeder::templates())
        ->firstWhere('key', 'till.unresolved_orders.pending_close');

    expect($tpl)->not->toBeNull();

    $schema = $tpl['params_schema'];
    $declared = array_merge($schema['required'], $schema['optional']);

    expect($schema['required'])->toContain('pending_close_count')
        ->and($schema['required'])->toContain('shop_name')
        ->and($declared)->toContain('outstanding_order_count')
        ->and($declared)->not->toContain('needs_close_only')
        ->and($declared)->not->toContain('outstanding_amount');
});

it('ca thường (còn thiếu tiền thật) vẫn giữ nguyên copy có số tiền', function () {
    app(SystemNotificationTemplateSeeder::class)->run();

    $tpl = NotificationTemplate::query()
        ->where('key', 'till.unresolved_orders')
        ->firstOrFail();

    $rendered = app(TemplateRenderer::class)->render($tpl, [
        'shop_name' => '本郷店',
        'order_count' => 2,
        'outstanding_amount' => '1500',
        'currency_code' => 'JPY',
        'order_codes' => '#B1, #B2',
    ], 'ja');

    expect($rendered['body'])->toContain('未収 1500 JPY')
        ->and($rendered['title'])->toContain('未精算の伝票：2 件');

    // Và bản pending-close-only KHÔNG được thay chỗ của nó.
    $schema = collect(SystemNotificationTemplateSeeder::templates())
        ->firstWhere('key', 'till.unresolved_orders')['params_schema'];

    expect($schema['required'])->toContain('outstanding_amount');
});
