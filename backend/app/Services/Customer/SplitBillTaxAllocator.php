<?php

namespace App\Services\Customer;

/**
 * #2677 / #2064 ③④⑤ — chia thuế theo TỪNG MỨC cho một tập phiếu chia bill.
 *
 * # Bất biến — ĐIỀU KIỆN DỪNG, không phải tiêu chí "nên có"
 *
 * > Σ các phiếu con phải khớp phiếu tổng **theo TỪNG mức thuế**, không lệch một
 * > đồng.
 *
 * Chủ dự án ghi kèm ruling: *"Không đạt được bất biến đó thì DỪNG và báo thiết
 * kế, đừng ship. Một tờ hoá đơn có số thuế không cộng lại đúng tệ hơn thiếu
 * trường — thiếu trường là không khấu trừ được, sai số là sai sổ thuế."*
 *
 * Lớp này vì thế KHÔNG có nhánh "gần đúng". Nó trả về đúng tập số cộng lại bằng
 * đầu vào, hoặc ném.
 *
 * # Vì sao KHÔNG tự tính thuế ở đây
 *
 * Đầu vào `$byRate` là ảnh chụp bất biến của ĐƠN, đến từ
 * {@see OrderTaxBreakdownAggregator} — thứ chỉ đọc `customer_order_items`.
 * Lớp này chỉ PHÂN BỔ con số đó cho các phiếu con. Nó không nhìn dòng món,
 * không hỏi `TaxResolver`, không biết mức thuế nào tồn tại. Cùng nguyên tắc mà
 * ruling áp cho tầng in ("Cloud phân bổ, tầng in chỉ ĐỌC"), đẩy lên một tầng.
 *
 * # Phép làm tròn
 *
 * Dùng lại {@see OrderPricingCalculator::allocateGroupTax} — largest remainder,
 * không phải một bản chép. Ruling nói "nhất quán với phép làm tròn nhóm-rate
 * hiện hành (plan-043/#1099)", và cách duy nhất để giữ lời đó qua thời gian là
 * gọi CHÍNH nó: một bản chép sẽ trôi ở lần ai đó sửa quy tắc làm tròn.
 *
 * Largest remainder chạy trên **CẢ TẬP** phiếu chia cho mỗi mức, không phải
 * từng phiếu một — phần dư của mức 10% được phát cho phiếu xứng đáng nhất trong
 * toàn bộ tập, đúng như phần dư của một nhóm-rate được phát cho dòng món.
 */
class SplitBillTaxAllocator
{
    public function __construct(private readonly OrderPricingCalculator $pricing) {}

