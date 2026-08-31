<?php

declare(strict_types=1);

/**
 * Sổ `order_conditions` phải dẫn xuất từ KẾT QUẢ ĐỊNH GIÁ, không phải từ cột.
 *
 * ## Chiều phụ thuộc, không phải giá trị
 *
 * Hôm nay `recalculateTotals` ghi cột từ `PricingResult` NGAY TRƯỚC khi gọi
 * `writeConditions`, nên đọc cột hay đọc `$pricing` đều ra cùng con số. Rào này
 * không canh con số — nó canh CHIỀU:
 *
 *     đúng:  PricingResult ──> sổ
 *                          └─> cột (kết tinh)
 *     sai:   PricingResult ──> cột ──> sổ
 *
 * Ở hình dạng sai, ngày nào có một đường ghi khác chạm cột — backfill, lệnh
 * sửa tay, một service mới — thì sổ lặng lẽ đi theo con số sai mà không có gì
 * đỏ lên. Và quan trọng hơn: chừng nào sổ còn đọc cột thì **không xoá được
 * cột** (#2041 bước 3), vì xoá cột là làm sổ ngừng có dòng.
 *
 * ## Vì sao là rào tĩnh chứ không phải test hành vi
 *
 * Vì hành vi không phân biệt được hai hình dạng: hai con số bằng nhau. Muốn
 * test hành vi bắt được, phải dựng một trạng thái mà cột và `PricingResult`
 * lệch nhau — mà chính `recalculateTotals` đồng bộ chúng ngay trước đó, nên
 * trạng thái ấy không dựng được qua đường công khai. Một test "dựng được" chỉ
 * bằng cách gọi thẳng phương thức private thì đo cái nó tự dàn dựng.
 *
 * Nên đọc mã nguồn là phép đo trung thực hơn ở đây, và nó nêu tên đúng thứ
 * cần cấm.
 */
it('writeConditions không đọc cột tiền nào của đơn', function () {
    $path = base_path('app/Services/Order/Internal/Concerns/WritesCustomerOrders.php');
    $source = (string) file_get_contents($path);

    // Cắt đúng thân `writeConditions` — từ chữ ký tới phương thức kế tiếp.
    $start = strpos($source, 'private function writeConditions(');
    expect($start)->not->toBeFalse('không tìm thấy writeConditions — rào này đã lạc mục tiêu');

    $rest = substr($source, $start + 10);
    $end = strpos($rest, "\n    private function ");
    if ($end === false) {
        $end = strpos($rest, "\n    public function ");
    }
    $body = $end === false ? $rest : substr($rest, 0, $end);

    /** @var array<string, string> $forbidden cột => vì sao */
    $forbidden = [
        'service_charge' => 'phí phục vụ — dùng $pricing->serviceCharge',
        'tax_amount' => 'tổng thuế — dùng $pricing->groups',
        'discount_amount' => 'giảm giá — dùng $pricing->discount (đã KẸP về subtotal)',
        'total_amount' => 'tổng đơn — sổ không được suy ngược từ tổng',
        'subtotal' => 'dùng $pricing->subtotal',
    ];

    $hits = [];
    foreach ($forbidden as $column => $why) {
        // Chỉ bắt `$order-><cột>`; `$pricing->subtotal` và tên cột trong chuỗi
        // SQL (câu DELETE) là hợp lệ.
        if (preg_match('/\$order->'.preg_quote($column, '/').'\b/', $body)) {
            $hits[] = "  \$order->{$column} — {$why}";
        }
    }

    expect($hits)->toBe([], implode("\n", array_merge(
        ['`writeConditions` đọc cột tiền của đơn:', ''],
        $hits,
        [
            '',
            'Sổ phải dẫn xuất từ `PricingResult`, không phải từ cột. Đọc cột đảo',
            'chiều phụ thuộc (cột → sổ thay vì định giá → cả hai), nên một đường',
            'ghi khác chạm cột sẽ kéo sổ đi theo mà không có gì đỏ lên.',
            '',
            'Và nó CHẶN #2041 bước 3: chừng nào sổ còn đọc cột thì xoá cột là',
            'làm sổ ngừng có dòng.',
        ],
    )));
});

/**
 * Ruling #2132 §B — ba cơ chế ĐỊNH HÌNH GIÁ không bao giờ là `source` của sổ.
 *
 * Khuyến mãi thực đơn, override giá menu/floating và topping miễn phí N cái đầu
 * kết thúc ở `unit_price`/`topping_subtotal`, tức tiền của chúng đã nằm TRONG
 * `subtotal`. Một dòng sổ cho chúng là đại diện kép cùng khoản tiền trên cùng
 * bảng — đúng hình dạng #2074/#2075 đã trả giá.
 *
 * Test hành vi `PriceFormationLeavesNoLedgerRowTest` canh KẾT QUẢ (đơn có cả ba
 * cơ chế sinh đúng census như đơn thường). Rào này canh Ý ĐỊNH: nó nêu tên ba
 * `source` bị cấm, nên người thêm chúng đọc được lý do ngay tại chỗ thay vì chỉ
 * thấy một census lệch.
 */
