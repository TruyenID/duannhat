<?php

declare(strict_types=1);

use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\PrintJobConfig;
use App\Services\Print\Renderer\PrintLabels;
use App\Services\Print\Renderer\PrintRenderContext;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\StoreInfoBlock;
use App\Services\Print\Renderer\TaxLabels;

/**
 * #2000 — các NHÁNH BIÊN của `store_info.fields`, bản đối xứng của
 * `print_renderer_store_info_edges_test.go`.
 *
 * Sáu ca dưới đây đều đã hành xử đúng trước khi có bài này; điều thiếu là không
 * gì ghim chúng. Chúng cũng là chỗ PHP và Go phải NÓI CÙNG MỘT THỨ — đã đo tay
 * và thấy khớp, nhưng một phép đo tay không sống sót qua lần refactor kế tiếp.
 *
 * Cùng bảng ca, cùng kỳ vọng, hai ngôn ngữ. Lệch nhau ⇒ hai máy in ra hai tờ
 * giấy khác nhau từ cùng một definition, và cổng parity byte chỉ nói "hash không
 * khớp" chứ không nói vì sao.
 */
function storeInfoLines(array $fields, bool $above): array
{
    $config = new PrintJobConfig(
        storeOrganization: 'ORG',
        storeName: 'N',
        storeSubName: 'SUB',
        storeAddress: 'ADDR',
        storePhone: 'TEL',
    );

    $ctx = new PrintRenderContext(
        encoder: new Escpos,
        definition: ['blocks' => [['id' => 'store_info', 'type' => 'params', 'enabled' => true, 'fields' => $fields]]],
        data: new PrintRenderData(kind: 'receipt', config: $config),
        config: $config,
        locale: 'ja',
        width: 48,
        japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'),
        tax: TaxLabels::forLocale('ja'),
    );

    $before = $ctx->encoder->bytes();
    $above ? StoreInfoBlock::emitAbove($ctx) : StoreInfoBlock::emitBelow($ctx);

    $emitted = substr($ctx->encoder->bytes(), strlen($before));

    return array_values(array_filter(explode("\n", $emitted), static fn ($l) => $l !== ''));
}

dataset('storeInfoEdges', [
    // Không có mốc thì mọi field coi như đứng DƯỚI. Giữ đúng hành vi trước khi
    // có phép cắt, nên một definition cũ không đổi bố cục.
    'không có store_name → tất cả xuống dưới' => [
        ['store_sub_name', 'store_address'], [], ['SUB', 'ADDR'],
    ],
    'store_name đứng đầu → tất cả xuống dưới' => [
        ['store_name', 'store_sub_name', 'store_address'], [], ['SUB', 'ADDR'],
    ],
    'store_name đứng cuối → tất cả lên trên' => [
        ['store_sub_name', 'store_address', 'store_name'], ['SUB', 'ADDR'], [],
    ],
    // Khai hai lần thì in hai lần — CỐ Ý. Definition là ý muốn của người thiết
    // kế phiếu; lọc trùng ở đây sẽ là renderer cãi lại template, và "địa chỉ ở
    // cả đầu lẫn cuối" là một bố cục hợp lệ.
    'field lặp → in ở cả hai nửa' => [
        ['store_address', 'store_name', 'store_address'], ['ADDR'], ['ADDR'],
    ],
    // Không ném: một definition mới hơn code KHÔNG được làm hỏng cả phiếu.
    'field lạ → bỏ qua, không ném' => [
        ['store_fax', 'store_name'], [], [],
    ],
    'danh sách rỗng → không in gì' => [
        [], [], [],
    ],
]);

it('cắt trên/dưới theo mốc `store_name`', function (array $fields, array $above, array $below) {
    expect(storeInfoLines($fields, true))->toBe($above)
        ->and(storeInfoLines($fields, false))->toBe($below);
})->with('storeInfoEdges');

it('giá trị RỖNG bị bỏ qua ở mọi vị trí', function () {
    // Đây là thứ giữ cho một quán chưa nhập địa chỉ vẫn ra tờ giấy như cũ sau
    // khi #2000 bước 6 bật header đầy đủ trong bản mặc định.
    $config = new PrintJobConfig(storeName: 'N', storeSubName: 'SUB');

    $ctx = new PrintRenderContext(
        encoder: new Escpos,
        definition: ['blocks' => [['id' => 'store_info', 'type' => 'params', 'enabled' => true, 'fields' => [
            'store_organization', 'store_sub_name', 'store_name', 'store_address', 'store_phone',
        ]]]],
        data: new PrintRenderData(kind: 'receipt', config: $config),
        config: $config,
        locale: 'ja',
        width: 48,
        japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'),
        tax: TaxLabels::forLocale('ja'),
    );

    $before = $ctx->encoder->bytes();
    StoreInfoBlock::emitAbove($ctx);
    StoreInfoBlock::emitBelow($ctx);

    expect(substr($ctx->encoder->bytes(), strlen($before)))->toBe("SUB\n");
});

it('khối TẮT không in gì, kể cả khi khai đủ field', function () {
    $config = new PrintJobConfig(storeSubName: 'SUB', storeAddress: 'ADDR');

    $ctx = new PrintRenderContext(
        encoder: new Escpos,
        definition: ['blocks' => [['id' => 'store_info', 'type' => 'params', 'enabled' => false, 'fields' => [
            'store_sub_name', 'store_name', 'store_address',
        ]]]],
        data: new PrintRenderData(kind: 'receipt', config: $config),
        config: $config,
        locale: 'ja',
        width: 48,
        japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'),
        tax: TaxLabels::forLocale('ja'),
    );

    $before = $ctx->encoder->bytes();
    StoreInfoBlock::emitAbove($ctx);
    StoreInfoBlock::emitBelow($ctx);

    expect($ctx->encoder->bytes())->toBe($before);
});

it('không có khối `store_info` trong definition thì không in gì', function () {
    // Ca này KHÔNG lý thuyết: `void_notice` tắt khối đó, và một brand được phép
    // xoá hẳn nó khỏi definition của mình.
    $config = new PrintJobConfig(storeSubName: 'SUB');

    $ctx = new PrintRenderContext(
        encoder: new Escpos,
        definition: ['blocks' => [['id' => 'title', 'type' => 'text']]],
        data: new PrintRenderData(kind: 'receipt', config: $config),
        config: $config,
        locale: 'ja',
        width: 48,
        japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'),
        tax: TaxLabels::forLocale('ja'),
    );

    $before = $ctx->encoder->bytes();
    StoreInfoBlock::emitAbove($ctx);
    StoreInfoBlock::emitBelow($ctx);

    expect($ctx->encoder->bytes())->toBe($before);
});

it('`fields` không phải mảng thì bỏ qua, không nổ', function () {
    // Definition đến từ JSON của brand; một `fields: "store_name"` (chuỗi thay
    // vì mảng) là lỗi soạn thảo, không phải lý do để cả phiếu không in được.
    $config = new PrintJobConfig(storeSubName: 'SUB');

    $ctx = new PrintRenderContext(
        encoder: new Escpos,
        definition: ['blocks' => [['id' => 'store_info', 'type' => 'params', 'enabled' => true, 'fields' => 'store_name']]],
        data: new PrintRenderData(kind: 'receipt', config: $config),
        config: $config,
        locale: 'ja',
        width: 48,
        japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'),
        tax: TaxLabels::forLocale('ja'),
    );

    $before = $ctx->encoder->bytes();
    StoreInfoBlock::emitAbove($ctx);
    StoreInfoBlock::emitBelow($ctx);

    expect($ctx->encoder->bytes())->toBe($before);
});
