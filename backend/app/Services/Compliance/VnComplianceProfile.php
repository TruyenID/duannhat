<?php

declare(strict_types=1);

namespace App\Services\Compliance;

/**
 * Vietnam — hoá đơn điện tử (NĐ 123/2020, NĐ 70/2025).
 *
 * The seller registration number is the mã số thuế (MST): 10 digits for the
 * legal entity, optionally "-NNN" for a dependent unit (chi nhánh). No
 * small-amount exemption exists for credit notes — a return document is
 * always owed. The CQT transmission adapter itself is future scope (#1153
 * remainder); this profile only parametrizes the shared document machinery.
 */
final class VnComplianceProfile implements ComplianceProfile
{
    public function country(): string
    {
        return 'VN';
    }

    public function registrationNumberPattern(): string
    {
        return '/^\d{10}(-\d{3})?$/';
    }

    public function registrationNumberError(): string
    {
        return 'Mã số thuế must be 10 digits, optionally followed by "-" and 3 digits.';
    }

    public function returnInvoiceExemptionThreshold(string $currency): ?float
    {
        return null;
    }

    public function requiresEInvoiceTransmission(): bool
    {
        return true;
    }
}
