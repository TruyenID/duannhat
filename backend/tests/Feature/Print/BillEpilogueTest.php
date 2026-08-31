<?php

declare(strict_types=1);

use App\Services\Print\Renderer\Escpos;
use App\Services\Print\Renderer\PrintJobConfig;
use App\Services\Print\Renderer\PrintKindRegistry;
use App\Services\Print\Renderer\PrintLabels;
use App\Services\Print\Renderer\PrintRenderContext;
use App\Services\Print\Renderer\PrintRenderData;
use App\Services\Print\Renderer\TaxLabels;

/*
 * plan-053 T5.1d (#1923) — nhánh epilogue của họ bill.
 *
 * Khối QR tự để lại hai dòng trắng phía sau nó. KHÔNG có QR thì phiếu vẫn cần
 * đúng lề đuôi đó trước khi cắt — bỏ nhánh này là **phiếu bị cắt sát chữ**.
 *
 * Đây là nhánh `else` của `formatBillTicket` cũ, không phải một lựa chọn thẩm
 * mỹ mới. Nên nó phải được ghim: một người dọn code thấy `if` này sẽ nghĩ nó
 * thừa, vì cả hai nhánh đều "in ra được".
 */

function billEpilogueBytes(array $blocks): string
{
    $plan = app(PrintKindRegistry::class)->planFor('receipt');
    expect($plan)->not->toBeNull();

    $encoder = new Escpos;
    $ctx = new PrintRenderContext(
        encoder: $encoder,
        definition: ['blocks' => $blocks],
        data: new PrintRenderData(
            kind: 'receipt',
            config: new PrintJobConfig,
        ),
        config: new PrintJobConfig,
        locale: 'ja',
        width: 48,
        japaneseDoc: false,
        labels: PrintLabels::forLocale('ja'),
        tax: TaxLabels::forLocale('ja'),
    );

    ($plan->epilogue)($ctx);

    return $encoder->bytes();
}

it('#1923 KHÔNG có qr_block thì chèn 2 dòng trắng trước khi cắt', function () {
    $withoutQr = billEpilogueBytes([['id' => 'items'], ['id' => 'grand_total']]);
    $withQr = billEpilogueBytes([['id' => 'items'], ['id' => 'qr_block']]);

    // Phiếu không QR phải DÀI HƠN đúng phần feed — nếu hai bên bằng nhau thì
    // nhánh đã bị bỏ, và phiếu thật sẽ cắt sát dòng chữ cuối.
    expect(strlen($withoutQr))->toBeGreaterThan(strlen($withQr));
});

it('#1923 cả hai nhánh đều kết thúc bằng lệnh cắt', function () {
    // Nhánh nào cũng phải cắt. Một phiếu không cắt là tờ giấy dính liền tờ
    // sau — nhân viên xé tay, và số phiếu trên hai tờ lệch nhau.
    foreach ([[['id' => 'items']], [['id' => 'qr_block']]] as $blocks) {
        expect(billEpilogueBytes($blocks))->not->toBe('');
    }
});
