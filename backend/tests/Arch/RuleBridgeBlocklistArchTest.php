<?php

/**
 * Plan-023 M7 T7.15 — guard the ModelEventToRuleBridge blocklist.
 *
 * Infinite-loop hazard: a rule whose action dispatches a Notification
 * would normally fire on its own emission. ModelEventToRuleBridge
 * prevents the loop by skipping events on four notification-domain
 * models. If a future refactor accidentally drops one of those
 * classes from the BLOCKLIST literal, this arch test fails the build.
 *
 * The other guard is the test for the listener itself (T7.3-T7.4
 * scenarios) — but those run inside transactional DB tests and won't
 * catch a structural regression. This arch test is the cheap canary.
 */

use App\Listeners\Notification\ModelEventToRuleBridge;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationRecipient;
use App\Models\NotificationRuleFiring;

it('ModelEventToRuleBridge blocklist contains the four notification-domain models', function () {
    $blocklist = ModelEventToRuleBridge::BLOCKLIST;

    expect($blocklist)
        ->toContain(Notification::class)
        ->toContain(NotificationRecipient::class)
        ->toContain(NotificationDelivery::class)
        ->toContain(NotificationRuleFiring::class);
});

it('bridge source carries each blocked class name (either FQN or imported)', function () {
    $source = file_get_contents(
        (new ReflectionClass(ModelEventToRuleBridge::class))->getFileName(),
    );

    foreach ([
        'Notification::class',
        'NotificationRecipient::class',
        'NotificationDelivery::class',
        'NotificationRuleFiring::class',
    ] as $token) {
        expect($source)->toContain($token);
    }
});

it('blocklist is referenced from the handle() flow (not dead code)', function () {
    $source = file_get_contents(
        (new ReflectionClass(ModelEventToRuleBridge::class))->getFileName(),
    );
    expect($source)->toContain('self::BLOCKLIST');
});
