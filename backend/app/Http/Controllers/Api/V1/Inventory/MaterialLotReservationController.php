<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\MaterialBatch;
use App\Models\MaterialLot;
use App\Models\MaterialLotReservation;
use App\Services\Inventory\MaterialLotReservationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Shop-scoped write path for material-lot holds (#3112).
 *
 * The HQ twin (`Api\V1\HQ\MaterialLotReservationController`) stays exactly as
 * it was: org-wide reach, HQ-role only. This one shares the SAME service — the
 * hold arithmetic, the `lockForUpdate`, the `Σ(active) ≤ qty_on_hand` guard all
 * live in `MaterialLotReservationService` and are not re-implemented here — and
 * differs only in the ring it is gated by: the `*AtShop` abilities, which
 * additionally require the lot to sit in a warehouse of THE SHOP ON THE URL.
 *
 * The two ability sets are deliberately disjoint so that loosening the shop
 * ring can never loosen the HQ ring. See MaterialLotReservationPolicy.
 */
class MaterialLotReservationController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly MaterialLotReservationService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/shops/{shopSlug}/material-lot-reservations',
        summary: 'List the holds attached to one production batch of this shop',
        description: 'Scoped through the batch on purpose — a shop route must not be able to page through the whole organization\'s holds.',
        tags: ['Material Lot Reservations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'material_batch_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'consumed', 'cancelled', 'expired'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of holds for the batch'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Batch is not in a warehouse of this shop'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'material_batch_id' => ['required', 'string', 'exists:material_batches,id'],
            'status' => ['sometimes', 'nullable', 'string', 'in:active,consumed,cancelled,expired'],
        ]);

        $batch = MaterialBatch::findOrFail($filters['material_batch_id']);

        $this->authorize('viewAnyAtShop', [MaterialLotReservation::class, $batch]);

        $reservations = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'material_batch_id' => $batch->id,
            'status' => $filters['status'] ?? null,
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return response()->json($reservations);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/material-lot-reservations',
        summary: 'Hold qty on a lot of this shop\'s own warehouse',
        tags: ['Material Lot Reservations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['material_lot_id', 'qty_reserved'],
            properties: [
                new OA\Property(property: 'material_lot_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'qty_reserved', type: 'number'),
                new OA\Property(property: 'expected_consume_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'material_batch_id', type: 'string', format: 'uuid', nullable: true, description: 'The production batch this hold serves. Must live in the same shop.'),
                new OA\Property(property: 'reason', type: 'string', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Hold created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Lot (or batch) is not in a warehouse of this shop'),
            new OA\Response(response: 422, description: 'Insufficient available qty / validation failed'),
        ],
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'material_lot_id' => ['required', 'string', 'exists:material_lots,id'],
            'qty_reserved' => ['required', 'numeric', 'gt:0'],
            'expected_consume_at' => ['sometimes', 'nullable', 'date'],
            'material_batch_id' => ['sometimes', 'nullable', 'string', 'exists:material_batches,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $lot = MaterialLot::findOrFail($data['material_lot_id']);

        // Read the batch out of the VALIDATED array, not off the raw request:
        // #2622 — `validate()` strips every key without a rule, so a batch id
        // that survives here is the same value the service will store. Sourcing
        // it from `$request->input()` instead would let the authorization step
        // pass judgement on a value the write path never sees.
        $batch = isset($data['material_batch_id'])
            ? MaterialBatch::findOrFail($data['material_batch_id'])
            : null;

        $this->authorize('createAtShop', [MaterialLotReservation::class, $lot, $batch]);

        $data['reserved_by_id'] = $request->user()->id;

        $reservation = $this->service->reserve($data);

        return response()->json(['data' => $reservation], 201);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/material-lot-reservations/{reservation}/release',
        summary: 'Release (cancel) a hold',
        tags: ['Material Lot Reservations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'reservation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Released'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Hold is not on a lot of this shop'),
        ],
    )]
    public function release(MaterialLotReservation $reservation): JsonResponse
    {
        $this->authorizeOrganization($reservation);
        $this->authorize('releaseAtShop', $reservation);

        return response()->json(['data' => $this->service->release($reservation)]);
    }

    #[OA\Post(
        path: '/api/v1/shops/{shopSlug}/material-lot-reservations/{reservation}/renew',
        summary: 'Renew an active or expired hold',
        tags: ['Material Lot Reservations'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'shopSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'reservation', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [new OA\Property(property: 'expected_consume_at', type: 'string', format: 'date-time', nullable: true)]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Renewed'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Hold is not on a lot of this shop'),
            new OA\Response(response: 422, description: 'Insufficient available qty to reactivate'),
        ],
    )]
    public function renew(Request $request, MaterialLotReservation $reservation): JsonResponse
    {
        $this->authorizeOrganization($reservation);
        $this->authorize('renewAtShop', $reservation);

        $data = $request->validate([
            'expected_consume_at' => ['sometimes', 'nullable', 'date'],
        ]);

        return response()->json(['data' => $this->service->renew($reservation, $data['expected_consume_at'] ?? null)]);
    }
}
