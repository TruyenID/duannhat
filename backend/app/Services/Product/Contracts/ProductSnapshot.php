<?php

namespace App\Services\Product\Contracts;

use App\Services\DomainMutation\AggregateSnapshot;

interface ProductSnapshot extends AggregateSnapshot
{
    public function brandId(): string;

    public function status(): string;
}
