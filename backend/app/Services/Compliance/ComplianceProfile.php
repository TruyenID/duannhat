<?php

declare(strict_types=1);

namespace App\Services\Compliance;

/**
 * #1153 — per-country compliance parameters.
 *
 * The core stays international: ONE invoice/credit-note model, ONE tax
 * engine, ONE document pipeline. Never fork a VnInvoice/JpInvoice — a
 * profile only PARAMETRIZES the shared machinery (registration-number
 * format, document thresholds, labels). Resolution rides the organization's
 * operating country, mirrored from the Platform by UserProvisioner
 * (organizations.operating_country, default JP).
 */
interface ComplianceProfile
{
    /** ISO 3166-1 alpha-2 country this profile implements. */
    public function country(): string;

    /**
     * Regex for the seller tax-registration number entered in brand/branch
     * settings (JP: インボイス T+13 · VN: mã số thuế 10 or 10-3 digits).
     */
    public function registrationNumberPattern(): string;

    /** Human validation message for a registration number that fails the pattern. */
    public function registrationNumberError(): string;

    /**
     * Tax-included total UNDER which no return document (返還インボイス /
     * credit note) is legally owed for the given currency — null when the
     * jurisdiction has no such exemption. JP: 消令70条の9③二 exempts
     * returns under ¥10,000; VN has no equivalent (documents always owed).
     */
    public function returnInvoiceExemptionThreshold(string $currency): ?float;

    /**
     * Whether issued tax documents must be transmitted to the tax authority
     * as e-invoices (VN: hoá đơn điện tử ký số + mã CQT qua provider,
     * NĐ 123/2020 / NĐ 70/2025). JP has no per-document transmission duty
     * today. This is the PROFILE gate only, and — kể từ #1779 — nó KHÔNG còn
     * cổng nào phía sau: toàn bộ đường truyền CQT (job, service, lệnh redrive,
     * cờ compliance.vn_einvoice.enabled, bảng VnEinvoiceSetting) đã bị gỡ.
     *
     * Nghĩa là hàm này hiện chỉ MÔ TẢ nghĩa vụ pháp lý theo quốc gia, không
     * kích hoạt hành vi nào. Ai dựng lại đường truyền phải nối lại cổng thứ
     * hai — đừng cho rằng `true` ở đây là đã truyền. Theo dõi ở #1153.
     */
    public function requiresEInvoiceTransmission(): bool;
}
