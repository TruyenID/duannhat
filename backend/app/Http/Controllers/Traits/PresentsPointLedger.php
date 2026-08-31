<?php

namespace App\Http\Controllers\Traits;

use App\Models\Coupon;
use App\Models\CustomerPointEntry;

/**
 * #1718 — hình dạng JSON của sổ điểm và nhật ký đổi thưởng, dùng chung cho
 * cả bốn màn: HQ và shop, mỗi bên một cặp endpoint.
 *
 * Tách ra trait chứ không chép sang controller thứ hai, vì lỗi đã trả giá ở
 * #1713 đúng là dạng đó: `redemptionLog()` bọc `withTrashed()` còn `history()`
 * thì không, nên cùng một lượt đổi mà màn nhật ký hiện tên phần thưởng còn màn
 * sổ điểm để trống. Hai bản sao thì trôi; một chỗ thì không.
 *
 * Đây là tầng TRÌNH BÀY thuần: không truy vấn, không quyết định phạm vi. Ai
 * được xem cái gì là việc của controller (`authorizeOrganization`, brand/branch
 * scope) — trait này chỉ trả lời "một dòng sổ trông như thế nào".
 */
trait PresentsPointLedger
{
    /**
     * Một dòng sổ điểm của một khách.
     *
     * Giàu hơn bản customer-web ở đúng một chỗ: dòng `redeem` mang theo TÌNH
     * TRẠNG tấm coupon đã mint. Khách chỉ cần biết mình có mã gì; người trực
     * quầy thì đang trả lời "mã này dùng được không" — mà câu đó cần
     * `times_used` và `valid_until`, không phải cái mã.
     *
     * @return array<string, mixed>
     */
    protected function pointEntryToArray(CustomerPointEntry $entry): array
    {
        $coupon = $entry->coupon;

        return [
            'id' => $entry->id,
            // Có dấu (BR-PT01). Số dư là TỔNG CỘNG của chính những dòng này,
            // nên đổi sang dương cho "đẹp" là làm nó không cộng lại được.
            'points' => (int) $entry->points,
            'kind' => $entry->kind instanceof \BackedEnum ? $entry->kind->value : $entry->kind,
            'note' => $entry->note,
            'created_at' => $entry->created_at?->toISOString(),
            'order_code' => $entry->customerOrder?->order_code,
            'order_id' => $entry->customer_order_id,
            // #1713 — còn tên kể cả khi phần thưởng đã bị xoá mềm, nhờ
            // `withTrashed()` ở `CustomerPointService::history()`.
            'reward_name' => $entry->pointReward?->name,
            'coupon' => $coupon === null ? null : [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'times_used' => (int) $coupon->times_used,
                'valid_until' => $coupon->valid_until?->toISOString(),
                'status' => $this->couponUsageStatus($coupon),
            ],
        ];
    }

    /**
     * Một dòng nhật ký đổi thưởng.
     *
     * @return array<string, mixed>
     */
    protected function redemptionToArray(CustomerPointEntry $entry): array
    {
        $customer = $entry->customer;
        $coupon = $entry->coupon;

        return [
            'id' => $entry->id,
            'points' => (int) $entry->points,
            'created_at' => $entry->created_at?->toISOString(),
            'customer' => $customer === null ? null : [
                'id' => $customer->id,
                'name' => $customer->full_name,
                'phone' => $customer->phone,
                'email' => $customer->email,
            ],
            'reward' => $entry->pointReward === null ? null : [
                'id' => $entry->pointReward->id,
                'name' => $entry->pointReward->name,
            ],
            'coupon' => $coupon === null ? null : [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'status' => $this->couponUsageStatus($coupon),
                'times_used' => (int) $coupon->times_used,
                'valid_until' => $coupon->valid_until?->toISOString(),
            ],
        ];
    }

    /**
     * Tình trạng tấm coupon cá nhân, theo đúng ba trạng thái mà người vận hành
     * hỏi: đã tiêu chưa · còn tiêu được không · đã rơi vãi chưa.
     *
     * KHÔNG dùng `CouponService::computeStatus()`: cái đó trả thêm
     * `draft`/`scheduled`/`exhausted` — từ vựng của chiến dịch HQ. Coupon cá
     * nhân nào cũng mint ra ở `status = draft` và `usage_limit_total = 1`, nên
     * cùng một tấm chưa dùng sẽ hiện là "draft" còn tấm đã dùng hiện
     * "exhausted", và không người vận hành nào đọc được cặp từ đó.
     */
    protected function couponUsageStatus(Coupon $coupon): string
    {
        if ((int) $coupon->times_used >= 1) {
            return 'used';
        }

        // `valid_until` là mốc UTC tuyệt đối (mint = now + N ngày), nên so
        // instant với instant — không phải một ngày nghiệp vụ.
        if ($coupon->valid_until !== null && $coupon->valid_until->isPast()) {
            return 'expired';
        }

        return 'unused';
    }
}
