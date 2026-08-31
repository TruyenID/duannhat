<?php

declare(strict_types=1);

namespace App\Services\Platform\Source;

use App\Services\Platform\Contracts\IdentityEventSource;
use Aws\Sqs\SqsClient;

final class SqsEventSource implements IdentityEventSource
{
    public function __construct(
        private readonly SqsClient $client,
        private readonly string $queueUrl,
        private readonly int $waitSeconds = 20,
    ) {}

    public function receive(int $max): array
    {
        $result = $this->client->receiveMessage([
            'QueueUrl' => $this->queueUrl,
            // SQS caps a single receive at 10 regardless of what is asked.
            'MaxNumberOfMessages' => min($max, 10),
            // Long polling. The consumer runs under cron + flock with a ~55s
            // budget, so waiting here catches a message that arrives mid-run
            // instead of returning empty and dying immediately.
            'WaitTimeSeconds' => $this->waitSeconds,
        ]);

        $messages = $result->get('Messages') ?? [];
        $received = [];

        foreach ($messages as $message) {
            $body = json_decode((string) ($message['Body'] ?? ''), true);

            if (! is_array($body)) {
                // Not JSON. Left ON the queue deliberately: acknowledging it here
                // would silently discard something we cannot explain, and SQS's
                // own redrive policy is the right place for a message that never
                // parses — it lands in the DLQ where someone can look at it.
                continue;
            }

            $received[] = ['receipt' => (string) ($message['ReceiptHandle'] ?? ''), 'envelope' => $body];
        }

        return $received;
    }

    public function acknowledge(mixed $receipt): void
    {
        $this->client->deleteMessage([
            'QueueUrl' => $this->queueUrl,
            'ReceiptHandle' => (string) $receipt,
        ]);
    }

    public function isReady(): bool
    {
        return $this->queueUrl !== '';
    }

    public function describe(): string
    {
        return $this->queueUrl === '' ? 'sqs (queue URL NOT configured)' : 'sqs → '.$this->queueUrl;
    }
}
