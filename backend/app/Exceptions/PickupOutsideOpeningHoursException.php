<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * #1160 — the customer scheduled a takeaway pickup for a moment the shop is
 * shut. customer-web already caps the picker, but the FE must not be the only
 * gate: a stale tab (hours edited after load) or a direct API call would
 * otherwise book food for 03:00.
 *
 * `closes_at` is the branch-local closing time of the day the customer aimed
 * at (ISO-8601, null when that day is closed outright), so the client can say
 * "we close at 22:00" instead of a bare rejection.
 */
class PickupOutsideOpeningHoursException extends \RuntimeException
{
    public function __construct(
        private readonly ?string $closesAt = null,
    ) {
        parent::__construct('The requested pickup time falls outside the shop opening hours.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'PICKUP_OUTSIDE_OPENING_HOURS',
            'message' => $this->getMessage(),
            'closes_at' => $this->closesAt,
        ], 422);
    }
}
