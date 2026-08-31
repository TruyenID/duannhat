<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\CustomerOrder;
use App\Services\Payment\Contracts\OpenAccountDebtReads;

/**
 * #2063 — đơn nào ĐANG TREO tiền, và vì thế không được in biên lai / hoá đơn đỏ.
 *
 * Một đơn trả thiếu hoặc được ghi nợ vẫn in được hai tờ giấy tuyên bố quán ĐÃ
 * nhận tiền. Nhánh nặng nhất là ghi nợ TOÀN BỘ: payment `on_account` cộng thẳng
 * vào `paid_amount`, nên đơn đóng lại bình thường và mọi màn hình đọc ra "Hoàn
 * thành" — kèm nút *In biên lai* và *Xuất hoá đơn đỏ*.
 *
 * # Trạng thái DẪN XUẤT, không thêm enum
 *
 * Đơn ghi nợ VẪN `closed` trong DB. Doanh thu, Z-report và việc nhả bàn **không
 * đổi** — đúng luật plan-038 *"debt = revenue, not cash"*. Chỉ nhãn hiển thị và
 * cổng in đổi. Thêm một trạng thái vào enum sẽ kéo theo mọi bộ lọc, báo cáo và
 * máy trạng thái đang đọc `status`, để đổi một câu hỏi thuộc về TRÌNH BÀY.
 *
 * # Ghép hai định nghĩa ĐÃ CÓ, không viết định nghĩa thứ ba
 *
 *     treo = (paying && paid < total)  HOẶC  (có on_account chưa tất toán)
 *
 * Vế trái là {@see CustomerOrder::scopePartPaid} của Ordering; vế phải là
 * {@see OpenAccountDebtReads} của Payments. Hai nghĩa vụ này cố ý KHÔNG gộp ở
 * tầng dữ liệu — nợ ghi sổ là quán CHO nợ có chủ ý và thu theo
 * `settles_payment_id`, còn đơn trả thiếu là đơn không ai kết thúc. Gộp chúng
 * thành một con số thì mất khả năng phân biệt "đã cấp X tín dụng" với "X đi
 * mất".
 *
 * Nhưng với câu hỏi "tờ giấy này có được in không" thì cả hai đều trả lời CÓ
 * TREO, nên phép hợp nằm ở ĐÂY — tầng lắp ráp — chứ không phải bằng cách nới
 * một trong hai định nghĩa kia.
 *
 * # Theo LÔ
 *
 * Cờ được đóng dấu lên các đường ĐỌC vốn trả về danh sách đơn. Hỏi từng đơn là
 * N+1 trên đúng màn hình bận nhất của quán, nên phép hỏi nợ đi theo lô
 * ({@see OpenAccountDebtReads::orderIdsWithOpenDebt}).
 */
final class OrderHoldStatus
{
    public function __construct(private readonly OpenAccountDebtReads $debts) {}

    /**
     * Trong tập đơn này, đơn nào đang treo.
     *
     * @param  iterable<CustomerOrder>  $orders
     * @return array<string, bool> khoá là order id, CHỈ chứa đơn đang treo
     */
    public function forOrders(iterable $orders): array
    {
        $held = [];
        $ids = [];

        foreach ($orders as $order) {
            $id = (string) $order->id;
            $ids[] = $id;

            if ($this->isPartPaid($order)) {
                $held[$id] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        foreach ($this->debts->orderIdsWithOpenDebt($ids) as $withDebt) {
            $held[(string) $withDebt] = true;
        }

        return $held;
    }

    /** Một đơn. Chỉ dùng ở đường đã có sẵn đúng một đơn — ngược lại dùng `forOrders`. */
    public function isHeld(CustomerOrder $order): bool
    {
        return $this->forOrders([$order]) !== [];
    }

    /**
     * Vế "trả thiếu", đọc từ chính hàng đơn — KHÔNG query lại.
     *
     * Cùng vị ngữ với `CustomerOrder::scopePartPaid` (`status = paying` và
     * `paid_amount < total_amount`). Viết lại ở đây thay vì gọi scope vì scope
     * là một BỘ LỌC trên query builder, còn ở đây ta đã cầm sẵn model — gọi
     * scope sẽ là một vòng đi-về nữa cho dữ liệu đang nằm trong tay.
     *
     * Hai chỗ phải cùng một luật; nếu `scopePartPaid` đổi thì đây phải đổi theo,
     * và có test ghim đúng cặp đó.
     */
    private function isPartPaid(CustomerOrder $order): bool
    {
        $status = $order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status;

        return $status === 'paying' && (float) $order->paid_amount < (float) $order->total_amount;
    }
}
