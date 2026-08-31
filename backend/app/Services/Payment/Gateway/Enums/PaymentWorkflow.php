<?php

namespace App\Services\Payment\Gateway\Enums;

enum PaymentWorkflow: string
{
    case Sale = 'sale';
    case AuthorizeCapture = 'authorize_capture';
}
