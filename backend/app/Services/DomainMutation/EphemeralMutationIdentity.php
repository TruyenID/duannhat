<?php

namespace App\Services\DomainMutation;

/** Process-local, non-serializable identity for a secret-bearing mutation value. */
interface EphemeralMutationIdentity
{
    public function ephemeralMutationIdentity(): string;
}
