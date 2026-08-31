<?php

namespace App\Services\Payment\Orchestration\Enums;

enum TenderKind: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Transfer = 'transfer';
    case Qr = 'qr';
    case Voucher = 'voucher';
    case OnAccount = 'on_account';
    case Other = 'other';
}
