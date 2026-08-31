<?php

namespace App\Services\DomainMutation;

interface AggregateSnapshot
{
    public function aggregateId(): string;

    public function organizationId(): string;

    public function version(): int;
}
