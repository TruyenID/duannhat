<?php

namespace App\Services\Payment\Policy\Enums;

enum ConnectionCapabilityVerification: string
{
    case Unknown = 'unknown';
    case ContractRequired = 'contract_required';
    case CertificationRequired = 'certification_required';
    case Verified = 'verified';
    case Restricted = 'restricted';
}
