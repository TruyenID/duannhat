<?php

use App\Events\OrderItemAdded;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/*
 * #555 M11 — OrderItemAdded::broadcastWith() re-queries the DB for items_count
 * / subtotal / total. If the event fired before the enclosing DB transaction
 * committed (or at all when it rolled back), a Redis-queued broadcast could
 * ship phantom or pre-commit state. Its sibling order events (OrderPaid,
 * OrderItemStatusChanged) already dispatch after commit; this pins the parity.
 */

it('dispatches only after the surrounding transaction commits', function () {
    $implements = (new ReflectionClass(OrderItemAdded::class))
        ->implementsInterface(ShouldDispatchAfterCommit::class);

    expect($implements)->toBeTrue();
});
