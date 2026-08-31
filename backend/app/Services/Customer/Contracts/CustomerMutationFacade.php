<?php

namespace App\Services\Customer\Contracts;

use App\Services\Customer\Commands\ChangeCustomerCredentialsCommand;
use App\Services\Customer\Commands\CustomerLifecycleCommand;
use App\Services\Customer\Commands\FindOrCreateCustomerCommand;
use App\Services\Customer\Commands\IssueCustomerAccessTokenCommand;
use App\Services\Customer\Commands\LinkCustomerScopeCommand;
use App\Services\Customer\Commands\LoginCustomerCommand;
use App\Services\Customer\Commands\MergeCustomersCommand;
use App\Services\Customer\Commands\RegisterCustomerAccountCommand;
use App\Services\Customer\Commands\RegisterCustomerCommand;
use App\Services\Customer\Commands\ReviseGlobalCustomerProfileCommand;
use App\Services\Customer\Commands\ReviseTenantCustomerProfileCommand;
use App\Services\Customer\Commands\RevokeCustomerAccessTokenCommand;
use App\Services\Customer\Commands\UnlinkCustomerScopeCommand;
use App\Services\Customer\Commands\VerifyCustomerEmailCommand;
use App\Services\Customer\Results\CustomerAuthenticationResult;
use App\Services\Customer\Results\CustomerMergeResult;
use App\Services\Customer\Results\CustomerMutationResult;
use App\Services\Customer\Results\CustomerResolvedResult;

interface CustomerMutationFacade
{
    public function register(RegisterCustomerCommand $command): CustomerMutationResult;

    public function registerAccount(RegisterCustomerAccountCommand $command): CustomerAuthenticationResult;

    public function login(LoginCustomerCommand $command): CustomerAuthenticationResult;

    public function issueAccessToken(IssueCustomerAccessTokenCommand $command): CustomerAuthenticationResult;

    public function revokeAccessToken(RevokeCustomerAccessTokenCommand $command): CustomerMutationResult;

    public function reviseGlobalProfile(ReviseGlobalCustomerProfileCommand $command): CustomerMutationResult;

    public function reviseTenantProfile(ReviseTenantCustomerProfileCommand $command): CustomerMutationResult;

    public function verifyEmail(VerifyCustomerEmailCommand $command): CustomerMutationResult;

    public function linkScope(LinkCustomerScopeCommand $command): CustomerMutationResult;

    public function unlinkScope(UnlinkCustomerScopeCommand $command): CustomerMutationResult;

    public function findOrCreate(FindOrCreateCustomerCommand $command): CustomerResolvedResult;

    public function changeCredentials(ChangeCustomerCredentialsCommand $command): CustomerMutationResult;

    public function merge(MergeCustomersCommand $command): CustomerMergeResult;

    public function archive(CustomerLifecycleCommand $command): CustomerMutationResult;

    public function restore(CustomerLifecycleCommand $command): CustomerMutationResult;
}
