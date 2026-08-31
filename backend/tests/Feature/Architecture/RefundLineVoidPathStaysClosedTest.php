<?php

declare(strict_types=1);

/**
 * #2158 ca 4 — "void một dòng hoàn" là đường ĐÃ ĐÓNG; test này giữ nó đóng.
 *
 * Câu hỏi của issue ("có đường nào trong sản phẩm cho phép void một dòng hoàn
 * không?") đã trả lời bằng đọc mã 2026-08-09: KHÔNG.
 *
 *   - Hai cửa void ĐƠN LẺ duy nhất — `voidItem` (POS/customer) và
 *     `transportWorkstationVoidItem` (replay máy trạm, KHÔNG đi qua ma trận
 *     `item_voidable_statuses`) — đều gọi `assertNotRefundLine()` (#2173,
 *     409 `CANNOT_VOID_REFUND_LINE`) trước mọi thứ khác.
 *   - Không endpoint HTTP nào nhận `status = voided` cho một item: mọi
 *     whitelist ghi trạng thái item dừng ở `pending/preparing/ready/served`
 *     (Workstation `updateItemStatus`, Handy, Shop PATCH, KDS gen-1/gen-2).
 *   - Các chỗ còn lại set item → Voided đều là VOID CẢ ĐƠN (voidOrder /
 *     update-full-void / voidAwaitingConfirmation / expire sweep): dòng hoàn
 *     chết CÙNG cả đơn — đơn Voided không còn phát biểu tiền nào để mâu thuẫn,
 *     khác hẳn việc rút một dòng hoàn khỏi một đơn còn sống.
 *
 * Hành vi 409 của hai cửa đã có `VoidRefundLineIsRefusedTest` (POS + replay)
 * và `RefundTraceProtectionTest` (#2200, dòng GỐC) canh. File này canh phần
 * feature test KHÔNG canh được: một CỬA MỚI mở ra sau này —
 *
 *   1. ai đó gỡ lời gọi `assertNotRefundLine` khỏi một trong hai cửa hiện có;
 *   2. ai đó thêm `voided` vào một whitelist `in:` ghi trạng thái item.
 *
 * Nhánh `applyRefundLines` BỎ QUA dòng hoàn đã voided
 * (`OrderPricingCalculator.php` — thuế "quay lại" đơn nếu dòng hoàn bị void)
 * vì thế chỉ với tới được qua void CẢ ĐƠN, nơi con số tổng không còn ai đọc.
 */
it('cả hai cửa void đơn lẻ đều còn giữ guard #2173 assertNotRefundLine', function () {
    $source = file_get_contents(app_path('Services/Order/Internal/Concerns/WritesCustomerOrders.php'));
    expect($source)->not->toBeFalse();

    // Cắt thân từng method theo dấu mở method kế tiếp — đủ bền cho một file
    // trait: chỉ cần lời gọi nằm SAU dòng khai báo method và TRƯỚC method kế.
    foreach (['voidItem', 'transportWorkstationVoidItem'] as $method) {
        $start = strpos($source, "function {$method}(");
        expect($start)->not->toBeFalse("method {$method} biến mất khỏi WritesCustomerOrders — cửa void đã đổi chỗ, chuyển guard #2173 theo và cập nhật test này");

        $end = strpos($source, "\n    public function", $start + 1);
        $body = substr($source, $start, ($end === false ? strlen($source) : $end) - $start);

        expect(str_contains($body, '$this->assertNotRefundLine('))->toBeTrue(implode("\n", [
            "{$method}() không còn gọi assertNotRefundLine() — cửa void dòng hoàn vừa MỞ LẠI (#2173).",
            'Void một dòng hoàn làm chứng từ tự mâu thuẫn: dòng âm biến khỏi tổng đơn nhưng',
            'refunded_quantity trên dòng gốc chỉ được CỘNG — hai sổ nói hai chuyện.',
            'Đảo một khoản hoàn = một giao dịch MỚI, không phải xoá bút toán cũ.',
        ]));
    }
});

it('không endpoint HTTP nào nhận status=voided cho một item', function () {
    // Mọi whitelist `in:` ghi trạng thái item trong app/Http phải dừng ở
    // pending/preparing/ready/served. Enum `voided` trong tài liệu OA (filter
    // ĐỌC theo query) không phải chuỗi `in:` nên không khớp mẫu này.
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Http'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        if ($source === false) {
            continue;
        }
        if (preg_match_all('/[\'"]in:[a-z0-9_,]*voided[a-z0-9_,]*[\'"]/i', $source, $m)) {
            foreach ($m[0] as $rule) {
                $offenders[] = $file->getPathname().' → '.$rule;
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'Một whitelist validation vừa nhận `voided` làm trạng thái ghi được qua HTTP:',
        ...$offenders,
        'Đường đó cho client void thẳng một dòng — kể cả DÒNG HOÀN — mà không qua',
        'guard #2173. Void phải đi qua voidItem/transportWorkstationVoidItem, nơi',
        'assertNotRefundLine đứng gác.',
    ]));
});
