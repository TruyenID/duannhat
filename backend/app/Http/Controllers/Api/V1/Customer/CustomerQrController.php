<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\Table;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use Illuminate\Http\JsonResponse;

class CustomerQrController extends Controller
{
    /**
     * Resolve an opaque QR token to whatever it points at — a dine-in table
     * or a takeaway order — so the kiosk can route a single scan without
     * knowing the token's kind up front.
     *
     * Tables are checked first, orders second. Both share the 32-char base62
     * namespace, so a cross-kind collision is astronomically unlikely; fixing
     * the lookup order makes the outcome deterministic regardless.
     *
     * Contract:
     *   - table  → { data: { type: "table", table: { id, code, name } } }
     *   - order  → { data: { type: "order", order: { id, code } } }
     *   - miss / inactive table / voided order → 404
     */
    public function resolve(string $token): JsonResponse
    {
        $table = Table::where('qr_token', $token)
            ->where('is_active', true)
            ->first();

        if ($table) {
            return response()->json([
                'data' => [
                    'type' => 'table',
                    'table' => [
                        'id' => $table->id,
                        'code' => $table->code,
                        'name' => $table->name,
                    ],
                ],
            ]);
        }

        $order = CustomerOrder::where('qr_token', $token)->first();

        // Voided orders read as cancelled → 404. Every other status (incl.
        // closed/paid) still resolves; the kiosk bill screen decides what to
        // render from the order's own status. Raw read dodges the
        // CustomerOrderStatusEnum cast in case of a stray stored value.
        if ($order && $order->getRawOriginal('status') !== CustomerOrderStatusEnum::Voided->value) {
            return response()->json([
                'data' => [
                    'type' => 'order',
                    'order' => [
                        'id' => $order->id,
                        'code' => $order->order_code,
                    ],
                ],
            ]);
        }

        abort(404);
    }
}
