<?php

namespace App\Http\Middleware;

use App\Services\Pos\TillSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan 030 — Cashier Shift hard-lock.
 *
 * Attached to POST /pos/orders and POST /pos/payments. Resolves the branch's
 * Till; if no current session is open, returns 409 NO_OPEN_SHIFT. On success,
 * exposes `till_session_id` via request attributes so
 * OrderPaymentService::create() (and any future order service) stamps it.
 *
 * MUST run AFTER ResolvePosShop so `shop_id` is available.
 */
class ResolveOpenTillSession
{
    public function __construct(
        private readonly TillSessionService $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $branchId = $request->attributes->get('shop_id');
        if (! $branchId) {
            // ResolvePosShop should have set this; defensive 400.
            abort(400, 'Shop context not resolved.');
        }

        $current = $this->sessions->currentForBranch($branchId);
        if ($current['open_session'] === null) {
            abort(response()->json([
                'message' => 'No cashier shift is currently open on this till.',
                'code' => 'NO_OPEN_SHIFT',
            ], 409));
        }

        $request->attributes->set('till_session_id', $current['open_session']->id);
        $request->attributes->set('till_session', $current['open_session']);

        return $next($request);
    }
}
