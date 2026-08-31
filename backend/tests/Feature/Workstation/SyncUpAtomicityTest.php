<?php

declare(strict_types=1);

/**
 * #1270 — the four Cloud endpoints that ingest workstation sync-UP were split
 * two and two on atomicity: applyCoupon wrapped its call, syncItems wrapped
 * itself, and update/init wrapped nothing.
 *
 * The patch itself is a single write, which is why it does not look dangerous.
 * What it drags behind it is not: refreshOrderTotals → recalculateTotals →
 * applyPricing → writeConditions, and order_conditions holds the pricing rules
 * the totals were computed from. A failure between those statements leaves an
 * order whose totals disagree with the conditions that produced them.
 *
 * The workstation retries sync ops and a patch is idempotent, so a retry heals
 * it — but only if the retry comes. An op that dead-letters leaves the order
 * wrong for good.
 *
 * Asserted at the source, not by forcing a mid-write failure: injecting a fault
 * between two specific statements pins the implementation rather than the
 * property, and would go green the day the call order changes for an unrelated
 * reason.
 *
 * #1666 — the property is that the write path is atomic, NOT that the wrapper
 * sits in any particular file. The original assertions read the controller and
 * so pinned the location: they went red when `update`/`init` moved their
 * transaction down into `OrderService::patchWorkstationOrder`, where a
 * consistency boundary belongs, even though nothing about atomicity changed.
 * They now follow the call chain instead — controller OR the service it calls.
 *
 * #1686 — applyCoupon then made the same move, into
 * `OrderCouponService::applyWorkstationCoupon`. Note what this file can and
 * cannot prove: a grep only says a wrapper EXISTS somewhere on the chain, not
 * that it spans both writes. `WorkstationApplyCouponAtomicityTest` proves the
 * property, by failing the second write and requiring the first to be gone.
 */
it('wraps every workstation sync-UP write path in a transaction', function () {
    $controller = file_get_contents(
        base_path('app/Http/Controllers/Api/V1/Workstation/OrderLifecycleController.php'),
    );
    $orderService = file_get_contents(base_path('app/Services/Order/OrderService.php'));

    // Each handler that reaches a multi-statement write: the context tag that
    // locates its call site, and the facade method that performs the write.
    $mustBeWrapped = [
        'patch-order' => ['update', 'patchWorkstationOrder'],
        'init-order' => ['init', 'patchWorkstationOrder'],
    ];

    $unwrapped = [];
    foreach ($mustBeWrapped as $contextTag => [$handler, $facadeMethod]) {
        // The context tag is unique per handler, so it locates the call site
        // without depending on line numbers.
        $position = strpos($controller, "'{$contextTag}'");
        expect($position)->not->toBeFalse("could not find the {$handler} call site by its '{$contextTag}' context tag");

        // Walk back to the nearest enclosing statement: a transaction between
        // the handler's start and the call satisfies atomicity at the caller.
        $before = substr($controller, 0, $position);
        $handlerStart = strrpos($before, "public function {$handler}(");
        expect($handlerStart)->not->toBeFalse("could not find handler {$handler}()");

        $callerScope = substr($controller, $handlerStart, $position - $handlerStart);

        // Otherwise the callee must own it. Slice the facade method's body the
        // same way — from its signature to the start of the next method.
        $methodStart = strpos($orderService, "public function {$facadeMethod}(");
        expect($methodStart)->not->toBeFalse("could not find OrderService::{$facadeMethod}()");
        $nextMethod = strpos($orderService, 'public function ', $methodStart + 1);
        $calleeScope = substr(
            $orderService,
            $methodStart,
            ($nextMethod === false ? strlen($orderService) : $nextMethod) - $methodStart,
        );

        if (! str_contains($callerScope, 'DB::transaction') && ! str_contains($calleeScope, 'DB::transaction')) {
            $unwrapped[] = "{$handler}() reaches {$facadeMethod} with no transaction on either side";
        }
    }

    expect($unwrapped)->toBe([], implode("\n  ", [
        'These sync-UP handlers write order totals and order_conditions across several',
        'statements with no transaction, while applyCoupon and syncItems wrap theirs:',
        ...$unwrapped,
    ]));
});

it('keeps the two paths that were already atomic that way', function () {
    // Named so a future refactor that "simplifies" one of them has to argue
    // with a test rather than pass silently.
    $controller = file_get_contents(
        base_path('app/Http/Controllers/Api/V1/Workstation/OrderLifecycleController.php'),
    );
    // #1686 — applyCoupon's wrapper moved DOWN, for the same reason update/init's
    // did: the controller was holding a consistency boundary for a use case it
    // does not own. `OrderCouponService` (Ordering) already had both halves as
    // collaborators, so it can own the transaction without the surface doing it.
    //
    // Follow the chain, exactly as the first test does: the controller's handler
    // OR the service method it delegates to must carry the wrapper. Pinning the
    // controller is what made these assertions go red at #1666 for a refactor
    // that changed nothing about atomicity.
    $applyCoupon = strpos($controller, 'public function applyCoupon(');
    expect($applyCoupon)->not->toBeFalse();
    $handlerScope = substr($controller, $applyCoupon, 2000);

    $couponService = file_get_contents(base_path('app/Services/Order/Coupon/OrderCouponService.php'));
    $orchestrator = strpos($couponService, 'public function applyWorkstationCoupon(');
    expect($orchestrator)->not->toBeFalse(
        'the applyCoupon orchestrator is gone; the controller is spanning two services again',
    );
    $nextMethod = strpos($couponService, 'public function ', $orchestrator + 1);
    $orchestratorScope = substr(
        $couponService,
        $orchestrator,
        ($nextMethod === false ? strlen($couponService) : $nextMethod) - $orchestrator,
    );

    expect(str_contains($handlerScope, 'DB::transaction') || str_contains($orchestratorScope, 'DB::transaction'))
        ->toBeTrue(
            'apply-coupon spans TWO writes (order binding + coupon redemption); dropping the '.
            'transaction splits the redemption from the discount. Behaviour proof lives in '.
            'WorkstationApplyCouponAtomicityTest — this only checks the wrapper is somewhere on the chain.',
        );

    $transport = file_get_contents(
        base_path('app/Services/Order/Internal/Concerns/WritesCustomerOrders.php'),
    );
    $syncItems = strpos($transport, 'function transportWorkstationSyncItems');
    expect($syncItems)->not->toBeFalse();

    $body = substr($transport, $syncItems, 4000);
    // str_contains, not toContain: for strings Pest reads every extra argument
    // as another needle, so a message passed there is searched for in the body.
    expect(str_contains($body, 'DB::transaction'))->toBeTrue(
        'transportWorkstationSyncItems wrapped itself; removing that leaves syncItems non-atomic',
    );
});
