<?php

namespace App\Services\Payment\Orchestration\Enums;

enum PaymentObligation: string
{
    case Immediate = 'immediate';
    case Split = 'split';
    case Debt = 'debt';
    case DebtSettlement = 'debt_settlement';
}
