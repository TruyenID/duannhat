<?php

namespace App\Services\Payment\Orchestration\Results;

use App\Services\DomainMutation\MutationCommand;

final readonly class ProviderEventResult
{
    public string $providerEventId;

    public ?string $attemptId;

    public function __construct(string $providerEventId, public bool $deduplicated, ?string $attemptId)
    {
        $this->providerEventId = MutationCommand::safeToken($providerEventId, 'providerEventId', 255);
        $this->attemptId = MutationCommand::nullableUuid($attemptId, 'attemptId');
    }
}
