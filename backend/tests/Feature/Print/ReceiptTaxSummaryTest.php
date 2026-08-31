<?php

declare(strict_types=1);

use App\Services\Print\Renderer\ReceiptTaxSummary;

/**
 * plan-053 T5.1d slice 1a (#1908).
 *
 * Thứ phải ghim ở đây là **luật đánh dấu ※**, không phải phép cộng. Phép cộng đã
 * xảy ra ở `OrderTaxBreakdownAggregator` và là snapshot bất biến; class này chỉ
 * đọc lại rồi dẫn xuất. Nên mọi ca dưới đây đo đúng phần dẫn xuất — chỗ duy
 * nhất PHP tự quyết.
 */
it('#1908 by_rate rỗng ⇒ summary RỖNG, không phải một khối 0%', function () {
    // Đơn cũ không có snapshot mức thuế trên dòng nào. Bên Go nhánh này làm
    // phiếu rơi về đường "Thue" một dòng; thay bằng khối 0% là in ra một khẳng
    // định sai (đơn KHÔNG phải 非課税).
    $s = ReceiptTaxSummary::fromBreakdown(['by_rate' => []]);

    expect($s->isEmpty())->toBeTrue()
        ->and($s->blocks)->toBe([])
        ->and($s->hasReduced)->toBeFalse()
        ->and($s->maxRate)->toBe(0.0);
});

it('#1908 một mức duy nhất ⇒ KHÔNG đánh dấu ※', function () {
    // §1.3.2 dùng ※ để phân biệt hai tầng thuế. Một tầng thì không có gì để
    // phân biệt, và đánh dấu tất cả là làm dấu đó vô nghĩa.
    $s = ReceiptTaxSummary::fromBreakdown(['by_rate' => [
        ['rate' => 10.0, 'taxable' => 1000, 'tax' => 100],
    ]]);

    expect($s->hasReduced)->toBeFalse()
        ->and($s->maxRate)->toBe(10.0)
        ->and($s->isReducedLine(10.0))->toBeFalse();
});

it('#1908 hai mức ⇒ mức THẤP mang ※, mức cao thì không', function () {
    $s = ReceiptTaxSummary::fromBreakdown(['by_rate' => [
        ['rate' => 10.0, 'taxable' => 2000, 'tax' => 200],
        ['rate' => 8.0, 'taxable' => 1000, 'tax' => 80],
    ]]);

    expect($s->hasReduced)->toBeTrue()
        ->and($s->reducedRate)->toBe(8.0)
        ->and($s->maxRate)->toBe(10.0)
        ->and($s->isReducedLine(8.0))->toBeTrue()
        ->and($s->isReducedLine(10.0))->toBeFalse();
});

it('#1908 khối được SẮP theo mức tăng dần bất kể thứ tự payload', function () {
    // Cổng không hứa thứ tự. Phiếu thì phải in 8% trước 10% để hai khối của các
    // phiếu khác nhau thẳng hàng khi kế toán xếp chồng.
    $s = ReceiptTaxSummary::fromBreakdown(['by_rate' => [
        ['rate' => 10.0, 'taxable' => 2000, 'tax' => 200],
        ['rate' => 0.0, 'taxable' => 500, 'tax' => 0],
        ['rate' => 8.0, 'taxable' => 1000, 'tax' => 80],
    ]]);

    expect(array_map(static fn ($b): float => $b->rate, $s->blocks))->toBe([0.0, 8.0, 10.0]);
});

