<?php

use App\Services\Print\SystemTemplateDefaults;

/**
 * #2928 — MST tắt trên phiếu BẾP, và CHỈ phiếu bếp.
 *
 * `登録番号` (T+13) là thông tin pháp định của hoá đơn thuế (#1152). Tắt lây sang
 * một phiếu KHÁCH là hỏng nghĩa vụ pháp lý mà không có gì kêu — nên bài này đo
 * cả hai chiều, không chỉ chiều "bếp đã tắt chưa".
 */
function kitchenBlockOf(string $kind, string $block): ?array
{
    foreach (app(SystemTemplateDefaults::class)->forKind($kind)['blocks'] ?? [] as $row) {
        if (($row['id'] ?? null) === $block) {
            return $row;
        }
    }

    return null;
}

it('#2928 phiếu bếp KHÔNG in MST', function () {
    $block = kitchenBlockOf('kitchen', 'registration_number');

    // Khối phải CÒN trong danh mục — tắt chứ không gỡ, để brand bật lại được.
    expect($block)->not->toBeNull();
    expect($block['enabled'] ?? true)->toBeFalse();
});

it('#2928 phiếu KHÁCH vẫn in MST — nghĩa vụ pháp lý, không được tắt lây', function (string $kind) {
    $block = kitchenBlockOf($kind, 'registration_number');

    expect($block)->not->toBeNull("kind {$kind} mất khối registration_number");
    expect($block['enabled'] ?? true)->toBeTrue("kind {$kind} bị tắt MST — đây là chứng từ thuế (#1152)");
})->with(['receipt', 'runner', 'qualified_simplified_invoice', 'red_invoice']);
