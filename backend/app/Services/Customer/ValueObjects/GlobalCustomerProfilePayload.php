<?php

namespace App\Services\Customer\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\SupportedLocale;

final readonly class GlobalCustomerProfilePayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $givenName;

    public ?string $familyName;

    public string $email;

    public ?string $phone;

    public function __construct(string $givenName, ?string $familyName, string $email, ?string $phone, public SupportedLocale $locale, public ?string $address = null)
    {
        $this->givenName = MutationCommand::safeToken($givenName, 'givenName', 100);
        $this->familyName = $familyName === null ? null : MutationCommand::safeToken($familyName, 'familyName', 100);
        $this->email = mb_strtolower(trim($email));
        $this->phone = $phone === null ? null : MutationCommand::safeToken($phone, 'phone', 32);
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('email must be valid.');
        }
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
