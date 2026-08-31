<?php

namespace App\Services\Customer\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;

final readonly class TenantCustomerProfilePatch implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public function __construct(public OptionalProfileField $givenName, public OptionalProfileField $familyName, public OptionalProfileField $email, public OptionalProfileField $phone, public OptionalProfileField $address, public OptionalProfileField $taxCode, public OptionalProfileField $note)
    {
        $provided = false;
        foreach (get_object_vars($this) as $field) {
            $provided = $provided || $field->provided;
        }
        if (! $provided) {
            throw new \InvalidArgumentException('Tenant profile PATCH must provide a field.');
        }
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
