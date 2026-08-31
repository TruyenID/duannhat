<?php

declare(strict_types=1);

namespace App\Services\Payment\Internal;

use App\Services\Payment\Contracts\BranchPaymentTotals;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * #1622 — hiện thực {@see BranchPaymentTotals}.
 *
 * Hai truy vấn chép NGUYÊN từ `PosRevenueService`, kể cả những mệnh đề mà bản cũ
 * phải viết hẳn một đoạn comment để giải thích:
 *
 * - `whereNull('metadata->settles_payment_id')` — loại bút toán tất toán của
 *   thanh toán bất đồng bộ (#1125); giữ lại là **đếm hai lần**.
 * - `orWhere('metadata->dispute_kind', 'reinstatement')` — nhánh THẮNG tranh
 *   chấp (#1123).
 * - `whereIn('op.status', ['succeeded', 'refunded'])` ở method thứ hai: bản gốc
 *   giữ `+X` (đổi sang `refunded`) và thêm `-X` (`succeeded`), nên **cộng cả
 *   hai trạng thái** mới ra số ròng. Lọc bỏ dòng hoàn tiền — như bản còn cũ hơn
 *   từng làm — là báo cáo số tiền đã trả lại khách.
 *
 * Vẫn query builder thô: bản cũ như vậy, và đổi sang Eloquent là đổi số truy vấn
 * trên đường báo cáo — việc riêng.
 */
final class EloquentBranchPaymentTotals implements BranchPaymentTotals
{
    public function reversalTotal(
        string $branchId,
        string $organizationId,
        DateTimeInterface $from,
        DateTimeInterface $to,
    ): float {
        return (float) DB::table('order_payments')
            ->where('branch_id', $branchId)
            ->where('organization_id', $organizationId)
            ->where('status', 'succeeded')
            ->where(fn ($reversal) => $reversal
                ->whereNotNull('refund_of_id')
                ->orWhere('metadata->dispute_kind', 'reinstatement'))
            ->whereNull('deleted_at')
            ->whereNull('metadata->settles_payment_id')
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');
    }

    public function netByPaymentMethod(
        string $branchId,
        string $organizationId,
        DateTimeInterface $from,
        DateTimeInterface $to,
    ): array {
        return DB::table('order_payments as op')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'op.payment_method_id')
            ->where('op.branch_id', $branchId)
            ->where('op.organization_id', $organizationId)
            ->whereIn('op.status', ['succeeded', 'refunded'])
            ->whereNull('op.deleted_at')
            ->whereNull('op.metadata->settles_payment_id')
            ->whereBetween('op.paid_at', [$from, $to])
            ->selectRaw('op.payment_method_id as method_id, pm.code as code, pm.name as name, SUM(op.amount) as amount')
            ->groupBy('op.payment_method_id', 'pm.code', 'pm.name')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r): array => [
                'method_id' => $r->method_id,
                'code' => $r->code,
                'name' => $r->name,
                'amount' => (int) $r->amount,
            ])
            ->all();
    }
}
