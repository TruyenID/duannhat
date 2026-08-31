<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\MaterialLotReceiveRequest;
use App\Http\Resources\MaterialLotResource;
use App\Models\MaterialLot;
use App\Services\Inventory\MaterialLotService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class MaterialLotController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly MaterialLotService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/material-lots',
        summary: 'List material lots (Shop scope)',
        description: 'Paginated list of material lots, shop-scoped. The optional `search` term matches lot_code / supplier_lot_code / supplier_name and the parent material\'s sku / translated name.',
        tags: ['MaterialLots'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'material_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Free-text. Matches lot_code, supplier_lot_code, supplier_name, material.sku, material.name (translated).', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: '-received_at')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MaterialLot::class);

        $lots = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            // #1091 — branch context so date filters bind to the BRANCH's business day.
            'branch_id' => $request->attributes->get('shop_id'),
            'warehouse_id' => $request->input('warehouse_id'),
            'material_id' => $request->input('material_id'),
            'status' => $request->input('status'),
            'search' => $request->input('search'),
            'sort' => $request->input('sort', '-received_at'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return MaterialLotResource::collection($lots);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/material-lots/{lot}',
        summary: 'Get a material lot',
        tags: ['MaterialLots'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lot', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Material lot'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(MaterialLot $lot): MaterialLotResource
    {
        $this->authorizeOrganization($lot);
        $this->authorize('view', $lot);

        return new MaterialLotResource($this->service->findById($lot->id));
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/material-lots/receive',
        summary: 'Receive a supplier lot',
        description: 'Creates a new MaterialLot + auto stock_in transaction. The transaction auto-completes when the warehouse has auto_approve_stock_in enabled.',
        tags: ['MaterialLots'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['material_id', 'warehouse_id', 'received_qty'],
            properties: [
                new OA\Property(property: 'material_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'supplier_name', type: 'string', maxLength: 255, nullable: true),
                new OA\Property(property: 'supplier_lot_code', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'received_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'expiry_date', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'received_qty', type: 'number'),
                new OA\Property(property: 'unit', type: 'string', maxLength: 50, nullable: true),
                new OA\Property(property: 'cost_per_unit', type: 'number', nullable: true),
                new OA\Property(property: 'coa_urls', type: 'object', nullable: true, description: 'Language → CoA URL map, e.g. {"ja": "https://...", "en": "https://..."}'),
                new OA\Property(property: 'allergen_override_reason', type: 'string', maxLength: 1000, nullable: true, description: 'Override reason when receiving a lot with forbidden allergens at this warehouse'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Lot received'),
            new OA\Response(response: 422, description: 'Validation failed — includes allergen_forbidden_at_warehouse when allergen policy violated'),
        ],
    )]
    public function receive(MaterialLotReceiveRequest $request): JsonResponse
    {
        $this->authorize('create', MaterialLot::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['created_by_id'] = $request->user()?->id;

        $result = $this->service->receive($data);

        return (new MaterialLotResource($result['lot']))
            ->additional(['stock_transaction_id' => $result['stock_transaction']->id])
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/material-lots/{lot}/split',
        summary: 'Split a lot into smaller child lots',
        description: 'Splits a parent lot into N child lots. Parent is depleted; children inherit metadata. Optionally assign children to different warehouses.',
        tags: ['MaterialLots'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lot', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['parts'],
            properties: [
                new OA\Property(property: 'parts', type: 'array', items: new OA\Items(
                    required: ['qty'],
                    properties: [
                        new OA\Property(property: 'qty', type: 'number', description: 'Quantity for the child lot'),
                        new OA\Property(property: 'target_warehouse_id', type: 'string', format: 'uuid', nullable: true, description: 'Optional warehouse for cross-warehouse split'),
                    ],
                    type: 'object',
                ), minItems: 1),
                new OA\Property(property: 'reason', type: 'string', nullable: true, description: 'Reason for splitting'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Split result with parent and children'),
            new OA\Response(response: 422, description: 'Validation failed (sum exceeds qty or lot not active)'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Lot not found'),
        ],
    )]
    public function split(Request $request, MaterialLot $lot): JsonResponse
    {
        $this->authorizeOrganization($lot);
        $this->authorize('split', $lot);

        $data = $request->validate([
            // A single part is valid: split off one portion and the parent keeps
            // the remainder (it stays Active). See MaterialLotService::split.
            'parts' => ['required', 'array', 'min:1'],
            'parts.*.qty' => ['required', 'numeric', 'gt:0'],
            // #851 — scope the cross-warehouse split target to the caller's org
            // so a tenant cannot move stock into another org's warehouse.
            'parts.*.target_warehouse_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('warehouses', 'id')->where('organization_id', $this->getOrganizationId()),
            ],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $result = $this->service->split($lot, $data['parts'], $data['reason'] ?? null, $request->user()?->id);

        return response()->json([
            'data' => [
                'parent' => new MaterialLotResource($result['parent']),
                'children' => MaterialLotResource::collection($result['children']),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/material-lots/{lot}/return',
        summary: 'Return qty back to a lot',
        description: 'Adds qty back to a lot (e.g. production leftover, customer return). Depleted lots revert to active.',
        tags: ['MaterialLots'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lot', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['qty', 'reason'],
            properties: [
                new OA\Property(property: 'qty', type: 'number', description: 'Quantity to return'),
                new OA\Property(property: 'reason', type: 'string', maxLength: 500, description: 'Reason for return'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Lot updated with returned qty'),
            new OA\Response(response: 422, description: 'Validation failed'),
            new OA\Response(response: 403, description: 'Unauthorized'),
            new OA\Response(response: 404, description: 'Lot not found'),
        ],
    )]
    public function returnQty(Request $request, MaterialLot $lot): MaterialLotResource
    {
        $this->authorizeOrganization($lot);
        $this->authorize('create', MaterialLot::class);

        $data = $request->validate([
            'qty' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $updated = $this->service->returnQty($lot, (float) $data['qty'], $data['reason'], $request->user()?->id);

        return new MaterialLotResource($updated);
    }

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/material-lots/expiring',
        summary: 'List lots expiring within N days',
        tags: ['MaterialLots'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 7)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated list')],
    )]
    public function expiring(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MaterialLot::class);

        $lots = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            // #1091 — branch context so date filters bind to the BRANCH's business day.
            'branch_id' => $request->attributes->get('shop_id'),
            'warehouse_id' => $request->input('warehouse_id'),
            'expiring_within_days' => $request->integer('days', 7),
            'sort' => 'expiry_date',
            'per_page' => min($request->integer('per_page', 50), 100),
        ]);

        return MaterialLotResource::collection($lots);
    }
}
