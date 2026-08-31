<?php

/**
 * #3082 — phiếu bếp: dòng trắng đầu phiếu (+ nửa "cao gấp đôi", ĐÃ GỠ).
 *
 * ## Ca thật
 *
 * Bếp KẸP phiếu vào thanh kẹp, và thanh kẹp che mất dòng đầu. Phiếu đã có sẵn
 * `slipTopPadding` = 3 dòng trắng và thực tế cho thấy **chưa đủ** — chủ dự án
 * chốt 2026-08-17: thêm 3 dòng nữa (tổng 6).
 *
 * Bản đầu của #3082 còn cho dòng MÓN cao gấp đôi, "vì đó là thứ đầu bếp đọc từ
 * xa". Nửa đó đã **gỡ** cùng ngày theo ruling sau: dòng món phiếu bếp phải BẰNG
 * phiếu hall. Bài dưới giữ lại để ghim chiều ngược — xem nó.
 *
 * ## Vì sao bài này tồn tại khi đã có golden
 *
 * Golden ghim BYTE của phiếu, nên gỡ `top_feed` đi thì nó đỏ. Nhưng nó đỏ với
 * một thông điệp là hai chuỗi hash — không nói điều gì đã mất, và cách chữa rẻ
 * nhất khi thấy hash lệch là chạy `-update-print-golden`. Bài này phát biểu
 * bằng lời điều golden chỉ phát biểu bằng số: **cái gì** phải còn đó, và
 * **vì sao**.
 *
 * Cùng lý lẽ với `KitchenTicketHasNoRegistrationNumberTest` của #2928.
 */

use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\SystemTemplateDefaults;

uses()->group('print');

/** Định nghĩa mặc định hệ thống của một kind. */
function kindDefault(string $kind): array
{
    return app(SystemTemplateDefaults::class)->forKind($kind);
}

it('#3082 phiếu bếp chừa dòng trắng ở ĐẦU phiếu', function () {
    expect(kindDefault('kitchen')['top_feed'] ?? 0)->toBe(3);
});

it('#3082 ĐÃ GỠ nửa "cao gấp đôi" — dòng món phiếu bếp BẰNG phiếu hall', function () {
    // Biên bản gỡ bỏ, không phải test chết. #3082 từng khai `items.size = tall`
    // (ESC i 1 0 — ×2 CAO) cho phiếu bếp; chủ dự án chốt 2026-08-17 rằng dòng
    // món phiếu bếp phải BẰNG phiếu hall, nên override ấy đã gỡ khỏi
    // `config/print_templates.php` cùng lúc với nhánh `opts.kind == "kitchen"`
    // bên Go.
    //
    // Bài này đảo chiều thay vì biến mất, vì cái đắt nhất ở đây là gỡ MỘT PHÍA:
    // mẫu bên Cloud và renderer bên Go phải cùng im lặng, nếu không hai đường
    // in lệch nhau ngay dòng món đầu tiên — và cổng byte-parity chỉ nói ra bằng
    // hai chuỗi hash, không nói cái gì đã lệch.
    $items = collect(kindDefault('kitchen')['blocks'])->firstWhere('id', 'items');

    expect($items['size'] ?? null)->toBeNull();
});

it('#3082 KHÔNG kind nào khác bị đụng — cả hai prop chỉ ở bếp', function () {
    // Vế đắt nhất của bản vá này. `top_feed` nằm ở cấp MẪU và `size` bọc NGOÀI
    // mọi emitter, nên một dòng khai sai chỗ sẽ đổi hoá đơn khách của mọi quán
    // — mà hoá đơn khách là chứng từ. 117/126 golden giữ nguyên chính là điều
    // này, phát biểu bằng lời.
    $leaked = [];

    foreach (PrintTemplateKind::cases() as $case) {
        if ($case->value === 'kitchen') {
            continue;
        }
        $def = kindDefault($case->value);

        if (($def['top_feed'] ?? 0) !== 0) {
            $leaked[] = "{$case->value}: top_feed={$def['top_feed']}";
        }
        foreach ($def['blocks'] as $block) {
            if (($block['size'] ?? null) !== null) {
                $leaked[] = "{$case->value}.{$block['id']}: size={$block['size']}";
            }
        }
    }

    expect($leaked)->toBe([], "prop của #3082 rò sang kind khác:\n  ".implode("\n  ", $leaked));
});

it('#3082 `size` là ENUM đóng, không phải ô chữ tự do', function () {
    // `tall` an toàn vì ×2 CAO giữ nguyên số cột. Double-width thì mỗi glyph
    // chiếm hai cột ⇒ `wrapText`, căn giữa/phải và hiệu chỉnh lề trái đều sai.
    // Nên chỗ nguy hiểm không phải renderer mà là ENUM: thêm một giá trị ở đây
    // là cho phép một phiếu vỡ bố cục ra đời.
    $enum = config('print_blocks.blocks.items.prop_enums.size');

    expect($enum)->toBe(['normal', 'tall'])
        ->and($enum)->not->toContain('double_width')
        ->and($enum)->not->toContain('double_size');
});

it('#3082 brand SỬA được cỡ chữ — nếu không, mỗi lần chỉnh là một lần deploy', function () {
    expect(config('print_blocks.blocks.items.editable_props'))->toContain('size');
});
