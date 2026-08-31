<?php

declare(strict_types=1);

use App\Services\Print\Renderer\ChainShiftLine;
use App\Services\Print\Renderer\DebtSlipInfo;
use App\Services\Print\Renderer\ShiftDenominationLine;
use App\Services\Print\Renderer\ShiftDiscountLine;
use App\Services\Print\Renderer\ShiftOpenReportInfo;
use App\Services\Print\Renderer\ShiftPaymentLine;
use App\Services\Print\Renderer\ShiftReportInfo;
use App\Services\Print\Renderer\ShiftTaxRateLine;
use App\Services\Print\Renderer\TablePaidInfo;
use App\Services\Print\Renderer\VatInvoiceInfo;
use App\Services\Print\Renderer\VatInvoiceLine;
use App\Services\Print\Renderer\VatInvoiceTaxLine;
use App\Services\Print\Renderer\VatInvoiceTopping;

/**
 * plan-053 T5.1d (#1910) — parity cho các struct payload mà `PrintRenderData`
 * mang theo, đọc `payload_fields` của `print_contract_golden.json`.
 *
 * ── Vì sao rào này phải tồn tại, và nó đã trả công ngay ─────────────────
 *
 * `ShiftReportInfo` có **43 trường**. Chép tay 43 trường mà không có gì đối
 * chiếu thì sai một trường hiện ra dưới dạng **một dòng thiếu trên phiếu 精算**,
 * không phải một lỗi — và 精算 là chứng từ đối soát.
 *
 * Bản chép tay đầu tiên của tôi sai HAI kiểu, và fixture bắt được cả hai ngay
 * lượt sinh đầu:
 *
 *   - `ChainShiftLine` thiếu 3 trường (`CheckCount` · `Discount` · `ExpectedCash`);
 *   - `ShiftReportInfo` **thừa 2 trường không tồn tại** (`OpeningFloat` ·
 *     `Note` — chúng thuộc phiếu MỞ ca, không phải phiếu đóng ca).
 *
 * Kiểu thứ hai nguy hiểm hơn: một trường thừa không làm gì đỏ, nó chỉ nằm đó
 * cho tới khi có người tin là nó có dữ liệu.
 */

/** @return array<string, list<string>> */
function payloadContractGolden(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $path = base_path('../workstation/internal/service/testdata/print_contract_golden.json');
    expect(file_exists($path))->toBeTrue("thiếu fixture hợp đồng: {$path}");

    /** @var array{payload_fields?: array<string, list<string>>} $decoded */
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return $cache = $decoded['payload_fields'] ?? [];
}

/**
 * Tên tham số PHP → tên trường Go.
 *
 * `ucfirst` đúng cho gần hết; ba chỗ Go viết hoa cả từ viết tắt nên phải khai
 * tường minh. Một hàm "tự động" sẽ đúng 40/43 rồi sai 3 chỗ theo kiểu khó thấy
 * nhất — cùng lý do `PrintRenderData::GO_FIELD_NAMES` được khai bằng tay.
 *
 * @return list<string>
 */
function goFieldNamesOf(string $class): array
{
    $exceptions = ['chainId' => 'ChainID', 'zNumber' => 'ZNumber', 'tillCode' => 'TillCode'];

    $names = [];

    foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
        $php = $param->getName();
        $names[] = $exceptions[$php] ?? ucfirst($php);
    }

    sort($names);

    return $names;
}

it('#1910 struct payload khớp Go từng trường', function (string $goName, string $phpClass) {
    $expected = payloadContractGolden()[$goName] ?? null;

    expect($expected)->not->toBeNull("fixture không có {$goName} — sinh lại print_contract_golden.json");
    expect(goFieldNamesOf($phpClass))->toBe($expected);
})->with([
    'ShiftReportInfo' => ['ShiftReportInfo', ShiftReportInfo::class],
    'ShiftOpenReportInfo' => ['ShiftOpenReportInfo', ShiftOpenReportInfo::class],
    'ChainShiftLine' => ['ChainShiftLine', ChainShiftLine::class],
    'ShiftPaymentLine' => ['ShiftPaymentLine', ShiftPaymentLine::class],
    'ShiftDiscountLine' => ['ShiftDiscountLine', ShiftDiscountLine::class],
    'ShiftTaxRateLine' => ['ShiftTaxRateLine', ShiftTaxRateLine::class],

    // Họ docs (#1909). `VatInvoiceInfo` 19 trường là chỗ chép tay lớn nhất
    // của slice này — đúng loại chỗ đã sai hai kiểu ở #1910.
    'VatInvoiceInfo' => ['VatInvoiceInfo', VatInvoiceInfo::class],
    'VatInvoiceLine' => ['VatInvoiceLine', VatInvoiceLine::class],
    'VatInvoiceTaxLine' => ['VatInvoiceTaxLine', VatInvoiceTaxLine::class],
    'VatInvoiceTopping' => ['VatInvoiceTopping', VatInvoiceTopping::class],
    'DebtSlipInfo' => ['DebtSlipInfo', DebtSlipInfo::class],
    'TablePaidInfo' => ['TablePaidInfo', TablePaidInfo::class],
]);

it('#1910 dòng mệnh giá dùng CHUNG cho phiếu mở ca và 精算', function () {
    // Go gọi nó `ShiftOpenDenomLine` nhưng `ShiftReportInfo.Denominations` cũng
    // dùng đúng kiểu đó. PHP đặt tên `ShiftDenominationLine` cho khớp cả hai
    // chỗ dùng — nên phải khai ánh xạ tên tường minh ở đây, không suy ra được.
    expect(goFieldNamesOf(ShiftDenominationLine::class))
        ->toBe(payloadContractGolden()['ShiftOpenDenomLine']);
});

it('#1910 fixture CÓ payload_fields — rỗng là fixture hỏng, không phải PHP xong', function () {
    expect(payloadContractGolden())->not->toBe([]);
});
