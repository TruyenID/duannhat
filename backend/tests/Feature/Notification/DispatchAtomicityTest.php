<?php

/**
 * Dispatch transaction-atomicity test (plan-008 TESTS.md §Side effects):
 *
 *   "dispatch always writes `notifications` + `notification_recipients` in the
 *    same DB transaction — simulated DB error during recipient insert rolls
 *    back parent row"
 *
 * NotificationService::dispatch wraps the parent Notification::create and the
 * bulk NotificationRecipient::insert in a single DB::transaction (see the
 * class docblock + docs/contributing/service.md). If the child insert fails
 * after the parent row is created, the whole unit of work must roll back so no
 * orphaned content row is left behind.
 *
 * To force a deterministic failure at the recipient-insert step (and ONLY
 * there — the parent create must already have succeeded), we drop the
 * `resolved_via` column that the bulk insert always writes to. The insert then
 * references a non-existent column and throws a QueryException from inside the
 * transaction, exactly as a real DB error would.
 */

use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
});

it('rolls back the parent notification when the recipient insert fails', function () {
    $recipient = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    // Simulate a DB error at the recipient-insert step: remove a column the
    // bulk insert unconditionally writes to. Notification::create (which runs
    // first, on a different table) still succeeds; the child insert then
    // throws mid-transaction.
    Schema::table('notification_recipients', function ($table) {
        $table->dropColumn('resolved_via');
    });

    $service = app(NotificationService::class);

    expect(fn () => $service->dispatch([
        'type' => 'recipe.approved',
        'organization_id' => $this->orgId,
        'recipients' => collect([$recipient]),
    ]))->toThrow(QueryException::class);

    // Parent content row must NOT persist — the transaction rolled it back.
    expect(Notification::query()->count())->toBe(0);
    expect(NotificationRecipient::query()->count())->toBe(0);
});
