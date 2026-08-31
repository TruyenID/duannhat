<?php

declare(strict_types=1);

/**
 * #1492 (#1459 tầng 2) — 適格簡易請求書 là một `kind` RIÊNG, chọn theo quốc gia.
 *
 * Trước bản này quán Nhật lấy chứng từ Nhật bằng cách cưỡi lên trục LOCALE:
 * `FormatVatInvoice` rẽ sang layout Nhật khi `normalizePrintLocale(info.Locale)
 * == "ja"`. Hệ quả đo được trong #1459: quán VN mà thu ngân để giao diện tiếng
 * Nhật thì in ra chứng từ Nhật, còn quán JP với giao diện tiếng Việt thì in ra
 * hoá đơn GTGT Việt Nam. Bốn trục độc lập — compliance-country ≠ currency ≠
 * timezone ≠ print locale — và trục 1 đang bị suy ra từ trục 4.
 *
 * Tầng này dựng CHỖ ĐỨNG cho chứng từ Nhật ở Cloud. Việc đổi trục rẽ trong Go
 * từ locale sang quốc gia là #1493.
 */

use App\Services\Print\Enums\PrintTemplateKind;
use App\Services\Print\SystemTemplateDefaults;

it('#1492 適格簡易請求書 chỉ phát cho shop ở NHẬT', function () {
    $kind = PrintTemplateKind::QualifiedSimplifiedInvoice;

    expect($kind->countries())->toBe(['JP'])
        ->and($kind->availableIn('JP'))->toBeTrue()
        ->and($kind->availableIn('VN'))->toBeFalse();

    // Song đối: chứng từ Việt không được phát cho quán Nhật. Hai chiều cùng
    // đúng mới là "chứng từ đi theo quốc gia", một chiều thì chỉ là bộ lọc.
    expect(PrintTemplateKind::VatInvoice->availableIn('JP'))->toBeFalse()
        ->and(PrintTemplateKind::RedInvoice->availableIn('JP'))->toBeFalse();
});

it('#1492 không biết quốc gia thì VẪN phát — ẩn chứng từ luật định là chặn người ta xuất hoá đơn', function () {
    // Quy tắc có sẵn từ #1445, ghim lại ở đây vì kind mới thừa hưởng nó.
    expect(PrintTemplateKind::QualifiedSimplifiedInvoice->availableIn(null))->toBeTrue();
});

it('#1492 tên chứng từ KHÔNG dịch — cả ba locale đều là tên pháp lý tiếng Nhật', function () {
    $title = collect(app(SystemTemplateDefaults::class)
        ->forKind(PrintTemplateKind::QualifiedSimplifiedInvoice)['blocks'])
        ->firstWhere('id', 'title');

    // Luật #1445: "quốc gia nào ngôn ngữ đó" được thoả bằng việc chọn ĐÚNG
    // CHỨNG TỪ, không bằng việc dịch tên một chứng từ nước khác. Một quán Nhật
    // in bản `en` vẫn phải ra 適格簡易請求書 — đó là tên pháp lý của tờ giấy,
    // không phải một nhãn hiển thị.
    foreach (['ja', 'en', 'vi'] as $locale) {
        expect($title['i18n'][$locale])->toBe('適格簡易請求書');
        expect($title['i18n_narrow'][$locale])->toBe('簡易請求書');
    }
});

it('#1492 mẫu mặc định mô tả tờ giấy ĐANG in, không phải một layout mới (TR-40)', function () {
    $defaults = app(SystemTemplateDefaults::class);

    $qsi = array_column($defaults->forKind(PrintTemplateKind::QualifiedSimplifiedInvoice)['blocks'], 'id');
    $vat = array_column($defaults->forKind(PrintTemplateKind::VatInvoice)['blocks'], 'id');

    // CÙNG bộ block với vat_invoice: khác biệt Nhật–Việt nằm bên trong từng
    // emitter (nhánh `ja` dùng chung helper với `formatVatInvoiceJA`), không
    // nằm ở danh sách block. Bịa một chuỗi block mới là vi phạm cổng TR-40 —
    // mẫu hệ thống phải in giống hệt formatter nó thay thế TRƯỚC khi ai sửa.
    expect($qsi)->toBe($vat);
});

it('#1492 登録番号 là block BẮT BUỘC — thiếu nó thì tờ giấy không phải chứng từ đủ điều kiện', function () {
    $required = config('print_blocks.kinds.qualified_simplified_invoice.required');

    // Theo インボイス制度, 登録番号 của NGƯỜI BÁN là thứ làm tờ giấy này LÀ một
    // 適格簡易請求書; thiếu nó thì đây chỉ là 領収書 thường và người mua không
    // khấu trừ được thuế đầu vào.
    expect($required)->toContain('registration_number');
});
