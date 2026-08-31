<?php

/**
 * Plan-023 M4 T4.4 — MailWebhookManager registry coverage.
 */

use App\Contracts\Notification\MailWebhookContract;
use App\Omnify\Enums\NotificationDeliveryStatusEnum;
use App\Services\Notification\MailWebhookManager;
use App\Services\Notification\Webhooks\PostmarkMailWebhookHandler;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

it('M4-6: resolves postmark driver by default', function () {
    $manager = app(MailWebhookManager::class);

    expect($manager->driver('postmark'))->toBeInstanceOf(PostmarkMailWebhookHandler::class);
    expect($manager->driver())->toBeInstanceOf(PostmarkMailWebhookHandler::class);
});

it('throws on unknown driver', function () {
    $manager = app(MailWebhookManager::class);

    expect(fn () => $manager->driver('mailgun'))
        ->toThrow(InvalidArgumentException::class, 'Mail webhook driver [mailgun] is not registered');
});

it('allows registering new drivers at runtime', function () {
    $manager = app(MailWebhookManager::class);

    $fake = new class implements MailWebhookContract
    {
        public function verifySignature(Request $request): void {}

        public function parseEvents(Request $request): array
        {
            return [];
        }

        public function mapToDeliveryStatus(string $providerEvent): NotificationDeliveryStatusEnum
        {
            return NotificationDeliveryStatusEnum::Delivered;
        }
    };

    $manager->register('fake', fn () => $fake);

    expect($manager->driver('fake'))->toBe($fake);
    expect($manager->registered())->toContain('postmark', 'fake');
});

it('default driver follows the config key', function () {
    $manager = app(MailWebhookManager::class);
    $fake = new class implements MailWebhookContract
    {
        public function verifySignature(Request $request): void {}

        public function parseEvents(Request $request): array
        {
            return [];
        }

        public function mapToDeliveryStatus(string $providerEvent): NotificationDeliveryStatusEnum
        {
            return NotificationDeliveryStatusEnum::Delivered;
        }
    };
    $manager->register('ses', fn () => $fake);

    config(['notifications.mail_webhook_driver' => 'ses']);

    expect($manager->driver())->toBe($fake);
});
