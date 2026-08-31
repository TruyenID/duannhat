<?php

namespace App\Services\Customer\Contracts;

interface CustomerQueryPort
{
    public function findGlobalAccountById(string $customerId): ?CustomerSnapshot;

    public function findGlobalAccountByEmail(string $email): ?CustomerSnapshot;

    public function findTenantCustomerById(string $organizationId, string $branchId, string $customerId): ?CustomerSnapshot;

    public function findForOrderSnapshot(string $organizationId, string $customerId): ?CustomerSnapshot;
}
