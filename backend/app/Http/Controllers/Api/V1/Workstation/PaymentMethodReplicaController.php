<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/workstation/payment-methods
 *
 * Replica feed for the workstation's SyncPuller. Returns the full active +
 * inactive set so the workstation can mirror it locally and let pos-web
 * filter at read time. Branch is resolved from the device's paired branch.
 */
class PaymentMethodReplicaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;
        $organizationId = $device?->organization_id;

        $methods = PaymentMethod::query()
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->orWhereNull('branch_id'); // org-scoped global methods
            })
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get([
                'id', 'code', 'name', 'is_active', 'sort_order',
                // Drive pos-web's PaymentDialog: requires_tendered makes the
                // cash-tendered field mandatory; is_auto_confirm decides
                // whether pos-web polls /payments/{id}/status after submit.
                'is_auto_confirm', 'requires_tendered',
                // The BEHAVIOURAL classification (cash / card / on_account / …),
                // and the only way the workstation can tell a debt method from a
                // cash one. `code` cannot: it is shop-editable, and a shop may
                // name its on-account method anything.
                //
                // Leaving it out did not fail loudly — the workstation column
                // defaults to 'cash', so every mirrored method looked like cash
                // and two money paths quietly went wrong on LAN:
                // `handleLANPrintDebtSlip` refused every 掛売 slip with
                // `payment_method_not_on_account`, and `ComputeTillDebtSummary`
                // reported 0 debt issued on every shift report no matter how
                // much was recorded.
                'type',
            ]);

        return response()->json([
            'data' => $methods,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
