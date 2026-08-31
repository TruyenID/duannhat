<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

use Tests\Feature\Print\ShiftLabelsParityTest;

/**
 * plan-053 T5.1d (#1934) — 48 nhãn của phiếu 精算, ba locale.
 *
 * Đối ứng `shiftLabels` bên Go. **KHÔNG chép tay**: file này được SINH từ khoá
 * `shift_labels` của `print_contract_golden.json`, và
 * {@see ShiftLabelsParityTest} ghim lại từng chuỗi.
 *
 * Vì sao ghim GIÁ TRỊ chứ không chỉ tên trường: một nhãn sai không làm gì đỏ —
 * nó hiện ra dưới dạng MỘT DÒNG SAI CHỮ trên phiếu 精算, và 精算 là chứng từ
 * đối soát tiền mặt cuối ca. Người phát hiện sẽ là thu ngân đang đếm két.
 */
final class ShiftLabels
{
    public function __construct(
        public readonly string $acctCorrection = '',
        public readonly string $cashMovementTotal = '',
        public readonly string $chainFinal = '',
        public readonly string $chainHandover = '',
        public readonly string $chainLabel = '',
        public readonly string $chainShift = '',
        public readonly string $chainShiftUnit = '',
        public readonly string $chainTitle = '',
        public readonly string $checkCount = '',
        public readonly string $countUnit = '',
        public readonly string $countedCash = '',
        public readonly string $denomination = '',
        public readonly string $discountGeneric = '',
        public readonly string $discounts = '',
        public readonly string $drawerCheck = '',
        public readonly string $expectedCash = '',
        public readonly string $grossSales = '',
        public readonly string $guestUnit = '',
        public readonly string $handoverTitle = '',
        public readonly string $itemDiscount = '',
        public readonly string $itemSurcharge = '',
        public readonly string $itemUnit = '',
        public readonly string $netSales = '',
        public readonly string $nonCashChange = '',
        public readonly string $nonCashPaid = '',
        public readonly string $nonCashUnpaid = '',
        public readonly string $operator = '',
        public readonly string $operatorNone = '',
        public readonly string $paidIn = '',
        public readonly string $paidOut = '',
        public readonly string $paymentMethods = '',
        public readonly string $period = '',
        public readonly string $phone = '',
        public readonly string $pieceUnit = '',
        public readonly string $rateTarget = '',
        public readonly string $salesBreakdown = '',
        public readonly string $serviceCharge = '',
        public readonly string $serviceChargeTax = '',
        public readonly string $tax = '',
        public readonly string $taxBreakdown = '',
        public readonly string $taxTotal = '',
        public readonly string $taxableSales = '',
        public readonly string $till = '',
        public readonly string $title = '',
        public readonly string $variance = '',
        public readonly string $voidBills = '',
        public readonly string $voidPaid = '',
        public readonly string $voidUnpaid = '',
    ) {}

