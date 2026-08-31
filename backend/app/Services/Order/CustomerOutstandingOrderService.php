<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Models\CustomerOrder;
use Illuminate\Database\Eloquent\Collection;

/**
 * #1596 — "khách này còn nợ những đơn nào, và bao nhiêu" — chuyển từ
 * `CustomerService` sang Ordering.
 *
 * Đây là hai truy vấn **trên đơn hàng**, chỉ lọc thêm theo `customer_id`. Chúng
 * nằm ở CustomerEngagement vì màn hình gọi chúng là màn hình khách, không phải
 * vì dữ liệu thuộc về đó — đúng loại "ranh giới vẽ theo màn hình" mà #962 đã
 * gỡ nhiều lần.
 *
 * **Cố ý KHÔNG phải cổng công bố.** Nó trả `Collection<CustomerOrder>` vì chỗ
 * gọi (`CustomerController::outstanding`) đưa thẳng vào `CustomerOrderResource`
 * — đổi sang snapshot là **đổi payload API công khai**, việc riêng. Chỗ gọi là
 * controller, tức Composition, nên được phép phụ thuộc Ordering.
 */
final class CustomerOutstandingOrderService
{
    /**
     * Đơn khách còn nợ: trong vòng đời `paying` VÀ `paid_amount < total_amount`.
     *
     * `checkout` bị loại (chưa thu đồng nào — là vé đang mở, không phải nợ);
     * `closed`/`voided` cũng vậy. Sắp theo `checkout_at` giảm dần để nợ mới nổi
     * lên trên. Chép nguyên từ bản cũ, kể cả thứ tự.
     *
     * @return Collection<int, CustomerOrder>
     */
    public function listOutstanding(string $customerId, string $organizationId, ?string $branchId = null): Collection
    {
        $query = CustomerOrder::query()
            ->with('conditions')
            // #1992 — vị ngữ "trả chưa đủ" KHÔNG còn viết ở đây. Nó là
            // `CustomerOrder::partPaid()`, dùng chung với
            // `PartPaidOrderReads` (bảng tra cứu ở POS). Trước đó hai chỗ tự
            // viết hai lần, hai công nghệ truy vấn khác nhau.
            ->partPaid()
            ->where('customer_id', $customerId)
            ->where('organization_id', $organizationId)
            ->orderByDesc('checkout_at');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        return $query->get();
    }

    /**
     * Tổng số tiền còn nợ, dạng chuỗi hai chữ số thập phân.
     *
     * Trả **chuỗi** chứ không float: đây là con số đi thẳng ra payload API, và
     * bản cũ đã `number_format(...)`. Đổi kiểu ở đây là đổi payload.
     */
    public function outstandingTotal(string $customerId, string $organizationId, ?string $branchId = null): string
    {
        return $this->totalOf($this->listOutstanding($customerId, $organizationId, $branchId));
    }

    /**
     * Tổng của một tập đơn ĐÃ CÓ TRONG TAY.
     *
     * #1992 — `CustomerController::outstanding` gọi `listOutstanding()` rồi
     * `outstandingTotal()`, mà bản cũ của hàm thứ hai gọi LẠI hàm thứ nhất: mỗi
     * lần mở PaymentDialog chạy đúng truy vấn đó hai lần. Chỗ gọi nào đã có
     * danh sách thì cộng thẳng ở đây; `outstandingTotal()` vẫn giữ nguyên chữ ký
     * cho chỗ chỉ cần con số.
     *
     * @param  Collection<int, CustomerOrder>  $orders
     */
    public function totalOf(Collection $orders): string
    {
        $total = $orders->reduce(
            fn (float $carry, CustomerOrder $o): float => $carry + (float) $o->total_amount - (float) $o->paid_amount,
            0.0,
        );

        return number_format(max(0.0, $total), 2, '.', '');
    }
}