it('#1908 非課税 0% là một mức THẬT — nhưng KHÔNG phải 軽減税率, nên không mang ※ (#2086)', function () {
    // Hai mệnh đề, và bản gốc #1908 gộp nhầm chúng làm một.
    //
    // ĐÚNG (giữ nguyên): 0 là thuế suất hợp lệ, một trong ba loại của plan-043.
    // Nó KHÔNG được đối xử như "không có mức" — đó đúng lỗi #1128 đã chặn ở
    // đường pull thuế, và khối 0% phải có mặt trong bảng thuế.
    //
    // SAI (sửa ở #2086): từ đó suy ra "0% mang ※". Dấu ※ trỏ tới chú thích
    // 「※は軽減税率対象」 ở chân phiếu, tức nó nói CỤ THỂ rằng dòng ấy là
    // 軽減税率. Mà 非課税 / 免税 là chế độ thuế KHÁC HẲN — Peppol còn tách hẳn
    // thành hai loại (Z/E so với S). Dán ※ lên một món 非課税 là tuyên bố sai
    // trên chứng từ thuế.
    //
    // Lỗi chỉ tới được giấy sau #2069 (nhóm 0% bắt đầu vào sổ); trước đó bảng
    // thuế không bao giờ có dòng 0% nên nó bị che.
    $s = ReceiptTaxSummary::fromBreakdown(['by_rate' => [
        ['rate' => 0.0, 'taxable' => 500, 'tax' => 0],
        ['rate' => 10.0, 'taxable' => 2000, 'tax' => 200],
    ]]);

    // Khối 0% CÓ MẶT — phần #1908 làm đúng.
    expect($s->isEmpty())->toBeFalse()
        ->and(array_map(static fn ($b): float => $b->rate, $s->blocks))->toBe([0.0, 10.0]);

    // Nhưng nó không phải mức giảm, và không mang ※.
    expect($s->hasReduced)->toBeFalse()
        ->and($s->reducedRate)->toBe(0.0)
        ->and($s->isReducedLine(0.0))->toBeFalse();
});

it('0% + 8% + 10%: chỉ 8% mang ※, 0% thì không (#2086)', function () {
    // Ca phân biệt thật sự: có cả 軽減 thật lẫn 非課税 trên cùng một phiếu.
    //
    // Bản cũ dán ※ lên CẢ HAI (điều kiện chỉ là `rate < maxRate`), nên 8% vẫn
    // đúng còn 0% thì sai. Lỗi hẹp hơn "bỏ sót 軽減" — đo lại mới thấy — nhưng
    // hậu quả vẫn là một tuyên bố sai về chế độ thuế của món 非課税 trên chứng
    // từ. Test này ghim rằng phân biệt phải theo LOẠI thuế, không theo thứ tự
    // số học của thuế suất.
    $s = ReceiptTaxSummary::fromBreakdown(['by_rate' => [
        ['rate' => 0.0, 'taxable' => 500, 'tax' => 0],
        ['rate' => 8.0, 'taxable' => 1000, 'tax' => 80],
        ['rate' => 10.0, 'taxable' => 2000, 'tax' => 200],
    ]]);

    expect($s->hasReduced)->toBeTrue()
        ->and($s->reducedRate)->toBe(8.0)
        ->and($s->isReducedLine(8.0))->toBeTrue()
        ->and($s->isReducedLine(0.0))->toBeFalse()
        ->and($s->isReducedLine(10.0))->toBeFalse();
});

it('#1908 dòng KHÔNG có snapshot mức thì không đánh dấu', function () {
    $s = ReceiptTaxSummary::fromBreakdown(['by_rate' => [
        ['rate' => 8.0, 'taxable' => 1000, 'tax' => 80],
        ['rate' => 10.0, 'taxable' => 2000, 'tax' => 200],
    ]]);

    expect($s->isReducedLine(null))->toBeFalse();
});

it('#1908 KHÔNG tính lại thuế — số vào bằng số ra', function () {
    // Ca này là hàng rào chống chính cái cám dỗ của bản port: bên Go
    // `buildReceiptTaxSummary` chạy priceGroups() để TÍNH LẠI. Ở PHP mà tính
    // lại thì con số in ra sẽ khác hoá đơn đã phát hành, và đó là báo cáo thuế.
    //
    // Truyền một cặp taxable/tax KHÔNG khớp `tax = taxable × rate` — nếu ai đó
    // thêm phép tính lại, ca này đỏ.
    $s = ReceiptTaxSummary::fromBreakdown(['by_rate' => [
        ['rate' => 10.0, 'taxable' => 1000, 'tax' => 73],
    ]]);

    expect($s->blocks[0]->taxable)->toBe(1000)
        ->and($s->blocks[0]->tax)->toBe(73);
});
