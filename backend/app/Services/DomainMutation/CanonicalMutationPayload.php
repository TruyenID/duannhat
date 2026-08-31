<?php

namespace App\Services\DomainMutation;

use JsonSerializable;

interface CanonicalMutationPayload extends JsonSerializable
{
    public function canonicalJson(): string;

    public function fingerprint(): string;
}
