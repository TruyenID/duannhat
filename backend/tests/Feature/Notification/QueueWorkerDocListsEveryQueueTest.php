<?php

/**
 * #2552 — the production worker command in the docs must name every queue the
 * code actually dispatches to.
 *
 * The incident this closes is a doc that was RIGHT about three queues and silent
 * about four more. `notifications-digest` piles up visibly (hourly), so it got
 * noticed; the delivery queues fail the other way — every rule evaluates, every
 * schedule fires, and nothing is delivered.
 *
 * Asserted against the ENUM rather than a hand-typed list: the delivery family is
 * generated (`'notifications-'.$channel`), so a list written here would go stale
 * the same way the doc did.
 */

use App\Omnify\Enums\NotificationChannelEnum;

uses()->group('notification');

function queueWorkerDoc(): string
{
    $path = base_path('../docs/explanation/notifications.md');
    if (! is_file($path)) {
        throw new RuntimeException("missing doc: {$path}");
    }

    return file_get_contents($path);
}

/** The `--queue=` list from the documented worker command. */
function documentedWorkerQueues(): array
{
    if (preg_match('/--queue=([a-z0-9_,\-]+)/i', queueWorkerDoc(), $m) !== 1) {
        throw new RuntimeException('the doc no longer shows a `--queue=` worker command');
    }

    return explode(',', $m[1]);
}

it('names every channel delivery queue, derived from the enum', function () {
    // The four that were missing. Driven off the enum so adding a channel fails
    // here instead of failing in production as an undelivered notification.
    $documented = documentedWorkerQueues();

    foreach (NotificationChannelEnum::cases() as $channel) {
        // `in_array` + `toBeTrue` rather than `toContain`: Pest's `toContain`
        // takes MANY VALUES, not a message, so a message passed there becomes a
        // second string the array must contain — the assertion then fails for a
        // reason that has nothing to do with queues. Cost me a run.
        expect(in_array('notifications-'.$channel->value, $documented, true))->toBeTrue(
            "channel [{$channel->value}] dispatches to `notifications-{$channel->value}` "
            .'(NotificationChannelJob) but the documented worker does not drain it — '
            .'every notification on that channel would sit unprocessed',
        );
    }
});

it('names every OTHER named queue any job asks for', function () {
    // Scans the source for `onQueue('...')` instead of listing the three jobs by
    // class. The three are set in constructors, so reflection on an
    // uninitialised instance sees nothing — and a hand-typed class list has the
    // same failure mode as the hand-typed queue list this test exists to catch.
    // A fourth background job on a fourth queue fails here on the day it lands.
    $documented = documentedWorkerQueues();
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Jobs')));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match_all("/onQueue\\(\\s*'([a-z0-9_\\-]+)'/i", file_get_contents($file->getPathname()), $m)) {
            foreach ($m[1] as $queue) {
                $found[$queue] = $file->getFilename();
            }
        }
    }

    expect($found)->not->toBeEmpty('no onQueue() literal found — did Jobs/ move?');

    foreach ($found as $queue => $where) {
        expect(in_array($queue, $documented, true))->toBeTrue(
            "{$where} dispatches to [{$queue}], which the documented worker does not drain",
        );
    }
});

it('keeps `default` first — orders and payments must not queue behind digests', function () {
    expect(documentedWorkerQueues()[0])->toBe('default');
});
