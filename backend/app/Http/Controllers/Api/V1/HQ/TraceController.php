<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Resources\TraceTreeResource;
use App\Models\MaterialLot;
use App\Services\Inventory\TraceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class TraceController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly TraceService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/trace/lot/{lot}',
        summary: 'Trace a material lot (forward + backward)',
        tags: ['Trace'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lot', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['forward', 'backward', 'both'], default: 'both')),
            new OA\Parameter(name: 'max_depth', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 6)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trace tree (recursive)'),
            new OA\Response(response: 404, description: 'Lot not found'),
            new OA\Response(response: 429, description: 'Rate-limited (30/min)'),
        ],
    )]
    public function lot(Request $request, string $lot): TraceTreeResource
    {
        // trace.view first: it is the narrower gate (org-admin / org-manager),
        // and the one this endpoint was designed around — recursive joins that
        // fan out over the whole organisation's lot genealogy (#1271). The
        // MaterialLot check stays because the trace reads lots, so a caller who
        // may not read lots at all must still be refused.
        $this->authorize('trace.view');
        $this->authorize('viewAny', MaterialLot::class);

        $direction = in_array($request->input('direction'), ['forward', 'backward', 'both'], true)
            ? $request->input('direction')
            : 'both';
        $maxDepth = max(1, min((int) $request->input('max_depth', TraceService::DEFAULT_MAX_DEPTH), TraceService::ABSOLUTE_MAX_DEPTH));

        return new TraceTreeResource($this->service->traceLot($lot, $direction, $maxDepth));
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/trace/customer-order/{order}',
        summary: 'Trace a customer order back to source supplier lots',
        tags: ['Trace'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'max_depth', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 6)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trace tree'),
            new OA\Response(response: 404, description: 'Order not found / no edges'),
            new OA\Response(response: 429, description: 'Rate-limited (30/min)'),
        ],
    )]
    public function customerOrder(Request $request, string $order): TraceTreeResource
    {
        // trace.view first: it is the narrower gate (org-admin / org-manager),
        // and the one this endpoint was designed around — recursive joins that
        // fan out over the whole organisation's lot genealogy (#1271). The
        // MaterialLot check stays because the trace reads lots, so a caller who
        // may not read lots at all must still be refused.
        $this->authorize('trace.view');
        $this->authorize('viewAny', MaterialLot::class);

        $maxDepth = max(1, min((int) $request->input('max_depth', TraceService::DEFAULT_MAX_DEPTH), TraceService::ABSOLUTE_MAX_DEPTH));

        return new TraceTreeResource($this->service->traceCustomerOrder($order, $maxDepth));
    }
}
