<?php

namespace App\Services\Customer\ValueObjects;

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
use App\Services\Customer\Contracts\CustomerAuthorityVerificationPort;
use App\Services\Customer\Enums\CustomerAuthorityOperation;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\VerificationAuthority;
use App\Services\DomainMutation\VerifiedObjectRegistry;

/** Opaque persistence input issued only after customer authority verification. */
final readonly class VerifiedCustomerMutation
{
    private function __construct(
        public MutationCommand $command,
        public CustomerAuthorityOperation $operation,
        public ?CustomerMergePlan $mergePlan,
    ) {}

    public static function issue(CustomerAuthorityVerificationPort $verifier, VerificationAuthority $authority, MutationCommand $command, CustomerAuthorityOperation $operation, ?CustomerMergePlan $mergePlan = null): self
    {
        $expectedClasses = [
            CustomerAuthorityOperation::Archive->value => CustomerLifecycleCommand::class,
            CustomerAuthorityOperation::Restore->value => CustomerLifecycleCommand::class,
            CustomerAuthorityOperation::IssueToken->value => IssueCustomerAccessTokenCommand::class,
            CustomerAuthorityOperation::RevokeToken->value => RevokeCustomerAccessTokenCommand::class,
            CustomerAuthorityOperation::GlobalProfile->value => ReviseGlobalCustomerProfileCommand::class,
            CustomerAuthorityOperation::TenantProfile->value => ReviseTenantCustomerProfileCommand::class,
            CustomerAuthorityOperation::VerifyEmail->value => VerifyCustomerEmailCommand::class,
            CustomerAuthorityOperation::LinkScope->value => LinkCustomerScopeCommand::class,
            CustomerAuthorityOperation::UnlinkScope->value => UnlinkCustomerScopeCommand::class,
            CustomerAuthorityOperation::Merge->value => MergeCustomersCommand::class,
            CustomerAuthorityOperation::ChangeCredentials->value => ChangeCustomerCredentialsCommand::class,
        ];
        if ($command::class !== $expectedClasses[$operation->value]) {
            throw new \InvalidArgumentException($operation->value.' authority cannot wrap '.$command::class.'.');
        }
        if ($command instanceof CustomerLifecycleCommand && $command->action->value !== $operation->value) {
            throw new \InvalidArgumentException('Customer lifecycle action does not match verified authority operation.');
        }
        if (($operation === CustomerAuthorityOperation::Merge) !== ($mergePlan !== null)) {
            throw new \InvalidArgumentException('Only verified merge mutations may carry a merge plan.');
        }
        if ($command instanceof MergeCustomersCommand
            && ($mergePlan->sourceCustomerId !== $command->sourceCustomerId || $mergePlan->targetCustomerId !== $command->targetCustomerId)) {
            throw new \InvalidArgumentException('Verified merge plan identities must match the merge command.');
        }
        $value = new self($command, $operation, $mergePlan);
        VerifiedObjectRegistry::seal($value, $verifier, $authority, 'customer.verified_mutation', CustomerAuthorityVerificationPort::class);

        return $value;
    }

    public function assertTrusted(CustomerAuthorityOperation $expected): void
    {
        VerifiedObjectRegistry::assertSealed($this, 'customer.verified_mutation');
        if ($this->operation !== $expected) {
            throw new \LogicException("Expected verified {$expected->value} customer mutation.");
        }
    }
}
