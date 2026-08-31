<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Issue #603 — a guest takeaway order must carry at least one reachable contact
 * channel (phone OR email) so staff can reach the customer when the food is
 * ready. Thrown by CustomerTakeawayOrderService::place; rendered as the same 422
 * the controller returned inline.
 */
class TakeawayContactRequiredException extends \RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'TAKEAWAY_CONTACT_REQUIRED',
            'message' => 'A takeaway order requires a phone number or email so staff can contact the customer.',
        ], 422);
    }
}
