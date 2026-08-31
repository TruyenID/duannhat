<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\VerifiedObjectRegistry;

final readonly class PersistOnlineOrderCommand extends MutationCommand
{
    private function __construct(public PersistResolvedOrderCommand $resolved)
    {
        parent::__construct($resolved->context);
        $resolved->assertTrusted();
        if ($resolved->snapshot->offlineReplay) {
            throw new \InvalidArgumentException('Offline replay cannot use the online order persistence route.');
        }
    }

    public static function fromResolved(PersistResolvedOrderCommand $resolved): self
    {
        $command = new self($resolved);
        VerifiedObjectRegistry::derive($command, $resolved, 'order.persist_resolved', 'order.persist_online');

        return $command;
    }

    public function assertTrusted(): void
    {
        VerifiedObjectRegistry::assertSealed($this, 'order.persist_online');
    }
}
