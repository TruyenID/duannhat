<?php

namespace App\Services\Menu\Contracts;

use App\Services\DomainMutation\AggregateSnapshot;

interface MenuSnapshot extends AggregateSnapshot
{
    public function brandId(): string;

    public function branchId(): ?string;

    public function status(): string;
}
