<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Services\Platform\Contracts\IdentityEventSource;
use App\Services\Platform\Source\NullEventSource;
use App\Services\Platform\Source\SqsEventSource;
use Aws\Sqs\SqsClient;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Driver manager for the identity feed's receiving end (#3199).
 *
 * Built on `Illuminate\Support\Manager` for the same reasons as the producer
 * side: config-driven resolution, lazy per-driver construction, and `extend()`
 * so a Kafka or RabbitMQ source can arrive from elsewhere without editing this
 * class.
 */
final class IdentitySourceManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('identity.source', 'sqs');
    }

    public function source(?string $name = null): IdentityEventSource
    {
        $source = $this->driver($name);

        if (! $source instanceof IdentityEventSource) {
            throw new InvalidArgumentException(sprintf(
                'Identity source [%s] must implement %s.',
                $name ?? $this->getDefaultDriver(),
                IdentityEventSource::class,
            ));
        }

        return $source;
    }

    protected function createSqsDriver(): IdentityEventSource
    {
        $config = $this->configFor('sqs');

        return new SqsEventSource(
            new SqsClient([
                'region' => (string) ($config['region'] ?? 'ap-northeast-1'),
                'version' => 'latest',
            ]),
            (string) ($config['queue_url'] ?? ''),
            (int) ($config['wait_seconds'] ?? 20),
        );
    }

    protected function createNullDriver(): IdentityEventSource
    {
        return new NullEventSource;
    }

    /** @return array<string, mixed> */
    private function configFor(string $name): array
    {
        $config = $this->config->get("identity.sources.{$name}");

        if (! is_array($config)) {
            // A typo must not resolve to something that quietly works.
            throw new InvalidArgumentException("Identity source [{$name}] has no configuration block.");
        }

        return $config;
    }
}
