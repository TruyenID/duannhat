<?php

namespace App\Services\Customer\Contracts;

use App\Services\Customer\Commands\ChangeCustomerCredentialsCommand;
use App\Services\Customer\Commands\CustomerLifecycleCommand;
use App\Services\Customer\Commands\IssueCustomerAccessTokenCommand;
use App\Services\Customer\Commands\LinkCustomerScopeCommand;
use App\Services\Customer\Commands\MergeCustomersCommand;
use App\Services\Customer\Commands\ReviseGlobalCustomerProfileCommand;
use App\Services\Customer\Commands\ReviseTenantCustomerProfileCommand;
use App\Services\Customer\Commands\RevokeCustomerAccessTokenCommand;
use App\Services\Customer\Commands\UnlinkCustomerScopeCommand;
use App\Services\Customer\Commands\VerifyCustomerEmailCommand;
use App\Services\Customer\ValueObjects\VerifiedCustomerMutation;

interface CustomerAuthorityVerificationPort
{
    public function verifyLifecycleAuthority(CustomerLifecycleCommand $command): VerifiedCustomerMutation;

    public function verifyTokenIssueAuthority(IssueCustomerAccessTokenCommand $command): VerifiedCustomerMutation;

    public function verifyTokenRevokeAuthority(RevokeCustomerAccessTokenCommand $command): VerifiedCustomerMutation;

    public function verifyGlobalProfileAuthority(ReviseGlobalCustomerProfileCommand $command): VerifiedCustomerMutation;

    public function verifyTenantProfileAuthority(ReviseTenantCustomerProfileCommand $command): VerifiedCustomerMutation;

    public function verifyEmailAuthority(VerifyCustomerEmailCommand $command): VerifiedCustomerMutation;

    public function verifyLinkAuthority(LinkCustomerScopeCommand $command): VerifiedCustomerMutation;

    public function verifyUnlinkAuthority(UnlinkCustomerScopeCommand $command): VerifiedCustomerMutation;

    public function verifyMergeAuthority(MergeCustomersCommand $command): VerifiedCustomerMutation;

    public function verifyCredentialAuthority(ChangeCustomerCredentialsCommand $command): VerifiedCustomerMutation;
}
