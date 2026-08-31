<?php

namespace App\Services\Customer;

use App\Models\CustomerOrder;
use App\Omnify\Enums\OrderItemStatusEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * plan-043 §7.6 — per-rate consumption-tax aggregator for the OUTPUT surfaces
 * (Z-report PDF, revenue reports, dashboards).
 *
 * Unlike {@see OrderPricingCalculator} — which prices ONE live order — this
 * aggregator rolls up MANY finalized orders' immutable per-line snapshots into
 * a single per-rate breakdown:
 *
 *   - 課税売上 (taxable) = Σ item.subtotal   (net-of-topping line total)
 *   - 消費税  (tax)     = Σ item.tax_amount  (per-line snapshots that were
 *     allocated from the once-per-group figure, so their sum equals the
 *     group tax the order total used — never an independent per-line rounding)
 *
 * grouped by the line's snapshot `tax_rate`, over NON-voided items only. It
 * reads the frozen snapshots directly — it never recomputes tax math — so the
 * figures always agree with what was stamped at checkout (端数処理は税率ごとに1回,
 * インボイス) and reconcile to `order.tax_amount` (bar the order-level
 * service-charge tax, which owns no line), regardless of later branch rate
 * edits. See {@see OrderPricingCalculator::allocateGroupTax}.
 */
class OrderTaxBreakdownAggregator
{
    /**
     * Roll the non-voided item snapshots of the given orders up into per-rate
     * rows plus 税抜 (net) / 税込 (gross) / 消費税 (tax) totals.
     *
     * `net` here is the taxable-sales base (Σ subtotal). `gross` = net + tax so
     * the two revenue conventions (summary SUMs gross, by-product SUMs net —
     * §3.13) are reconciled onto one payload that exposes both explicitly.
     *
     * @param  iterable<int|string>  $orderIds
     * @return array{
     *   net: float,
     *   tax: float,
     *   gross: float,
     *   by_rate: list<array{rate: float, taxable: float, tax: float}>,
     * }
     */
    public function forOrders(iterable $orderIds): array
    {
        $ids = collect($orderIds)->filter(fn ($id) => $id !== null)->unique()->values();

        if ($ids->isEmpty()) {
            return $this->empty();
        }

        // #2031 — ĐỌC SỔ, không tự dựng lại.
        //
        // Phiên bản trước gom `SUM(items.subtotal)` làm `taxable` và
        // `SUM(items.tax_amount)` làm `tax`. Hai cột đó lấy từ hai mốc khác nhau:
        // `items.subtotal` là GỘP (không có cột giảm giá nào trên dòng), còn
        // `items.tax_amount` đã trừ giảm giá. Trên một đơn có khuyến mãi, hoá đơn
        // in ra "mức 10%: đối giá 10.000, thuế 900" — và 10.000 × 10% ≠ 900.
        //
        // Với 適格請求書, 税率ごとに区分した対価の額 phải là số tiền THỰC NHẬN, nên
        // đó là một trường pháp lý sai trên chứng từ, không phải lỗi trình bày.
        //
        // `order_conditions` (plan-045) đã chụp sẵn cả hai con số cho mỗi mức, sinh
        // từ chính `PricingResult` đã trừ giảm giá. Đọc thẳng nó.
        $rows = DB::table('order_conditions')
            ->where('type', 'tax')
            ->where('conditionable_type', (new CustomerOrder)->getMorphClass())
            ->whereIn('conditionable_id', $ids->all())
            // `COUNT(taxable_base)` bỏ qua NULL — xem điều kiện rơi về bên dưới.
            ->selectRaw('COALESCE(rate, 0) as rate, SUM(COALESCE(taxable_base, 0)) as taxable, SUM(amount) as tax, COUNT(taxable_base) as base_count')
            ->groupBy('rate')
            ->orderBy('rate')
            ->get();

        // Đơn ghi TRƯỚC #2031 không có `taxable_base`, và đơn ghi trước plan-045
        // không có dòng `tax` nào. Rơi về phép gom cũ ở đúng hai ca đó — sai như
        // trước chứ không TRỐNG, vì một báo cáo doanh thu rỗng còn khó phát hiện
        // hơn một con số lệch.
        //
        // #2074 — "không có dòng thuế" KHÔNG đồng nghĩa "sổ này cũ".
        //
        // Bản trước rơi về phép gom cũ khi kết quả rỗng **hoặc** mọi `taxable`
        // bằng 0. Cả hai điều kiện đều nhận nhầm một đơn HỢP LỆ: giảm giá phủ hết
        // giỏ (coupon ≥ giỏ, hoặc mọi món đã hoàn — #2114) cho nhóm mức
        // `taxable = 0, tax = 0`, mà `writeConditions` bỏ qua đúng nhóm ấy, nên
        // đơn không có dòng `tax` nào — dù sổ của nó hoàn toàn hiện đại.
        //
        // Hậu quả đo được (#2074): hoá đơn in `対価の額 1.000` cạnh `thuế 0` cho
        // một đơn khách trả 0 đồng. Đúng loại dòng TỰ MÂU THUẪN mà #2031 sinh ra
        // để diệt, chỉ khác đường tới.
        //
        // Dấu hiệu đúng là **sổ có tồn tại hay không**, không phải nó có bao nhiêu
        // dòng thuế: một đơn thời plan-045 có giảm giá luôn mang dòng `discount`,
        // nên `order_conditions` của nó không rỗng ngay cả khi mọi nhóm mức về 0.
        //
        // Thêm một cửa nữa cho lớp đơn ghi giữa plan-045 và #2031: dòng có thật
        // nhưng `taxable_base` chưa tồn tại (cột NULLABLE, thêm ở migration
        // `2000_05_18_…_alter_order_conditions_table`). `COUNT(cột)` không đếm
        // NULL, nên `base_count === 0` tách được "chưa bao giờ ghi" khỏi "ghi là 0".
        $ledgerExists = DB::table('order_conditions')
            ->where('conditionable_type', (new CustomerOrder)->getMorphClass())
            ->whereIn('conditionable_id', $ids->all())
            ->exists();

        $baseNeverWritten = $rows->isNotEmpty()
            && $rows->every(fn ($r) => (int) $r->base_count === 0);

        if (! $ledgerExists || $baseNeverWritten) {
            $rows = $this->itemLineRollup($ids);
        }

        return $this->assemble($rows);
    }

