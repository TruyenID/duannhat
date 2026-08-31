<?php

namespace App\Services\Customer\Commands;

use App\Services\Customer\ValueObjects\CustomerCredentialPayload;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class LoginCustomerCommand extends MutationCommand
{
    public string $email;

    public string $tokenName;

    public function __construct(MutationContext $context, string $email, public CustomerCredentialPayload $password, string $tokenName)
    {
        parent::__construct($context);
        if ($context->organizationId !== null) {
            throw new \InvalidArgumentException('Customer account login is global.');
        }
        $this->email = mb_strtolower(trim($email));
        $this->tokenName = self::safeToken($tokenName, 'tokenName', 100);
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('email must be valid.');
        }
    }
}
