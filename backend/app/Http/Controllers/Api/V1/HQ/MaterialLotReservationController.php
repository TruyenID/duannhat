<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\MaterialLotReservation;
use App\Services\Inventory\MaterialLotReservationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class MaterialLotReservationController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly MaterialLotReservationService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/material-lot-reservations',
        summary: 'List lot reservations',
        tags: ['MaterialLotReservations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'material_lot_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'consumed', 'cancelled', 'expired'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated list')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MaterialLotReservation::class);

        $reservations = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'material_lot_id' => $request->input('material_lot_id'),
            'status' => $request->input('status'),
            'material_batch_id' => $request->input('material_batch_id'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return response()->json($reservations);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/material-lot-reservations/{reservation}',
        summary: 'Get a single reservation',
        tags: ['MaterialLotReservations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'reservation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Reservation detail')],
    )]
    public function show(MaterialLotReservation $reservation): JsonResponse
    {
        $this->authorizeOrganization($reservation);
        $this->authorize('view', $reservation);

        return response()->json(['data' => $this->service->findById($reservation->id)]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/material-lot-reservations',
        summary: 'Reserve qty from a lot',
        tags: ['MaterialLotReservations'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['material_lot_id', 'qty_reserved'],
            properties: [
                new OA\Property(property: 'material_lot_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'qty_reserved', type: 'number'),
                new OA\Property(property: 'expected_consume_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'material_batch_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'reason', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Reservation created'),
            new OA\Response(response: 422, description: 'Insufficient available qty'),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', MaterialLotReservation::class);

        $data = $request->validate([
            'material_lot_id' => ['required', 'string', 'exists:material_lots,id'],
            'qty_reserved' => ['required', 'numeric', 'gt:0'],
            'expected_consume_at' => ['sometimes', 'nullable', 'date'],
            'material_batch_id' => ['sometimes', 'nullable', 'string', 'exists:material_batches,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $data['reserved_by_id'] = $request->user()->id;

        $reservation = $this->service->reserve($data);

        return response()->json(['data' => $reservation], 201);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/material-lot-reservations/{reservation}/release',
        summary: 'Release (cancel) a reservation',
        tags: ['MaterialLotReservations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'reservation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'Released')],
    )]
    public function release(MaterialLotReservation $reservation): JsonResponse
    {
        $this->authorizeOrganization($reservation);
        $this->authorize('release', $reservation);

        return response()->json(['data' => $this->service->release($reservation)]);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/material-lot-reservations/{reservation}/renew',
        summary: 'Renew an active or expired reservation',
        tags: ['MaterialLotReservations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'reservation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [new OA\Property(property: 'expected_consume_at', type: 'string', format: 'date-time')]
        )),
        responses: [new OA\Response(response: 200, description: 'Renewed')],
    )]
    public function renew(Request $request, MaterialLotReservation $reservation): JsonResponse
    {
        $this->authorizeOrganization($reservation);
        $this->authorize('renew', $reservation);

        $data = $request->validate([
            'expected_consume_at' => ['sometimes', 'nullable', 'date'],
        ]);

        return response()->json(['data' => $this->service->renew($reservation, $data['expected_consume_at'] ?? null)]);
    }
}
