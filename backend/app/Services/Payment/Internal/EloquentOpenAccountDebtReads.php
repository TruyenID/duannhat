<?php

declare(strict_types=1);

namespace App\Services\Payment\Internal;

use App\Models\OrderPayment;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Payment\Contracts\OpenAccountDebt;
use App\Services\Payment\Contracts\OpenAccountDebtReads;
use App\Support\BusinessClock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * #1993 — hiện thực {@see OpenAccountDebtReads}.
 *
 * Vị ngữ chép từ `DebtController::openDebtQuery()`, cố ý không đổi nghĩa: PR này
 * đổi chỗ luật tiền, không đổi số tiền.
 *
 * ## Bảng NGOÀI đi qua model, và đó là cách bộ lọc xoá mềm biến mất khỏi tầm tay
 *
 * Bản cũ là `DB::table('order_payments as p')` — query builder trần, **không**
 * áp global scope nào, nên `deleted_at` phải nhớ mà viết. Nó đã quên, và đó
 * chính là #1993.
 *
 * Đi qua {@see OrderPayment} thì `SoftDeletes` tự phát `order_payments.deleted_at
 * is null`. Điều kiện DUY NHẤT để việc đó chạy được là **không đặt bí danh cho
 * bảng ngoài**: scope phát tên cột theo `$model->getTable()`, nên
 * `from('order_payments as p')` sẽ sinh ra một cột không tồn tại. Bí danh `p`
 * không mua được gì ngoài vài ký tự, nên nó bị bỏ — viết đủ `order_payments.` là
 * cái giá để lấy về thứ đúng-theo-cấu-trúc, và là cùng lối #1801 đã chọn cho
 * `LedgerDriftScanner`.
 *
 * ## Đúng MỘT bộ lọc còn phải viết tay, và không thể khác
 *
 * Subquery `settle` là **tự đối chiếu chính bảng đó**, nên nó bắt buộc có bí
 * danh, nên nó không thể là một truy vấn model. `whereNull('settle.deleted_at')`
 * vì thế là thủ công — và đó là mệnh đề mà thiếu nó thì MẤT tiền chứ không phải
 * đẻ ra tiền, nên nó có test đỏ riêng canh chừng thay cho scope.
 *
 * ## Bù trừ hoàn: làm MỘT LẦN ở đây
 *
 * Bản cũ tính hai kiểu cho hai màn hình — `index()` cộng `SUM` trong SQL,
 * `show()` gấp dòng hoàn vào dòng gốc trong PHP. Hai phép tính cho cùng một con
 * số là đúng loại chênh lệch đọc ra thành "tiền tự sinh hoặc tiền biến mất".
 */
final class EloquentOpenAccountDebtReads implements OpenAccountDebtReads
{
    public function openDebtsForBranch(string $branchId, ?string $from = null, ?string $to = null): array
    {
        $rows = $this->openDebtRows($branchId, $from, $to);

        // Dòng đảo đi cùng để bù trừ (#821 A6); bản thân chúng không phải nợ.
        // Gấp mỗi dòng vào khoản nợ nó đảo, thay vì liệt kê ra.
        $reversals = [];
        foreach ($rows as $row) {
            if ($row->refund_of_id !== null) {
                $key = (string) $row->refund_of_id;
                $reversals[$key] = ($reversals[$key] ?? 0.0) + (float) $row->amount;
            }
        }

        $debts = [];
        foreach ($rows as $row) {
            if ($row->refund_of_id !== null || (float) $row->amount <= 0) {
                continue;
            }

            $amount = (float) $row->amount;
            $net = $amount + ($reversals[(string) $row->id] ?? 0.0);

            // Hoàn hết thì nợ đã tắt — không phải nợ đang mở, và không được
            // chiếm một chỗ trên trang kết quả.
            if ($net <= 0) {
                continue;
            }

            $debts[] = new OpenAccountDebt(
                paymentId: (string) $row->id,
                orderId: (string) $row->customer_order_id,
                amount: $amount,
                netAmount: $net,
                // `toDateTimeString()` chứ không phải `(string)` hay ISO: giữ
                // ĐÚNG chuỗi `Y-m-d H:i:s` mà payload cũ trả ra — xem docblock
                // của {@see OpenAccountDebt} về việc pos-web in thẳng nó.
                createdAt: $row->created_at?->toDateTimeString() ?? '',
                note: $row->note === null ? null : (string) $row->note,
            );
        }

        return $debts;
    }

