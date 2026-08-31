<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Services\Platform\IdentityEventConsumer;
use App\Services\Platform\IdentitySourceManager;
use Illuminate\Console\Command;

/**
 * Drains the identity feed into Tempo's mirror (#3199, ADR 0002).
 */
final class ConsumeIdentityCommand extends Command
{
    protected $signature = 'platform:consume-identity
        {--source= : Override the configured driver for this run}
        {--batch= : Messages to attempt (default config identity.consumer.batch)}';

    protected $description = "Consume identity events from the configured source into Tempo's mirror";

    public function handle(IdentitySourceManager $sources, IdentityEventConsumer $consumer): int
    {
        $source = $sources->source($this->option('source'));

        $result = $consumer->run(
            $source,
            (int) ($this->option('batch') ?: config('identity.consumer.batch', 50)),
        );

        // Always name the source: with a `null` driver in the config, "received 0"
        // means two very different things and only this line tells them apart.
        $this->line('source: '.$result['source']);

        if ($result['blocked']) {
            $this->error(implode(' ', [
                'Source is not configured — NOTHING was consumed and no message was acknowledged.',
                'Set IDENTITY_SQS_QUEUE_URL (see dxs-platform/platform#813), or choose another driver',
                'with IDENTITY_SOURCE. Failing here is deliberate: an acknowledged SQS message is gone,',
                'so the alternative is losing events to a blank env var.',
            ]));

            return self::FAILURE;
        }

        $this->line(sprintf(
            'received=%d  applied=%d  duplicate=%d  stale=%d  skipped=%d  failed=%d',
            $result['received'], $result['applied'], $result['duplicate'],
            $result['stale'], $result['skipped'], $result['failed'],
        ));

        // `duplicate` and `stale` are NORMAL — at-least-once delivery and
        // unordered transport respectively — so they are reported but never fail
        // the run. `failed` means messages stayed on the queue for redelivery,
        // which is worth a non-zero exit.
        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
