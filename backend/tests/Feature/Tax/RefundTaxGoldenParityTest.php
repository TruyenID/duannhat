<?php

declare(strict_types=1);

use App\Support\RoundingMode;

/**
 * #2133 — nửa CLOUD của hợp đồng thuế khoản hoàn từng phần.
 *
 * Bản sinh đôi: `workstation/internal/service/refund_tax_golden_test.go`,
 * đọc **cùng một file** (`testdata/refund_tax_golden.json`, khớp từng byte với
 * `tests/Fixtures/` — cổng đó nằm ở `SharedFixturesAgreeTest`, quét TOÀN BỘ
 * `tests/Fixtures`, skip khi thiếu submodule ở local và THROW trên CI).
 *
 * Vì sao cần: Cloud và máy trạm là hai bản cài đặt của cùng phép toán, và đường
 * Go là đường **thật sự đưa tiền cho khách** (POS-LAN, kể cả khi mất mạng).
 * Trước bài này không rào nào canh hai bên — `grep -ril refund` trên bốn fixture
 * golden đang có ra **rỗng** — và hai bên đã lệch thật: bản sửa #2133 vào Cloud
 * trước, Go giữ phép làm tròn từng lần, nên cùng một thao tác ra 303 ở quầy và
 * 302 trên sổ.
 *
 * Bài này đo **phép toán**, không dựng đơn: một fixture chỉ chạy được qua cả một
 * transaction thì sẽ không ai chạy, và nó cũng không còn so được với Go.
 *
 * #2180 — gọi THẲNG engine production (`RoundingMode::refundTaxDelta`, cũng là
 * hàm `WritesCustomerOrders::refundItem` dùng), không cài lại công thức ở đây:
 * bản cài lại đã được đo là vẫn XANH khi production đổi về làm tròn từng lần —
 * tức hợp đồng "hai engine hoàn tiền KHÁC NHAU" không bắn đúng lúc cần.
 */
it('khớp fixture chung với máy trạm — từng lần hoàn và tổng', function () {
    $path = base_path('tests/Fixtures/refund_tax_golden.json');
    expect(file_exists($path))->toBeTrue("thiếu fixture chung: {$path}");

    $doc = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $cases = $doc['cases'] ?? [];

    // Fixture rỗng và bộ đọc hỏng trông giống hệt nhau ở đầu ra: vòng lặp chạy
    // 0 lần và bài test XANH.
    expect(count($cases))->toBeGreaterThan(0, 'fixture không có ca nào — bộ đọc hỏng');

    foreach ($cases as $c) {
        $cum = 0;
        $sum = 0;

        foreach ($c['refunds'] as $i => $q) {
            $got = (int) round(RoundingMode::refundTaxDelta(
                (float) $c['tax_total'], (float) $cum, (float) $q, (float) $c['original_qty'],
                (float) $c['tax_step'], (string) $c['tax_mode'],
            ));

            expect($got)->toBe((int) $c['refund_taxes'][$i], sprintf(
                "[%s] lần hoàn %d (đã hoàn %d, thêm %d): thuế = %d, fixture nói %d — hai engine hoàn tiền KHÁC NHAU.\n%s",
                $c['name'], $i + 1, $cum, $q, $got, $c['refund_taxes'][$i], $c['why'] ?? '',
            ));

            if (isset($c['stamped_tax_total']) && (float) $c['stamped_tax_total'] !== (float) $c['tax_total']) {
                $wrong = (int) round(RoundingMode::refundTaxDelta(
                    (float) $c['stamped_tax_total'], (float) $cum, (float) $q, (float) $c['original_qty'],
                    (float) $c['tax_step'], (string) $c['tax_mode'],
                ));
                expect($wrong)->not->toBe((int) $c['refund_taxes'][$i], sprintf(
                    '[%s] lần hoàn %d: thuế STAMPED %.0f không được trùng gross — hoàn phải dùng tax_total %.0f',
                    $c['name'], $i + 1, $c['stamped_tax_total'], $c['tax_total'],
                ));
            }

            $cum += (int) $q;
            $sum += $got;
        }

        expect($sum)->toBe((int) $c['sum_refund_tax'], "[{$c['name']}] Σ thuế hoàn lệch");

        // Bất biến của cả dòng, với MỌI cách chia.
        if ($cum === (int) $c['original_qty']) {
            expect((float) $sum)->toBe((float) $c['tax_total'], sprintf(
                '[%s] hoàn hết %d/%d nhưng Σ = %d ≠ thuế đã thu %s — quán %s',
                $c['name'], $cum, $c['original_qty'], $sum, $c['tax_total'],
                $sum > $c['tax_total'] ? 'trả DƯ' : 'trả THIẾU',
            ));
        }
    }
});
