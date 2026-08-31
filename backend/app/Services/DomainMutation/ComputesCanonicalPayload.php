<?php

namespace App\Services\DomainMutation;

trait ComputesCanonicalPayload
{
    final public function canonicalJson(): string
    {
        return json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    final public function fingerprint(): string
    {
        return hash('sha256', $this->canonicalJson());
    }
}
