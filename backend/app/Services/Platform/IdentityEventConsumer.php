<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Models\IdentityInboxEntry;
use App\Services\Platform\Contracts\IdentityEventSource;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Receives identity events, records them once, applies them in order (#3199).
 */
final class IdentityEventConsumer
{
    public function __construct(private readonly IdentityEventApplier $applier) {}

    /**
     * @return array{source: string, received: int, applied: int, duplicate: int, stale: int, skipped: int, failed: int, blocked: bool}
     */
    public function run(IdentityEventSource $source, int $batch): array
    {
        $result = [
            'source' => $source->describe(), 'received' => 0, 'applied' => 0,
            'duplicate' => 0, 'stale' => 0, 'skipped' => 0, 'failed' => 0, 'blocked' => false,
        ];

        if (! $source->isReady()) {
            // Refuse the run. Anything else risks acknowledging messages that were
            // never applied, and an acknowledged SQS message is gone for good.
            $result['blocked'] = true;

            return $result;
        }

        foreach ($source->receive($batch) as $message) {
            $result['received']++;

            try {
                $outcome = $this->handle($message['envelope']);
                $result[$outcome]++;

                // Acknowledged ONLY after the event is safely recorded (and, when
                // applicable, applied). Acking first would turn any crash in
                // between into a permanently lost event: SQS has already dropped
                // it and no retry exists. Acking after means the worst case is
                // re-delivery, which `duplicate` handles for free.
                $source->acknowledge($message['receipt']);
            } catch (Throwable $exception) {
                // NOT acknowledged: leave it on the queue so SQS redelivers, and
                // its own redrive policy moves it to the DLQ after enough tries.
                // Swallowing here would be the silent-loss failure this whole
                // design exists to prevent.
                $result['failed']++;

                report($exception);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @return 'applied'|'duplicate'|'stale'|'skipped'
     */
    private function handle(array $envelope): string
    {
        $eventId = (string) ($envelope['id'] ?? '');
        $subject = (string) ($envelope['subject'] ?? '');
        [$resourceType, $resourceId] = array_pad(explode('/', $subject, 2), 2, '');
        $type = (string) ($envelope['type'] ?? '');
        $action = str_contains($type, '.') ? (string) last(explode('.', $type)) : '';
        $sequence = (int) ($envelope['sequence'] ?? 0);
        $payload = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

        if ($eventId === '' || $resourceType === '' || $resourceId === '') {
            // Malformed. Recorded as skipped and acknowledged: leaving it on the
            // queue would retry it forever, and it can never become valid.
            return 'skipped';
        }

        return DB::transaction(function () use ($eventId, $resourceType, $resourceId, $type, $action, $sequence, $payload): string {
            try {
                $entry = IdentityInboxEntry::query()->create([
                    'event_id' => $eventId,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                    'event_type' => $type,
                    'sequence' => $sequence,
                    'payload' => $payload,
                    'received_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Already seen. At-least-once delivery makes this routine, so it
                // is a counted outcome rather than an error.
                return 'duplicate';
            }

            // Ordering is NOT preserved by SNS/SQS. An event behind what has
            // already been applied for this resource must be DROPPED, not
            // applied: writing it would roll the mirror back to a past state and
            // nothing would report that.
            $latestApplied = IdentityInboxEntry::query()
                ->where('resource_type', $resourceType)
                ->where('resource_id', $resourceId)
                ->whereNotNull('applied_at')
                ->max('sequence');

            if ($latestApplied !== null && $sequence <= (int) $latestApplied) {
                return 'stale';
            }

            $outcome = $this->applier->apply($resourceType, $action, $resourceId, $payload);

            $entry->forceFill(['applied_at' => now()])->save();

            return $outcome === 'applied' ? 'applied' : 'skipped';
        });
    }
}