    /**
     * Bảng nhãn cho một locale.
     *
     * Locale lạ rơi về **ja**, đúng như `labelsFor` bên Go — mặc định là tiếng Nhật, không phải tiếng Anh.
     */
    public static function forLocale(string $locale): self
    {
        return match ($locale) {
            'en' => new self(
                acctCorrection: 'Correction',
                cashMovementTotal: 'Cash in/out total',
                chainFinal: 'final',
                chainHandover: 'handover',
                chainLabel: 'Chain',
                chainShift: 'Shift ',
                chainShiftUnit: ' shifts',
                chainTitle: 'CHAIN SETTLEMENT',
                checkCount: 'Check count',
                countUnit: 'x',
                countedCash: 'Counted cash',
                denomination: 'Denomination',
                discountGeneric: 'Discount',
                discounts: 'Discounts',
                drawerCheck: 'Drawer check',
                expectedCash: 'Expected cash',
                grossSales: 'Gross sales',
                guestUnit: ' guests',
                handoverTitle: 'HANDOVER',
                itemDiscount: 'Item discount',
                itemSurcharge: 'Item surcharge',
                itemUnit: ' items',
                netSales: 'Net sales',
                nonCashChange: 'Non-cash change',
                nonCashPaid: 'Paid',
                nonCashUnpaid: 'Unpaid',
                operator: 'Operator',
                operatorNone: '(not set)',
                paidIn: 'Cash in',
                paidOut: 'Cash out',
                paymentMethods: 'Payment methods',
                period: 'Period',
                phone: 'Tel',
                pieceUnit: 'x',
                rateTarget: '%s%% taxable',
                salesBreakdown: 'Sales breakdown',
                serviceCharge: 'Service charge',
                serviceChargeTax: 'Service charge tax',
                tax: 'Tax',
                taxBreakdown: 'Tax breakdown',
                taxTotal: 'Total tax',
                taxableSales: 'Taxable sales',
                till: 'Reg',
                title: 'SETTLEMENT',
                variance: 'Variance',
                voidBills: 'Voided bills',
                voidPaid: 'Paid',
                voidUnpaid: 'Unpaid',
            ),
            'vi' => new self(
                acctCorrection: 'Dieu chinh',
                cashMovementTotal: 'Tong thu chi',
                chainFinal: 'ket ca cuoi',
                chainHandover: 'ban giao',
                chainLabel: 'Chuoi',
                chainShift: 'Ca ',
                chainShiftUnit: ' ca',
                chainTitle: 'KET CA CUOI (CHUOI)',
                checkCount: 'So lan TT',
                countUnit: 'x',
                countedCash: 'Tien dem',
                denomination: 'Menh gia',
                discountGeneric: 'Giam gia',
                discounts: 'Giam gia',
                drawerCheck: 'Kiem quy',
                expectedCash: 'Tien du kien',
                grossSales: 'Tong doanh thu',
                guestUnit: ' khach',
                handoverTitle: 'BAN GIAO CA',
                itemDiscount: 'Giam gia mon',
                itemSurcharge: 'Phu thu mon',
                itemUnit: ' mon',
                netSales: 'Doanh thu thuan',
                nonCashChange: 'Tien thoi khac',
                nonCashPaid: 'Da tra',
                nonCashUnpaid: 'Chua tra',
                operator: 'Nguoi phu trach',
                operatorNone: '(chua dat)',
                paidIn: 'Thu tien',
                paidOut: 'Chi tien',
                paymentMethods: 'Phuong thuc TT',
                period: 'Ky',
                phone: 'SDT',
                pieceUnit: 'x',
                rateTarget: 'Chiu thue %s%%',
                salesBreakdown: 'Chi tiet doanh thu',
                serviceCharge: 'Phi phuc vu',
                serviceChargeTax: 'Thue phi phuc vu',
                tax: 'Thue',
                taxBreakdown: 'Chi tiet thue',
                taxTotal: 'Tong thue',
                taxableSales: 'DT chiu thue',
                till: 'Quay',
                title: 'KET CA',
                variance: 'Chenh lech',
                voidBills: 'Huy hoa don',
                voidPaid: 'Da TT',
                voidUnpaid: 'Chua TT',
            ),
            default => new self(
                acctCorrection: '会計修正',
                cashMovementTotal: '入出金合計金額',
                chainFinal: '精算',
                chainHandover: '引き継ぎ',
                chainLabel: 'チェーン',
                chainShift: 'シフト',
                chainShiftUnit: ' シフト',
                chainTitle: '精算（チェーン）',
                checkCount: '会計回数',
                countUnit: '件',
                countedCash: 'レジ金額',
                denomination: '金種',
                discountGeneric: '割引',
                discounts: '割引・割増',
                drawerCheck: 'レジ点検',
                expectedCash: '想定レジ金額',
                grossSales: '総売上',
                guestUnit: '人',
                handoverTitle: '引き継ぎ',
                itemDiscount: '個別割引',
                itemSurcharge: '個別割増',
                itemUnit: '個',
                netSales: '純売上',
                nonCashChange: '現金以外おつり',
                nonCashPaid: '支払額',
                nonCashUnpaid: '不支払額',
                operator: '担当者',
                operatorNone: '未設定',
                paidIn: '入金',
                paidOut: '出金',
                paymentMethods: '支払方法',
                period: '対象期間',
                phone: '電話番号',
                pieceUnit: '枚',
                rateTarget: '%s%%対象',
                salesBreakdown: '売上内訳',
                serviceCharge: 'サービス料',
                serviceChargeTax: 'サービス料消費税',
                tax: '消費税',
                taxBreakdown: '消費税内訳',
                taxTotal: '消費税総額',
                taxableSales: '課税売上',
                till: 'レジ',
                title: '精算',
                variance: '過不足',
                voidBills: '伝票削除',
                voidPaid: '会計済',
                voidUnpaid: '未会計',
            ),
        };
    }

    /** @return array<string, string> tên trường Go => chuỗi — khoá đối chiếu hợp đồng. */
    public function toGoFieldMap(): array
    {
        $out = [];

        foreach ((new \ReflectionClass($this))->getConstructor()?->getParameters() ?? [] as $p) {
            $php = $p->getName();
            $out[ucfirst($php)] = $this->{$php};
        }

        return $out;
    }
}
