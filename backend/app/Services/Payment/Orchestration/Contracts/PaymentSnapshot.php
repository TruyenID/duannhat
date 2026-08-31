<?php

namespace App\Services\Payment\Orchestration\Contracts;

use App\Services\DomainMutation\AggregateSnapshot;

interface PaymentSnapshot extends AggregateSnapshot
{
    public function orderId(): string;

    public function status(): string;
}
