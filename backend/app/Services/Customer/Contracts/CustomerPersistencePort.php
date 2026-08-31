<?php

namespace App\Services\Customer\Contracts;

use App\Services\Customer\Commands\FindOrCreateCustomerCommand;
use App\Services\Customer\Commands\RegisterCustomerAccountCommand;
use App\Services\Customer\Commands\RegisterCustomerCommand;
use App\Services\Customer\Results\CustomerAuthenticationResult;
use App\Services\Customer\Results\CustomerMergeResult;
use App\Services\Customer\Results\CustomerMutationResult;
use App\Services\Customer\Results\CustomerResolvedResult;
use App\Services\Customer\ValueObjects\VerifiedCustomerMutation;

interface CustomerPersistencePort
{
    public function insertCustomer(RegisterCustomerCommand $command): CustomerMutationResult;

    public function insertAccountAndIssueToken(RegisterCustomerAccountCommand $command): CustomerAuthenticationResult;

    public function issueAccessToken(VerifiedCustomerMutation $command): CustomerAuthenticationResult;

    public function revokeAccessToken(VerifiedCustomerMutation $command): CustomerMutationResult;

    public function applyGlobalProfileRevision(VerifiedCustomerMutation $command): CustomerMutationResult;

    public function applyTenantProfileRevision(VerifiedCustomerMutation $command): CustomerMutationResult;

    public function recordEmailVerification(VerifiedCustomerMutation $command): CustomerMutationResult;

    public function linkScope(VerifiedCustomerMutation $command): CustomerMutationResult;

    public function unlinkScope(VerifiedCustomerMutation $command): CustomerMutationResult;

    public function findOrInsertCustomer(FindOrCreateCustomerCommand $command): CustomerResolvedResult;

    public function replaceCredentials(VerifiedCustomerMutation $command): CustomerMutationResult;

    public function mergeCustomers(VerifiedCustomerMutation $command): CustomerMergeResult;

    public function markArchived(VerifiedCustomerMutation $command): CustomerMutationResult;

    public function markRestored(VerifiedCustomerMutation $command): CustomerMutationResult;
}
