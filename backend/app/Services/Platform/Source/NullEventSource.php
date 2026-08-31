<?php

declare(strict_types=1);

namespace App\Services\Platform\Source;

use App\Services\Platform\Contracts\IdentityEventSource;

/**
 * Consumes nothing. A deliberate pause, chosen — never fallen back to.
 */
final class NullEventSource implements IdentityEventSource
{
    public function receive(int $max): array
    {
        return [];
    }

    public function acknowledge(mixed $receipt): void {}

    public function isReady(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'null (consuming nothing — the feed is off at this end)';
    }
}
