<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d slice 0 (#1897) — bản đối ứng PHP của `taxLabels`
 * (workstation `internal/service/print_tax_i18n.go`).
 *
 * Tách khỏi {@see PrintLabels} vì bên Go tách: khối thuế theo mức là yêu cầu
 * của インボイス 適格簡易請求書 §1.3.2 và có vòng đời riêng với nhãn chung.
 *
 * Giá trị lấy từ `print_contract_golden.json` (Go sinh) và
 * `PrintContractParityTest` so lại từng chuỗi. Lý do phải so GIÁ TRỊ chứ không
 * chỉ tên trường nằm ở `rateTarget`: nó là **template sprintf** — chép thiếu
 * một `%` thì mọi khối thuế của locale đó in ra hỏng, trong khi tên trường vẫn
 * khớp hoàn hảo.
 */
final class TaxLabels
{
    /**
     * Ký tự ※ (U+203B, KOME) mã hoá được trong Shift_JIS nên in ra được trên
     * đầu nhiệt ở mọi locale — không cần gấp về ASCII như phần chữ Việt.
     */
    public const REDUCED_MARKER = '※';

    private function __construct(
        /** Tiền tố "{rate}%対象" — template sprintf, tiêu thụ MỘT chuỗi rate. */
        public readonly string $rateTarget,
        /** Nhãn số thuế trong ngoặc của một khối: "(内消費税 ¥80)". */
        public readonly string $includedTax,
        /** ※ đóng cạnh tên món chịu thuế suất giảm (8%). */
        public readonly string $reducedMarker,
        /** Chú thích chân phiếu giải nghĩa dấu ※. */
        public readonly string $reducedLegend,
        /** Nhãn ô số đăng ký T+13 — in RA CHỈ KHI có giá trị. */
        public readonly string $registrationNumber,
    ) {}

    /** Locale lạ rơi về ja, giống `taxLabelsFor` bên Go. */
    public static function forLocale(string $locale): self
    {
        return match (Definition::normalizeLocale($locale)) {
            'en' => self::en(),
            'vi' => self::vi(),
            default => self::ja(),
        };
    }

    /**
     * Tên trường theo cách Go đặt — khoá đối chiếu hợp đồng.
     *
     * @return array<string, string>
     */
    public function toGoFieldMap(): array
    {
        return [
            'IncludedTax' => $this->includedTax,
            'RateTarget' => $this->rateTarget,
            'ReducedLegend' => $this->reducedLegend,
            'ReducedMarker' => $this->reducedMarker,
            'RegistrationNumber' => $this->registrationNumber,
        ];
    }

    /**
     * Định dạng rate cho nhãn "{rate}%対象" giống hệt `rateKey` của engine: rate
     * nguyên in không phần thập phân ("8", "10"), rate lẻ giữ chữ số ("8.5").
     * Giữ khối in ra khớp với khoá gom nhóm, để 8 và 8.0 không tách hai khối.
     */
    public static function formatRatePercent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 10, '.', ''), '0'), '.') ?: '0';
    }

    private static function ja(): self
    {
        return new self(
            rateTarget: '%s%%対象',
            includedTax: '内消費税',
            reducedMarker: self::REDUCED_MARKER,
            reducedLegend: '※は軽減税率対象',
            registrationNumber: '登録番号',
        );
    }

    private static function en(): self
    {
        return new self(
            rateTarget: '%s%% taxable',
            includedTax: 'incl. tax',
            reducedMarker: self::REDUCED_MARKER,
            reducedLegend: '※ = reduced tax rate',
            registrationNumber: 'Reg. No.',
        );
    }

    /** Tiếng Việt gấp về ASCII (không dấu) — xem doc của {@see PrintLabels}. */
    private static function vi(): self
    {
        return new self(
            rateTarget: 'Chiu thue %s%%',
            includedTax: 'thue trong',
            reducedMarker: self::REDUCED_MARKER,
            reducedLegend: '※ = chiu thue giam',
            registrationNumber: 'So dang ky',
        );
    }
}
