<?php

declare(strict_types=1);

/**
 * #1262 — two notification systems share one table name, and the natural name
 * is the wrong one.
 *
 * `User` and `Customer` both use Laravel's `Notifiable`, which supplies a
 * `notifications()` relation expecting `notifications.notifiable_id`. The
 * `notifications` table here is the plan-008 DOMAIN table and has no such
 * column, so `$user->notifications` runs SQL against a column that does not
 * exist. The inbox lives on `notificationInbox()`.
 *
 * ReceivesNotifications already documents the collision and aliases
 * `unreadNotifications` away from the trait — the design is deliberate. What was
 * missing is anything that fails when someone reaches for the obvious name, or
 * when someone "fixes" the mismatch by adding notifiable_id to the domain table
 * and quietly merges the two systems.
 *
 * Nothing calls it today; this is a landmine, not a fire.
 */

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('keeps the domain notifications table free of Laravel notifiable columns', function () {
    // If this ever fails, someone has merged the two systems. That may be a fine
    // decision, but it is a decision — the domain table carries organization_id,
    // template_key, aggregation_key and idempotency_key, none of which Laravel's
    // DatabaseNotification knows about.
    expect(Schema::hasTable('notifications'))->toBeTrue();

    foreach (['notifiable_id', 'notifiable_type'] as $column) {
        expect(Schema::hasColumn('notifications', $column))->toBeFalse(
            "notifications.{$column} exists, so Laravel's Notifiable trait and the plan-008 domain "
            .'table now overlap. See #1262 before going further.',
        );
    }
});

it('routes the inbox through notificationInbox, not the trait method', function () {
    // The names are one letter apart in intent and completely different in
    // destination. Pinning both keeps a future rename honest.
    expect(method_exists(User::class, 'notificationInbox'))->toBeTrue()
        ->and(method_exists(Customer::class, 'notificationInbox'))->toBeTrue();

    // Laravel's relation is still there — deliberately, for mail-channel
    // routing — which is exactly why the trap is reachable.
    expect(method_exists(User::class, 'notifications'))->toBeTrue();
});

it('has no caller reaching for the trait relation', function () {
    // A source check because the failure is at the call site, not in a model:
    // `$user->notifications` compiles fine and dies at the database.
    $violations = [];

    foreach (['app', 'routes'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

            // The trait itself and the service property `$this->notifications`
            // (an injected dispatcher, not a relation) are not call sites.
            if (str_contains($path, 'Concerns/ReceivesNotifications.php')) {
                continue;
            }

            foreach (file($file->getPathname()) ?: [] as $number => $line) {
                if (preg_match('/->notifications(\(|\s*->|\s*;)/', $line) !== 1) {
                    continue;
                }
                if (str_contains($line, '$this->notifications->')) {
                    continue;
                }

                $violations[] = sprintf('%s:%d', $path, $number + 1);
            }
        }
    }

    expect($violations)->toBe([], implode("\n  ", [
        "These reach for Laravel's Notifiable relation, which points at the domain",
        'notifications table and has no notifiable_id to match on. Use notificationInbox().',
        ...$violations,
    ]));
});
