<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\PaymentGatewayConnection;
use App\Services\Payment\Configuration\PaymentReadinessOverviewBuilder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Brand payment "Overview" tab — real counts, no fixture (#F1). */
class PaymentReadinessController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly PaymentReadinessOverviewBuilder $overview,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PaymentGatewayConnection::class);

        return response()->json([
            'data' => $this->overview->build(
                $this->getOrganizationId(),
                (string) $request->attributes->get('brand_id'),
            ),
        ]);
    }
}
