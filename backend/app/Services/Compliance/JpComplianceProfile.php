<?php

declare(strict_types=1);

namespace App\Services\Compliance;

/**
 * Japan — 適格請求書等保存方式 (インボイス制度).
 *
 * Byte-identical to the pre-#1153 hardcodes it replaced: the T+13 regex
 * lived in HqBrandSettingsController / ShopBranchSettingsController and the
 * ¥10,000 exemption in PosReturnInvoiceService::JPY_EXEMPTION_THRESHOLD.
 */
final class JpComplianceProfile implements ComplianceProfile
{
    /** 消令70条の9③二 — tax-included exemption threshold for 適格返還請求書. */
    private const JPY_EXEMPTION_THRESHOLD = 10000.0;

    public function country(): string
    {
        return 'JP';
    }

    public function registrationNumberPattern(): string
    {
        // 適格請求書発行事業者登録番号 — "T" + 13-digit 法人番号.
        return '/^T\d{13}$/';
    }

    public function registrationNumberError(): string
    {
        return '登録番号 must be "T" followed by exactly 13 digits.';
    }

    public function returnInvoiceExemptionThreshold(string $currency): ?float
    {
        // The 消費税法 exemption is a yen figure; a JP org billing another
        // currency (not a real configuration today) gets no exemption.
        return $currency === 'JPY' ? self::JPY_EXEMPTION_THRESHOLD : null;
    }

    public function requiresEInvoiceTransmission(): bool
    {
        return false;
    }
}
