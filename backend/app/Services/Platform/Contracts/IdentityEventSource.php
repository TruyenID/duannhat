<?php

declare(strict_types=1);

namespace App\Services\Platform\Contracts;

/**
 * Where identity events arrive from (#3199, ADR 0002).
 *
 * Mirrors the transport seam on the producer side (dxs-platform/platform#814):
 * moving off SQS is a config value plus one driver, never a change to the inbox,
 * the dedupe, the ordering check or the apply step.
 */
interface IdentityEventSource
{
    /**
     * Fetch up to `$max` messages. Each is `['receipt' => mixed, 'envelope' => array]`.
     *
     * Nothing is removed from the source here — see `acknowledge()`.
     *
     * @return list<array{receipt: mixed, envelope: array<string, mixed>}>
     */
    public function receive(int $max): array;

    /**
     * Remove a message from the source, permanently.
     *
     * Called ONLY after the event is safely recorded. Acknowledging first would
     * turn any crash in between into a permanently lost event — the source has
     * already forgotten it and no retry exists.
     */
    public function acknowledge(mixed $receipt): void;

    /** Whether this driver has what it needs; see the fail-closed rule. */
    public function isReady(): bool;

    /** One line naming driver and target, for the run report. */
    public function describe(): string;
}
