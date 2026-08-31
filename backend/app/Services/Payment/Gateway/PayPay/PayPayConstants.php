<?php

namespace App\Services\Payment\Gateway\PayPay;

final class PayPayConstants
{
    /** Deprecated PayPay hostname — webhook bodies referencing it are rejected. */
    public const DEPRECATED_HOST = 'api.paypay.ne.jp';
}
