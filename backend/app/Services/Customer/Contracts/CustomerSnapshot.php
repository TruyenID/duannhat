<?php

namespace App\Services\Customer\Contracts;

interface CustomerSnapshot
{
    public function aggregateId(): string;

    public function organizationId(): ?string;

    public function branchId(): ?string;

    public function status(): string;
}
