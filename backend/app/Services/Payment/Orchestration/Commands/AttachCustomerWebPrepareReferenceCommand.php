<?php

namespace App\Services\Payment\Orchestration\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/**
 * Stamp the provider object id and customer-web channel onto an attempt that a
 * Stripe prepare just created.
 *
 * Carried as a command rather than loose scalars so the persistence port keeps
 * the one-typed-command-in / one-final-result-out shape every other canonical
 * mutation follows (`DomainMutationContractsTest`).
 */
final readonly class AttachCustomerWebPrepareReferenceCommand extends MutationCommand
{
    public string $attemptId;

    public string $providerObjectId;

    public function __construct(MutationContext $context, string $attemptId, string $providerObjectId)
    {
        parent::__construct($context);
        $this->attemptId = self::uuid($attemptId, 'attemptId');
        $this->providerObjectId = self::safeToken($providerObjectId, 'providerObjectId', 255);
    }
}
