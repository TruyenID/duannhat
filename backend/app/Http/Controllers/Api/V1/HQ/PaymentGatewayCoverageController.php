<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Resources\PaymentGatewayCoverageResource;
use App\Models\PaymentGatewayConnection;
use App\Services\Payment\Configuration\PaymentGatewayConfigurationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentGatewayCoverageController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly PaymentGatewayConfigurationService $configuration,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', PaymentGatewayConnection::class);

        $coverage = $this->configuration->listCoverage(
            $this->getOrganizationId(),
            (string) $request->attributes->get('brand_id'),
        );

        return PaymentGatewayCoverageResource::collection($coverage);
    }
}
