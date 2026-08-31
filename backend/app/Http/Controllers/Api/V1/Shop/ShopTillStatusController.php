<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\TillSession;
use App\Services\Pos\TillSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only till status for admin-web.
 *
 * Admin-web Settings page calls this to pre-flight the Currency dropdown:
 * if a shift is open, the dropdown is disabled with an inline warning that
 * surfaces who opened it and when. Same intent as the backend guard at
 * ShopOrderSettingsController::update — give admin a clear signal up front
 * instead of letting them PATCH and bounce off a 409.
 *
 * Shape mirrors the pos-web /pos/till/current envelope but trims fields:
 * the admin surface doesn't need denomination counts, only "is there an
 * open session, and which cashier opened it?".
 */
class ShopTillStatusController extends Controller
{
    public function __construct(private readonly TillSessionService $sessions) {}

    public function current(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');

        $state = $this->sessions->currentForBranch($shop->id);

        /** @var TillSession|null $session */
        $session = $state['open_session'];

        // #1130 — the settings guards block on open shift OR open chain
        // (plan-046 R8: the window after a handover, before the next open).
        // The pre-flight must expose BOTH or the UI enables controls the
        // PATCH will 409.
        $hasOpenChain = $this->sessions->branchHasOpenChain($shop->id);

        return response()->json([
            'data' => [
                'has_open_shift' => $session !== null,
                'has_open_chain' => $hasOpenChain,
                'settings_locked' => $session !== null || $hasOpenChain,
                'open_session' => $session === null ? null : [
                    'id' => $session->id,
                    'session_code' => $session->session_code,
                    'opened_at' => $session->opened_at?->toIso8601String(),
                    'opener_name' => $session->opener_name,
                    'opened_by_id' => $session->opened_by_id,
                    'default_currency_code' => $session->default_currency_code,
                ],
            ],
        ]);
    }
}
