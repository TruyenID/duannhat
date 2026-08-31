<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * #1167 — the customer tried to place a take-away order to be prepared NOW at
 * a shop that is currently shut. customer-web greys out ordering while closed,
 * but the FE must not be the only gate: a tab left open past closing time, or
 * a direct API call, would otherwise drop an order into an empty kitchen.
 *
 * A *scheduled* pickup is a different question and keeps its own gate
 * (`PickupOutsideOpeningHoursException`) — pre-ordering at 23:00 for tomorrow
 * lunch is legitimate, and only the requested slot has to be inside the hours.
 *
 * `opens_at` is the next opening instant (ISO-8601, branch-local offset), null
 * when the branch publishes no usable schedule ahead, so the client can say
 * "we open again at 06:00" instead of a bare rejection.
 */
class BranchClosedException extends \RuntimeException
{
    public function __construct(
        private readonly ?string $opensAt = null,
    ) {
        parent::__construct('The shop is closed right now.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'BRANCH_CLOSED',
            'message' => $this->getMessage(),
            'opens_at' => $this->opensAt,
        ], 422);
    }
}
