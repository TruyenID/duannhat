<?php

namespace App\Services\Customer\Commands;

use App\Services\Customer\ValueObjects\CustomerCredentialPayload;
use App\Services\Customer\ValueObjects\GlobalCustomerProfilePayload;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class RegisterCustomerAccountCommand extends MutationCommand
{
    public string $customerId;

    public string $profileFingerprint;

    public string $tokenName;

    public function __construct(MutationContext $context, string $customerId, public GlobalCustomerProfilePayload $profile, string $profileFingerprint, public CustomerCredentialPayload $password, string $tokenName)
    {
        parent::__construct($context);
        if ($context->organizationId !== null) {
            throw new \InvalidArgumentException('Global account registration cannot carry tenant scope.');
        }
        $this->customerId = self::uuid($customerId, 'customerId');
        $this->profileFingerprint = self::verifiedFingerprint($profileFingerprint, 'profileFingerprint', $profile);
        $this->tokenName = self::safeToken($tokenName, 'tokenName', 100);
    }
}
