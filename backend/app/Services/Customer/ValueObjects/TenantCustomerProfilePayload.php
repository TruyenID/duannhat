<?php

namespace App\Services\Customer\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class TenantCustomerProfilePayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $givenName;

    public ?string $familyName;

    public ?string $email;

    public ?string $phone;

    public function __construct(string $givenName, ?string $familyName, ?string $email, ?string $phone, public ?string $address = null, public ?string $taxCode = null, public ?string $note = null)
    {
        $this->givenName = MutationCommand::safeToken($givenName, 'givenName', 100);
        $this->familyName = $familyName === null ? null : MutationCommand::safeToken($familyName, 'familyName', 100);
        $this->email = $email === null ? null : mb_strtolower(trim($email));
        $this->phone = $phone === null ? null : MutationCommand::safeToken($phone, 'phone', 32);
        if ($this->email !== null && filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('email must be valid.');
        }
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
