<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d (#1934) — 11 nhãn của phiếu レジ開け (mở ca), ba locale.
 *
 * Đối ứng `shiftOpenLabels` bên Go, sinh từ khoá `shift_open_labels` của
 * `print_contract_golden.json`. Tách khỏi {@see ShiftLabels} vì Go tách — phiếu
 * mở ca và 精算 dùng hai bảng khác nhau, và gộp chúng là mời một nhãn của phiếu
 * này rơi sang phiếu kia.
 */
final class ShiftOpenLabels
{
    public function __construct(
        public readonly string $amountHeader = '',
        public readonly string $denomHeader = '',
        public readonly string $device = '',
        public readonly string $note = '',
        public readonly string $openedAt = '',
        public readonly string $operator = '',
        public readonly string $operatorNone = '',
        public readonly string $qtyHeader = '',
        public readonly string $qtyUnit = '',
        public readonly string $title = '',
        public readonly string $total = '',
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
                amountHeader: 'Amount',
                denomHeader: 'Denomination',
                device: 'Device',
                note: 'Note',
                openedAt: 'Opened at',
                operator: 'Operator',
                operatorNone: '(not set)',
                qtyHeader: 'Qty',
                qtyUnit: 'x',
                title: 'SHIFT OPEN',
                total: 'Total',
            ),
            'vi' => new self(
                amountHeader: 'So tien',
                denomHeader: 'Menh gia',
                device: 'Thiet bi',
                note: 'Ghi chu',
                openedAt: 'Gio mo ca',
                operator: 'Nguoi mo ca',
                operatorNone: '(chua dat)',
                qtyHeader: 'SL',
                qtyUnit: 'x',
                title: 'MO CA',
                total: 'Tong',
            ),
            default => new self(
                amountHeader: '金額',
                denomHeader: '金種',
                device: '端末',
                note: '備考',
                openedAt: '開始時刻',
                operator: '担当者',
                operatorNone: '未設定',
                qtyHeader: '枚数',
                qtyUnit: '枚',
                title: '開始',
                total: '合計',
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
