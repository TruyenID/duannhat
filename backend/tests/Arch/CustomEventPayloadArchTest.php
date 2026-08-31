<?php

/**
 * Plan-023 M8 T8.15 arch test 2 — custom.* event payload contract.
 *
 * Every class that dispatches a custom.* event MUST wrap its payload in
 * CustomNotificationEvent (Decision 29). This test reads the source of
 * every known custom-event dispatcher and verifies the wrapper is present.
 *
 * Add new dispatcher files to DISPATCHERS below when a new custom.*
 * emitter is introduced.
 */

use App\Events\Notification\CustomNotificationEvent;

/**
 * All PHP source files that are expected to dispatch custom.* events.
 * These are the concrete classes that call Event::dispatch('custom.…').
 */
const CUSTOM_EVENT_DISPATCHERS = [
    'DeviceOfflineDetectionJob' => 'app/Jobs/Notification/DeviceOfflineDetectionJob.php',
    'CouponExpirationScannerJob' => 'app/Jobs/Notification/CouponExpirationScannerJob.php',
];

it('M8-arch-2a: CustomNotificationEvent is a final readonly class', function () {
    $refl = new ReflectionClass(CustomNotificationEvent::class);

    expect($refl->isFinal())->toBeTrue('CustomNotificationEvent must be final')
        ->and($refl->isReadOnly())->toBeTrue('CustomNotificationEvent must be readonly');
});

it('M8-arch-2b: every known custom-event dispatcher wraps payload in CustomNotificationEvent', function () {
    $offenders = [];

    foreach (CUSTOM_EVENT_DISPATCHERS as $label => $relPath) {
        $absPath = base_path($relPath);
        expect(file_exists($absPath))->toBeTrue("Dispatcher file missing: {$relPath}");

        $source = file_get_contents($absPath);

        if (! str_contains($source, 'CustomNotificationEvent')) {
            $offenders[] = "{$label} ({$relPath}) — does not reference CustomNotificationEvent";
        }
    }

    expect($offenders)->toBeEmpty(
        'Dispatchers not wrapping payload in CustomNotificationEvent:'.PHP_EOL
        .implode(PHP_EOL, $offenders),
    );
});

it('M8-arch-2c: ModelEventToRuleBridge::onCustomEvent rejects non-CustomNotificationEvent payloads (source guard)', function () {
    $source = file_get_contents(
        base_path('app/Listeners/Notification/ModelEventToRuleBridge.php'),
    );

    // The bridge must import CustomNotificationEvent and check instanceof.
    expect($source)
        ->toContain('CustomNotificationEvent')
        ->toContain('instanceof CustomNotificationEvent');
});