    /**
     * Chia mỗi mức thuế của đơn cho các phiếu con theo tỉ lệ phần tiền.
     *
     * @param  list<array{rate: float, taxable: float, tax: float}>  $byRate  ảnh chụp bất biến của ĐƠN
     * @param  list<float>  $shares  phần tiền mỗi phiếu con phải trả, cùng thứ tự với tập phiếu
     * @param  float  $step  bước làm tròn của tiền tệ (JPY/VND = 1.0, USD = 0.01)
     * @return list<list<array{rate: float, taxable: float, tax: float}>> mỗi phần tử là breakdown của MỘT phiếu con
     */
    public function allocate(array $byRate, array $shares, float $step): array
    {
        $n = count($shares);

        if ($n === 0) {
            return [];
        }

        $total = array_sum($shares);

        // Tổng phần chia bằng 0 mà vẫn có thuế để chia ⇒ không có tỉ lệ nào
        // định nghĩa được. Ném thay vì chia đều: chia đều ở đây là BỊA một
        // quyết định phân bổ mà không dữ kiện nào đỡ, và nó sẽ được in ra như
        // một sự thật.
        if ($total <= 0.0) {
            foreach ($byRate as $group) {
                if ((float) ($group['tax'] ?? 0) != 0.0 || (float) ($group['taxable'] ?? 0) != 0.0) {
                    throw new \InvalidArgumentException(
                        'không phân bổ được thuế cho một tập phiếu có tổng tiền 0 — '
                        .'tỉ lệ không xác định, và chia đều ở đây là bịa'
                    );
                }
            }

            return array_fill(0, $n, []);
        }

        /** @var list<list<array{rate: float, taxable: float, tax: float}>> $out */
        $out = array_fill(0, $n, []);

        foreach ($byRate as $group) {
            $rate = (float) $group['rate'];
            $groupTaxable = (float) $group['taxable'];
            $groupTax = (float) $group['tax'];

            // Tỉ lệ lý tưởng của từng phiếu, TRƯỚC làm tròn. `allocateGroupTax`
            // nhận đúng hình dạng này: nó tự hạ về bội của `step` rồi phát phần
            // dư theo largest remainder.
            $idealTaxable = [];
            $idealTax = [];
            foreach ($shares as $share) {
                $ratio = ((float) $share) / $total;
                $idealTaxable[] = $groupTaxable * $ratio;
                $idealTax[] = $groupTax * $ratio;
            }

            $taxable = $this->allocateGroup($idealTaxable, $groupTaxable, $step);
            $tax = $this->allocateGroup($idealTax, $groupTax, $step);

            for ($i = 0; $i < $n; $i++) {
                $out[$i][] = [
                    'rate' => $rate,
                    'taxable' => $taxable[$i],
                    'tax' => $tax[$i],
                ];
            }
        }

        $this->assertReconciles($byRate, $out, $step);

        return $out;
    }

    /**
     * Seam MỘT dòng quanh bộ làm tròn dùng chung.
     *
     * Tồn tại để test dựng được một bộ phân bổ NÓI DỐI và chứng minh cổng bất
     * biến bên dưới thật sự chạy từ `allocate()`. Không có seam thì bài đó chỉ
     * đo KẾT QUẢ, và cổng có thể bị gỡ mà mọi bài vẫn xanh —
     * `OrderPricingCalculator` là `final` nên không mock được từ ngoài.
     *
     * @param  list<float>  $ideals
     * @return list<float>
     */
    protected function allocateGroup(array $ideals, float $groupTotal, float $step): array
    {
        return $this->pricing->allocateGroupTax($ideals, $groupTotal, $step);
    }

    /**
     * Cổng bất biến. Chạy trên MỌI lượt phân bổ, không phải chỉ trong test.
     *
     * Đây là chỗ điều kiện dừng của ruling sống. Một sai lệch ở đây nghĩa là
     * phép làm tròn vừa sinh ra một tờ hoá đơn không cộng lại đúng, và tuyến
     * đúng là DỪNG — không phải ghi log rồi in tiếp.
     */
    private function assertReconciles(array $byRate, array $out, float $step): void
    {
        // Ngưỡng theo BƯỚC của tiền tệ, không phải hằng: với JPY (step 1.0)
        // "lệch một đồng" là 1.0; với USD (step 0.01) nó là một xu. Một epsilon
        // cứng sẽ hoặc bỏ lọt sai số thật ở JPY, hoặc kêu oan vì bụi dấu phẩy
        // động ở USD.
        $eps = max($step, 0.01) * 1e-6;

        foreach ($byRate as $g => $group) {
            foreach (['taxable', 'tax'] as $field) {
                $want = (float) $group[$field];
                $got = 0.0;
                foreach ($out as $bill) {
                    $got += (float) $bill[$g][$field];
                }

                if (abs($got - $want) > $eps) {
                    throw new \RuntimeException(sprintf(
                        'phân bổ thuế KHÔNG khớp ở mức %s%%: %s của các phiếu con cộng lại = %s, '
                        .'phiếu tổng = %s (lệch %s). Không ship một tờ hoá đơn cộng không ra.',
                        rtrim(rtrim(number_format((float) $group['rate'] * 100, 2, '.', ''), '0'), '.'),
                        $field,
                        $got,
                        $want,
                        $got - $want,
                    ));
                }
            }
        }
    }
}
