<?php

namespace App\Services\Notification;

use App\Contracts\Notification\MailWebhookContract;
use App\Services\Notification\Webhooks\PostmarkMailWebhookHandler;

/**
 * Plan-023 M4 T4.4 — provider driver registry for MailWebhookContract.
 *
 * Postmark ships pre-registered as the default. To add SES / Mailgun /
 * SendGrid later: implement MailWebhookContract, call `register(string,
 * factory)` from a service provider or test setUp. No schema change,
 * no controller change.
 *
 * `driver(?$name)` returns the requested driver — or the default when
 * `$name` is null. Default is sourced from `notifications.mail_webhook_
 * driver` (env `MAIL_WEBHOOK_DRIVER`), default 'postmark'.
 */
final class MailWebhookManager
{
    /** @var array<string, callable(): MailWebhookContract> */
    private array $factories = [];

    public function __construct()
    {
        // Postmark is pre-registered. Other providers self-register via
        // their own service-provider boot() hook.
        $this->register('postmark', static fn () => app(PostmarkMailWebhookHandler::class));
    }

    /**
     * Register a driver factory. Re-registering an existing driver
     * overwrites it — useful in tests to swap in a fake.
     */
    public function register(string $name, callable $factory): void
    {
        $this->factories[$name] = $factory;
    }

    public function driver(?string $name = null): MailWebhookContract
    {
        $name ??= (string) config('notifications.mail_webhook_driver', 'postmark');

        if (! isset($this->factories[$name])) {
            throw new \InvalidArgumentException("Mail webhook driver [{$name}] is not registered.");
        }

        return ($this->factories[$name])();
    }

    /**
     * @return array<int, string>
     */
    public function registered(): array
    {
        return array_keys($this->factories);
    }
}
