<?php

declare(strict_types=1);

namespace App\Services\Till;

use App\Models\CashDeviceInventorySnapshot;
use App\Models\CashDeviceTransaction;
use App\Models\TillSession;
use App\Omnify\Enums\CashDeviceOutcomeEnum;
use App\Services\Shop\Contracts\BranchCashVarianceTolerance;

/**
 * T2 của #2876 (#2879) — đối soát BA CHÂN cho tiền mặt.
 *
 * ## Vấn đề nó giải
 *
 * Phía cổng thanh toán đã đủ ba chân từ plan-050:
 *
 *     order_payments  ↔  payment_settlements  ↔  gateway_payouts
 *     (sổ)               (bên xử lý)             (ngân hàng)
 *
 * Phía tiền mặt chỉ có HAI:
 *
 *     order_payments  ↔  till_cash_denomination_counts
 *     (sổ)               (NGƯỜI đếm tay)
 *
 * Chân giữa — hỏi chính cái máy đang GIỮ tiền — chưa bao giờ được nối, dù
 * adapter đã có `GetInventory` từ đầu và không ai gọi.
 *
 * ## Giá trị thật KHÔNG phải "phát hiện lệch" mà là PHÂN LOẠI lệch
 *
 * Hai chân chỉ cho ra MỘT con số lệch, và một con số không nói được lệch đó là
 * gì. Ba chân cho ra HAI con số, và đọc chéo chúng ra bốn ô:
 *
 * | lệch_máy | lệch_người | Đọc là |
 * |---|---|---|
 * | ≈ 0 | ≠ 0 | **người đếm sai** — tiền vẫn trong máy |
 * | ≠ 0 | ≈ 0 | **tiền ra khỏi máy ngoài sổ** — nghiêm trọng |
 * | ≠ 0 | ≈ nhau | tiền thật sự thiếu |
 *
 * Đây là thứ #2848 đang thiếu: ba cảnh báo lệch tiền treo ở 本郷店 + 人形町店
 * mà không ai phân định được nguyên nhân.
 *
 * ## Máy là NHÂN CHỨNG, không phải QUAN TOÀ
 *
 * Không có đường ghi nào ở đây. Service này chỉ ĐỌC và trả về phán đoán; nó
 * không sửa `TillCashEvent`, không sửa `counted_cash_amount`, không đóng ca.
 * Máy vẫn đếm sai được (tiền kẹt khe, tiền giả bị giữ lại) — và một máy được
 * quyền ghi đè người sẽ biến một sai số phần cứng thành một sự thật kế toán.
 */
final class CashDrawerReconciliationService
{
    public function __construct(private readonly BranchCashVarianceTolerance $tolerance) {}

    /**
     * Ngưỡng mặc định (minor units) khi brand chưa cấu hình.
     *
     * KHÔNG hardcode một ngưỡng cho mọi nơi là bài học của
     * `SettlementAlertService`: Stripe trả tiền theo ngày, PayPay theo tháng,
     * nên một ngưỡng chung "hoặc câm với cái này hoặc la hét với cái kia".
     * Ở đây cũng vậy — một quán bán 50 đơn/ngày và một quán bán 2000 đơn/ngày
     * không chịu được cùng một con số.
     */
    public const DEFAULT_TOLERANCE_MINOR = 100;

    /**
     * @return array{
     *   status: string,
     *   machine_variance_minor: ?int,
     *   human_variance_minor: ?int,
     *   verdict: string,
     *   excluded_denominations: list<string>,
     *   bill_reject_count: int,
     *   reason: ?string
     * }
     */
    public function reconcile(TillSession $session, ?int $toleranceMinor = null): array
    {
        $toleranceMinor ??= $this->toleranceForBranch($session);

        $opening = $this->snapshot($session, 'opening');
        $closing = $this->snapshot($session, 'closing');

        $excluded = $this->excludedDenominations($opening, $closing);
        $billReject = (int) ($closing?->bill_reject_count ?? 0);

        // Không có số liệu máy ⇒ KHÔNG kết luận. Đây là ca thật và phải chạy
        // được: máy mất kết nối lúc chốt ca thì quán VẪN phải đóng cửa được.
        // Mất khả năng đối soát tốt hơn mất khả năng chốt ca.
        if ($opening === null || $closing === null) {
            return $this->undetermined($session, $excluded, $billReject, 'không có ảnh chụp 在高 ở một hoặc cả hai mốc');
        }

        // Máy tự khai KHÔNG CHẮC về một mệnh giá ⇒ mọi con số dựng trên nó là
        // phỏng đoán. Báo động dựa trên phỏng đoán sẽ bắt quán đi tìm tiền
        // không mất — và lần thứ hai như vậy là lần cuối ai đó tin cảnh báo này.
        if ($excluded !== []) {
            return $this->undetermined($session, $excluded, $billReject, 'máy khai 在高不確定 ở một số mệnh giá');
        }

        $machineExpected = (int) $opening->total_minor
            + $this->machineNetMinor($session)
            + $this->cashEventNetMinor($session);

        $machineVariance = (int) $closing->total_minor - $machineExpected;
        $humanVariance = $this->humanVarianceMinor($session);

        return [
            'status' => 'reconciled',
            'machine_variance_minor' => $machineVariance,
            'human_variance_minor' => $humanVariance,
            'verdict' => $this->verdict($machineVariance, $humanVariance, $toleranceMinor),
            'excluded_denominations' => $excluded,
            'bill_reject_count' => $billReject,
            'reason' => null,
        ];
    }