    /**
     * Dòng thô: cả khoản nợ lẫn dòng đảo của nó, đã loại những khoản có
     * settlement còn sống.
     *
     * @return Collection<int, OrderPayment>
     */
    public function orderIdsWithOpenDebt(iterable $orderIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (string) $id, is_array($orderIds) ? $orderIds : iterator_to_array($orderIds)),
            static fn (string $id) => $id !== '',
        )));

        // Tập rỗng ⇒ KHÔNG hỏi DB. `whereIn([])` là một câu luôn rỗng, nhưng nó
        // vẫn tốn một vòng đi-về trên đúng đường đọc mà cờ này sẽ đóng dấu.
        if ($ids === []) {
            return [];
        }

        // Cùng vị ngữ với `openDebtRows`, chỉ đổi trục lọc từ chi nhánh sang tập
        // đơn — nên ba luật tiền ở đó (số RÒNG, settlement còn sống, xoá mềm hai
        // vế) đi theo nguyên vẹn thay vì được chép lại.
        $rows = $this->openDebtQueryForOrders($ids)->get([
            'order_payments.id',
            'order_payments.customer_order_id',
            'order_payments.amount',
            'order_payments.refund_of_id',
        ]);

        $reversals = [];
        foreach ($rows as $row) {
            if ($row->refund_of_id !== null) {
                $key = (string) $row->refund_of_id;
                $reversals[$key] = ($reversals[$key] ?? 0.0) + (float) $row->amount;
            }
        }

        $withDebt = [];
        foreach ($rows as $row) {
            if ($row->refund_of_id !== null || (float) $row->amount <= 0) {
                continue;
            }

            // Hoàn hết ⇒ nợ đã tắt. Bỏ bước bù trừ này thì một khoản nợ đã hoàn
            // toàn bộ vẫn treo đơn lại mãi mãi, và quán không in được hoá đơn
            // cho một đơn không còn nợ đồng nào.
            if ((float) $row->amount + ($reversals[(string) $row->id] ?? 0.0) <= 0) {
                continue;
            }

            $withDebt[(string) $row->customer_order_id] = true;
        }

        return array_keys($withDebt);
    }

    /**
     * Vị ngữ "khoản nợ ghi sổ còn MỞ", dùng chung cho cả hai trục lọc (#2063).
     *
     * Tách ra để `openDebtRows` (theo chi nhánh) và `orderIdsWithOpenDebt` (theo
     * tập đơn) KHÔNG BAO GIỜ trôi khỏi nhau. Ba luật tiền trong đây đều đã trả
     * giá bằng sự cố thật; hai bản chép sẽ đánh rơi ít nhất một, và bản đánh rơi
     * sẽ là bản không ai đọc lại.
     */
    private function openDebtBaseQuery()
    {
        return OrderPayment::query()
            ->join('payment_methods as pm', 'pm.id', '=', 'order_payments.payment_method_id')
            // KHÔNG lọc `pm.deleted_at` — một khoản nợ phải sống lâu hơn phương
            // thức thanh toán nó được ghi bằng, nếu không thì shop dọn lại danh
            // sách phương thức là xoá sạch nợ toàn cửa hàng. Có test ghim.
            ->where('pm.type', 'on_account')
            // #821 A6 — nợ là SỐ RÒNG: mang cả dòng gốc (`refunded`) lẫn dòng
            // đảo (`succeeded`) rồi bù trừ ở người gọi.
            ->whereIn('order_payments.status', [
                PaymentStatusEnum::Succeeded->value,
                PaymentStatusEnum::Refunded->value,
            ])
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('order_payments as settle')
                    // Settlement THẤT BẠI (thẻ từ chối) không được xoá nợ, và
                    // settlement ĐÃ HOÀN phải trả nợ lại: chỉ settlement còn sống
                    // mới tính. `refund_of_id IS NULL` chặn bản đảo của chính
                    // settlement đóng vai settlement — nó thừa kế
                    // `settles_payment_id` (xem OrderPaymentService::refund).
                    ->where('settle.status', PaymentStatusEnum::Succeeded->value)
                    ->whereNull('settle.refund_of_id')
                    // #1993 — bộ lọc xoá mềm DUY NHẤT còn viết tay: xem docblock
                    // của class về việc vì sao nhánh này không thể là model.
                    ->whereNull('settle.deleted_at')
                    // Đối chiếu dòng đảo với settlement của khoản nợ CHA, để một
                    // khoản đã thu thì dòng đảo của nó cũng bị loại theo và không
                    // bao giờ nổi lên một mình thành dòng âm.
                    ->whereRaw(
                        "JSON_EXTRACT(settle.metadata, '$.settles_payment_id') "
                        .'= COALESCE(order_payments.refund_of_id, order_payments.id)'
                    );
            });
    }

    private function openDebtQueryForOrders(array $orderIds)
    {
        return $this->openDebtBaseQuery()->whereIn('order_payments.customer_order_id', $orderIds);
    }

    private function openDebtRows(string $branchId, ?string $from, ?string $to)
    {
        $query = $this->openDebtBaseQuery()
            ->where('order_payments.branch_id', $branchId);

        // #1091 — `from`/`to` là NGÀY KINH DOANH của chi nhánh, không phải hai
        // chuỗi đem so thẳng với một cột UTC.
        //
        // Bản cũ làm đúng cái sai đó: quản lý ở Tokyo lọc "2026-08-06" thì chuỗi
        // ấy được hiểu là 00:00 UTC, tức 09:00 giờ quán — chín tiếng đầu ngày
        // bán hàng rơi sang ngày hôm trước của báo cáo. Cùng lớp lỗi #1091 đã sửa
        // cho `TillSession.business_date`, và "báo cáo gom theo ngày" nằm đúng
        // trong danh sách thứ PHẢI dùng giờ kinh doanh.
        //
        // Biên NỬA MỞ `[from, until)` là do `utcRangeForBusinessDates` trả về —
        // đổi `<=` cũ thành `<` là CỐ Ý: `<=` trên một mốc 00:00 sẽ nuốt trọn
        // ngày kế tiếp hoặc đánh rơi cả ngày cuối, tuỳ cách người gọi viết.
        [$fromUtc, $untilUtc] = BusinessClock::utcRangeForBusinessDates($branchId, $from, $to);

        if ($fromUtc !== null) {
            $query->where('order_payments.created_at', '>=', $fromUtc);
        }
        if ($untilUtc !== null) {
            $query->where('order_payments.created_at', '<', $untilUtc);
        }

        return $query
            ->orderBy('order_payments.created_at')
            ->get([
                'order_payments.id',
                'order_payments.customer_order_id',
                'order_payments.amount',
                'order_payments.refund_of_id',
                'order_payments.created_at',
                'order_payments.note',
            ]);
    }
}
