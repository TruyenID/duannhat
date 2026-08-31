<?php

namespace App\Services\Customer\Contracts;

use App\Services\Customer\Commands\LoginCustomerCommand;
use App\Services\Customer\Results\AuthenticatedCustomerEvidence;

interface CustomerAuthenticationPort
{
    /** Validates the global account credential without mutating Customer or token storage. */
    public function authenticate(LoginCustomerCommand $command): AuthenticatedCustomerEvidence;
}
