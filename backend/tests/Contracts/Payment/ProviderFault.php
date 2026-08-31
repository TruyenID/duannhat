<?php

namespace Tests\Contracts\Payment;

enum ProviderFault: string
{
    case Decline = 'decline';
    case Authentication = 'authentication';
    case Timeout = 'timeout';
}
