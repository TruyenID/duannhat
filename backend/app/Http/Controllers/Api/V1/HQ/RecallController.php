<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\Recall;
use App\Services\Inventory\RecallDrillService;
use App\Services\Inventory\RecallService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class RecallController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly RecallService $service,
        private readonly RecallDrillService $drillService,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/recalls',
        summary: 'List recalls',
        tags: ['Recalls'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'active', 'completed', 'cancelled'])),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated list')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Recall::class);

        $recalls = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'brand_id' => $request->input('brand_id'),
            'status' => $request->input('status'),
            'search' => $request->input('search'),
            'sort' => $request->input('sort', '-initiated_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return response()->json([
            'data' => $recalls->items(),
            'meta' => [
                'current_page' => $recalls->currentPage(),
                'from' => $recalls->firstItem(),
                'last_page' => $recalls->lastPage(),
                'per_page' => $recalls->perPage(),
                'to' => $recalls->lastItem(),
                'total' => $recalls->total(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/recalls/{recall}',
        summary: 'Get a recall with affected lots / orders',
        tags: ['Recalls'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'recall', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Recall detail')],
    )]
    public function show(Recall $recall): JsonResponse
    {
        $this->authorizeOrganization($recall);
        $this->authorize('view', $recall);

        return response()->json(['data' => $this->service->findById($recall->id)]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/recalls/preview',
        summary: 'Preview recall blast radius without committing',
        tags: ['Recalls'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['root_lot_id'],
            properties: [new OA\Property(property: 'root_lot_id', type: 'string', format: 'uuid')]
        )),
        responses: [new OA\Response(response: 200, description: 'Affected counts + ids')],
    )]
    public function preview(Request $request): JsonResponse
    {
        $this->authorize('create', Recall::class);

        $data = $request->validate([
            'root_lot_id' => ['required', 'string', 'exists:material_lots,id'],
        ]);

        // plan-040 H7/TG.2 + W3: scope the root lot to the caller's org AND brand —
        // a lot owned by another org or another brand returns 404 (handled by the
        // service's scoped firstOrFail).
        $preview = $this->service->preview(
            $data['root_lot_id'],
            $this->getOrganizationId(),
            $request->attributes->get('brand_id'),
        );

        return response()->json([
            'root_lot' => $preview['root_lot']->only(['id', 'lot_code', 'supplier_name']),
            'affected_lots_count' => count($preview['affected_lot_ids']),
            'affected_orders_count' => count($preview['affected_order_ids']),
            'affected_lot_ids' => $preview['affected_lot_ids'],
            'affected_order_ids' => $preview['affected_order_ids'],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/recalls',
        summary: 'Initiate a recall (auto-quarantines downstream lots)',
        tags: ['Recalls'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['root_lot_id', 'reason'],
            properties: [
                new OA\Property(property: 'root_lot_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'reason', type: 'string', maxLength: 2000),
                new OA\Property(property: 'scope_type', type: 'string', enum: ['lot', 'supplier_lot', 'material'], default: 'lot'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Recall initiated'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Recall::class);

        $data = $request->validate([
            'root_lot_id' => ['required', 'string', 'exists:material_lots,id'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'scope_type' => ['sometimes', 'in:lot,supplier_lot,material'],
        ]);

        $data['organization_id'] = $this->getOrganizationId();
        // plan-040 W3: pass the route brand so initiate scopes the ROOT lot to the
        // caller's brand (a brand_admin can only recall a lot of their own brand).
        $data['brand_id'] = $request->attributes->get('brand_id');
        $data['initiated_by_id'] = $request->user()?->id;

        $recall = $this->service->initiate($data);

        return response()->json(['data' => $recall], 201);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/recalls/{recall}/complete',
        summary: 'Mark recall as completed',
        tags: ['Recalls'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'recall', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Completed')],
    )]
    public function complete(Recall $recall): JsonResponse
    {
        $this->authorizeOrganization($recall);
        $this->authorize('complete', $recall);

        return response()->json(['data' => $this->service->complete($recall)]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/recalls/{recall}/cancel',
        summary: 'Cancel a recall (releases auto-quarantined lots)',
        tags: ['Recalls'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'recall', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['cancellation_reason'],
            properties: [new OA\Property(property: 'cancellation_reason', type: 'string', maxLength: 2000)]
        )),
        responses: [new OA\Response(response: 200, description: 'Cancelled')],
    )]
    public function cancel(Request $request, Recall $recall): JsonResponse
    {
        $this->authorizeOrganization($recall);
        $this->authorize('cancel', $recall);

        $data = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return response()->json(['data' => $this->service->cancel($recall, $data['cancellation_reason'])]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/recalls/{recall}/notify',
        summary: 'Dispatch recall notification to affected users',
        tags: ['Recalls'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'recall', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [new OA\Property(property: 'channel', type: 'string', enum: ['in_app', 'email'], default: 'in_app')]
        )),
        responses: [new OA\Response(response: 200, description: 'Notification dispatched')],
    )]
    public function notify(Request $request, Recall $recall): JsonResponse
    {
        $this->authorizeOrganization($recall);
        $this->authorize('notify', $recall);

        $data = $request->validate([
            'channel' => ['sometimes', 'string', 'in:in_app,email'],
        ]);

        return response()->json(['data' => $this->service->notify($recall, $data['channel'] ?? null)]);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/recalls/{recall}/report',
        summary: 'FSMA-204-shaped recall report',
        tags: ['Recalls'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'recall', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Recall report JSON')],
    )]
    public function report(Recall $recall): JsonResponse
    {
        $this->authorizeOrganization($recall);
        $this->authorize('view', $recall);

        return response()->json(['data' => $this->service->generateReport($recall)]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/recalls/drill',
        summary: 'Run a mock recall drill',
        description: 'Measures traceability speed without quarantining or notifying. Auto-picks a random lot if selected_lot_id is omitted.',
        tags: ['Recalls'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'selected_lot_id', type: 'string', format: 'uuid', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Drill result'),
            new OA\Response(response: 404, description: 'No active lot found'),
        ],
    )]
    public function drill(Request $request): JsonResponse
    {
        $this->authorize('create', Recall::class);

        $data = $request->validate([
            'selected_lot_id' => ['sometimes', 'nullable', 'uuid', 'exists:material_lots,id'],
        ]);

        $drill = $this->drillService->run(
            $request->attributes->get('brand_id'),
            $this->getOrganizationId(),
            $request->user()->id,
            $data['selected_lot_id'] ?? null,
        );

        return response()->json(['data' => $drill], 201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/recalls/drills',
        summary: 'List past recall drills',
        tags: ['Recalls'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'List of drills')],
    )]
    public function drillHistory(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Recall::class);

        $drills = $this->drillService->list(
            $request->attributes->get('brand_id'),
        );

        return response()->json(['data' => $drills]);
    }
}
