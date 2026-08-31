<?php

namespace App\Services\Payment\ProviderEvent;

use App\Models\PaymentProviderEvent;
use App\Services\DomainMutation\MutationContext;
use App\Services\Payment\Orchestration\Commands\ApplyInboxProviderEventCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentMutationFacade;

/** Delegates inbox processing to the payment orchestrator entry point. */
final class ProviderEventProcessor
{
    public function __construct(
        private readonly PaymentMutationFacade $payments,
    ) {}

    public function process(PaymentProviderEvent $event): string
    {
        // The provider's own event id is the natural idempotency key: the inbox
        // already dedupes on it, so a redelivery rebuilds the same command
        // identity rather than a second mutation.
        $context = new MutationContext(
            (string) $event->organization_id,
            null,
            'provider-event:'.$event->id,
            (string) $event->provider_event_id,
        );

        return $this->payments->applyInboxEvent(
            new ApplyInboxProviderEventCommand($context, (string) $event->id),
        )->outcome;
    }
}
