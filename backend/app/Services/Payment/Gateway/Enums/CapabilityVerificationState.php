<?php

namespace App\Services\Payment\Gateway\Enums;

enum CapabilityVerificationState: string
{
    case Verified = 'verified';
    case ContractRequired = 'contract_required';
    case CertificationRequired = 'certification_required';
    case Unknown = 'unknown';
}
