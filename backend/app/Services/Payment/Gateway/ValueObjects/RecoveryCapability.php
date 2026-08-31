<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;

final readonly class RecoveryCapability implements JsonSerializable
{
    public ?string $reconciliationArtifact;

    public function __construct(
        public bool $pollPayment,
        public bool $pollRefund,
        public bool $webhookEvents,
        ?string $reconciliationArtifact = null,
    ) {
        $reconciliationArtifact = $reconciliationArtifact === null ? null : trim($reconciliationArtifact);
        if ($reconciliationArtifact !== null && ($reconciliationArtifact === '' || mb_strlen($reconciliationArtifact) > 100)) {
            throw new InvalidArgumentException('Reconciliation artifact is invalid.');
        }

        $this->reconciliationArtifact = $reconciliationArtifact;
    }

    /** @return array<string, bool|string|null> */
    public function jsonSerialize(): array
    {
        return [
            'poll_payment' => $this->pollPayment,
            'poll_refund' => $this->pollRefund,
            'webhook_events' => $this->webhookEvents,
            'reconciliation_artifact' => $this->reconciliationArtifact,
        ];
    }
}