    /**
     * Σ thẳng từ dòng món — dùng khi đơn KHÔNG có sổ điều kiện (plan-045).
     *
     * #2188: đây là một nhánh dữ liệu cũ thật sự, và là ỨNG VIÊN XOÁ — không
     * phải chuyện đặt tên. Nó sống tới khi đơn tiền-plan-045 được reseed hoặc
     * backfill lần cuối; xoá nó trước đó thì hoá đơn của những đơn ấy in rỗng.
     * Đổi tên ở #2407 chỉ gỡ chữ `legacy` khỏi ĐỊNH DANH, không xoá nợ.
     *
     * Nó SAI trên đơn có giảm giá (xem `forOrders`); nhưng với đơn không giảm giá
     * nó khớp tuyệt đối, và đơn cũ thì không còn cách nào đúng hơn — dữ liệu để
     * làm đúng không tồn tại vào lúc chúng được ghi.
     *
     * @param  Collection<int, int|string>  $ids
     * @return Collection<int, object>
     */
    private function itemLineRollup($ids)
    {
        return DB::table('customer_order_items')
            ->whereIn('customer_order_id', $ids->all())
            ->where('status', '!=', OrderItemStatusEnum::Voided->value)
            ->selectRaw('COALESCE(tax_rate, 0) as rate, SUM(subtotal) as taxable, SUM(tax_amount) as tax')
            ->groupBy('rate')
            ->orderBy('rate')
            ->get();
    }

    /**
     * @param  Collection<int, object>  $rows  each row: {rate, taxable, tax}
     * @return array{net: float, tax: float, gross: float, by_rate: list<array{rate: float, taxable: float, tax: float}>}
     */
    private function assemble($rows): array
    {
        $byRate = [];
        $net = 0.0;
        $tax = 0.0;

        foreach ($rows as $row) {
            $taxable = (float) ($row->taxable ?? 0);
            $rowTax = (float) ($row->tax ?? 0);
            $byRate[] = [
                'rate' => (float) ($row->rate ?? 0),
                'taxable' => $taxable,
                'tax' => $rowTax,
            ];
            $net += $taxable;
            $tax += $rowTax;
        }

        return [
            'net' => $net,
            'tax' => $tax,
            'gross' => $net + $tax,
            'by_rate' => $byRate,
        ];
    }

    /**
     * @return array{net: float, tax: float, gross: float, by_rate: list<array{rate: float, taxable: float, tax: float}>}
     */
    private function empty(): array
    {
        return ['net' => 0.0, 'tax' => 0.0, 'gross' => 0.0, 'by_rate' => []];
    }
}