it('writeConditions không phát source của một cơ chế định hình giá', function () {
    $path = base_path('app/Services/Order/Internal/Concerns/WritesCustomerOrders.php');
    $source = (string) file_get_contents($path);

    $start = strpos($source, 'private function writeConditions(');
    expect($start)->not->toBeFalse('không tìm thấy writeConditions — rào này đã lạc mục tiêu');

    $rest = substr($source, $start + 10);
    $end = strpos($rest, "\n    private function ");
    if ($end === false) {
        $end = strpos($rest, "\n    public function ");
    }
    $body = $end === false ? $rest : substr($rest, 0, $end);

    /** @var array<string, string> $banned source => cơ chế nó đại diện */
    $banned = [
        'menu_promotion' => 'khuyến mãi thực đơn — dấu vết là items.original_unit_price',
        'price_override' => 'override giá menu/floating — kết quả CHÍNH LÀ unit_price',
        'free_topping' => 'topping miễn phí N cái đầu — dấu vết ở dòng topping',
        'promotion' => 'như menu_promotion',
        'waived' => 'phần được miễn — không sinh tiền nên không có gì để ghi sổ',
    ];

    $hits = [];
    foreach ($banned as $sourceName => $why) {
        if (preg_match("/'source'\s*=>\s*'".preg_quote($sourceName, '/')."'/", $body)) {
            $hits[] = "  'source' => '{$sourceName}' — {$why}";
        }
    }

    expect($hits)->toBe([], implode("\n", array_merge(
        ['`writeConditions` phát dòng sổ cho một cơ chế định hình giá:', ''],
        $hits,
        [
            '',
            'Ruling #2132 §B: tiền của ba cơ chế này ĐÃ nằm trong `subtotal`, nên',
            'một dòng sổ là đại diện KÉP cùng khoản tiền — bất biến',
            '`total_amount == subtotal + Σ(sổ)` ngừng tự kiểm tra được bằng phép',
            'cộng, và mọi reader phải mang quy ước mềm "nhớ loại trừ khi cộng".',
            '',
            'Dấu vết của chúng sống ở tầng item-snapshot, không phải tầng sổ:',
            'docs/explanation/money-ledger-architecture.md §"Ruling #2132 §B".',
        ],
    )));

    // Khuôn thật sự bắt được — và không bắt nhầm `source` hợp lệ.
    expect(preg_match("/'source'\s*=>\s*'menu_promotion'/", "'source' => 'menu_promotion',"))->toBe(1);
    expect(preg_match("/'source'\s*=>\s*'menu_promotion'/", "'source' => 'tax_type',"))->toBe(0);
});

it('rào CÒN CHẠY — cắt đúng thân hàm và bắt được cột nếu có', function () {
    // Chống xanh giả: nếu `writeConditions` bị đổi tên hoặc phép cắt thân hàm
    // hỏng, `$body` thành rỗng và test trên xanh vĩnh viễn mà không đo gì.
    $path = base_path('app/Services/Order/Internal/Concerns/WritesCustomerOrders.php');
    $source = (string) file_get_contents($path);
    $start = strpos($source, 'private function writeConditions(');
    $rest = substr($source, (int) $start + 10);
    $end = strpos($rest, "\n    private function ");
    $body = $end === false ? $rest : substr($rest, 0, $end);

    // Thân hàm phải có thực chất, không phải chuỗi rỗng.
    expect(strlen($body))->toBeGreaterThan(1000, 'phép cắt thân writeConditions đã hỏng');

    // Và phải chứa những mốc CHẮC CHẮN có, nếu không là cắt nhầm hàm.
    expect($body)->toContain("'type' => 'tax'");
    expect($body)->toContain("'type' => 'discount'");
    expect($body)->toContain("'type' => 'service_charge'");
    expect($body)->toContain('$pricing->');

    // Khuôn phát hiện thật sự bắt được: dựng một thân giả có `$order->tax_amount`.
    $fake = 'foo(); $x = (float) $order->tax_amount; bar();';
    expect(preg_match('/\$order->tax_amount\b/', $fake))->toBe(1);

    // …và KHÔNG bắt nhầm `$pricing->` hay tên cột trong chuỗi SQL.
    $clean = "\$x = \$pricing->serviceCharge; DB::raw('SELECT tax_amount FROM t');";
    expect(preg_match('/\$order->tax_amount\b/', $clean))->toBe(0);
});