    /**
     * Ngưỡng của BRAND, mặc định khi chưa cấu hình.
     *
     * Đi qua cổng công bố {@see BranchCashVarianceTolerance}: Payments không
     * cần biết ngưỡng sống ở bảng nào, và càng không cần biết `branches` chỉ có
     * `console_brand_id` chứ không có `brand_id`. Kiến thức đó ở lại module sở
     * hữu.
     *
     * Ngưỡng âm hoặc 0 được TÔN TRỌNG, không kẹp về mặc định: một brand đặt 0
     * nghĩa là "báo mọi lệch", và đó là lựa chọn hợp lệ của họ. Kẹp nó lại là
     * âm thầm cướp mất lựa chọn — đúng thứ ruling deploy đã cấm.
     */
    private function toleranceForBranch(TillSession $session): int
    {
        $branchId = $session->branch_id;

        if ($branchId === null) {
            return self::DEFAULT_TOLERANCE_MINOR;
        }

        return $this->tolerance->toleranceMinorForBranch((string) $branchId)
            ?? self::DEFAULT_TOLERANCE_MINOR;
    }

    /**
     * Bốn ô của bảng trong docblock lớp.
     */
    private function verdict(int $machine, int $human, int $tolerance): string
    {
        $machineOk = abs($machine) <= $tolerance;
        $humanOk = abs($human) <= $tolerance;

        return match (true) {
            $machineOk && $humanOk => 'ok',
            // Máy khớp, người lệch ⇒ tiền vẫn trong máy, người đếm sai.
            $machineOk => 'human_count_error',
            // Máy lệch, người khớp ⇒ tiền rời máy mà sổ không biết. Nặng nhất.
            $humanOk => 'cash_left_machine_off_book',
            default => 'cash_missing',
        };
    }

    /**
     * Tiền RÒNG máy đã nhận trong ca, theo sổ lượt thu (T1).
     *
     * Chỉ `finish`: bốn kết cục còn lại không để lại tiền trong máy —
     * `cancel` trả lại khách, `timeout`/`abort` thì tiền còn kẹt nhưng lượt đó
     * chưa thành khoản thu và `deposited` của nó KHÔNG nằm trong 在高 kỳ vọng
     * theo sổ. Cộng chúng vào là đếm hai lần một sự cố.
     */
    private function machineNetMinor(TillSession $session): int
    {
        $rows = CashDeviceTransaction::query()
            ->where('till_session_id', $session->id)
            ->where('outcome', CashDeviceOutcomeEnum::Finish->value)
            ->get(['deposited_minor', 'dispensed_minor']);

        $net = 0;

        foreach ($rows as $row) {
            $net += (int) $row->deposited_minor - (int) $row->dispensed_minor;
        }

        return $net;
    }

    /**
     * Tiền vào/ra giữa ca do NGƯỜI ghi (`till_cash_events`).
     *
     * Đọc qua `TillSessionService` sẽ kéo cả một cỗ máy trạng thái vào một phép
     * đọc; ở đây chỉ cần tổng có dấu.
     */
    private function cashEventNetMinor(TillSession $session): int
    {
        $rows = $session->cashEvents()->get(['event_type', 'amount']);

        $net = 0;

        foreach ($rows as $row) {
            $type = $row->event_type instanceof \BackedEnum ? $row->event_type->value : (string) $row->event_type;
            $amount = (int) round((float) $row->amount);

            $net += match ($type) {
                'paid_in', 'loan_from_safe' => $amount,
                'paid_out', 'pickup_to_safe' => -$amount,
                default => 0,
            };
        }

        return $net;
    }

    /**
     * Lệch của NGƯỜI — đã có sẵn trên `till_sessions`, không tính lại.
     *
     * Tính lại ở đây sẽ là công thức thứ hai cho cùng một con số, và hai công
     * thức sẽ lệch nhau đúng vào lúc cần chúng khớp.
     */
    private function humanVarianceMinor(TillSession $session): ?int
    {
        if ($session->counted_cash_amount === null || $session->expected_cash_amount === null) {
            return null;
        }

        return (int) round(((float) $session->counted_cash_amount - (float) $session->expected_cash_amount));
    }

    private function snapshot(TillSession $session, string $phase): ?CashDeviceInventorySnapshot
    {
        return CashDeviceInventorySnapshot::query()
            ->where('till_session_id', $session->id)
            ->where('count_phase', $phase)
            ->orderByDesc('captured_at')
            ->first();
    }

    /**
     * @return list<string>
     */
    private function excludedDenominations(?CashDeviceInventorySnapshot $a, ?CashDeviceInventorySnapshot $b): array
    {
        // KHÔNG dùng mệnh giá làm KHOÁ mảng: PHP ép khoá chuỗi-số thành int
        // ('5000' → 5000), nên `array_keys` trả về int và mọi phép so `===` với
        // chuỗi ở chỗ khác sẽ trượt — im lặng.
        $out = [];

        foreach ([$a, $b] as $snap) {
            foreach ((array) ($snap?->uncertain_denominations ?? []) as $denom) {
                $out[] = (string) $denom;
            }
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @param  list<string>  $excluded
     * @return array{status: string, machine_variance_minor: ?int, human_variance_minor: ?int, verdict: string, excluded_denominations: list<string>, bill_reject_count: int, reason: ?string}
     */
    private function undetermined(TillSession $session, array $excluded, int $billReject, string $reason): array
    {
        return [
            'status' => 'undetermined',
            'machine_variance_minor' => null,
            // Vế người VẪN trả về: mất chân máy không được làm mất luôn phép đo
            // vốn đã có. Chốt ca hôm nay dùng đúng con số này.
            'human_variance_minor' => $this->humanVarianceMinor($session),
            'verdict' => 'undetermined',
            'excluded_denominations' => $excluded,
            'bill_reject_count' => $billReject,
            'reason' => $reason,
        ];
    }
}
