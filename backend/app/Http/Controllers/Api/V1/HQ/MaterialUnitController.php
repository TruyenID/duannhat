<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\MaterialUnitStoreRequest;
use App\Http\Requests\MaterialUnitUpdateRequest;
use App\Http\Resources\MaterialUnitResource;
use App\Models\Material;
use App\Models\MaterialUnit;
use App\Services\Omnify\MaterialUnitService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class MaterialUnitController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly MaterialUnitService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/materials/{material}/units',
        summary: 'List units for a material',
        tags: ['MaterialUnits'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'material', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [new OA\Response(response: 200, description: 'List of units')],
    )]
    public function index(Material $material): AnonymousResourceCollection
    {
        $this->authorizeOrganization($material);
        $this->authorizeBrand($material);
        $this->authorize('viewAny', MaterialUnit::class);

        return MaterialUnitResource::collection(
            $this->service->listForMaterial($material->id)
        );
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/materials/{material}/units',
        summary: 'Add a unit to a material',
        tags: ['MaterialUnits'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'material', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['unit', 'ratio'],
            properties: [
                new OA\Property(property: 'unit', type: 'string', maxLength: 50),
                new OA\Property(property: 'ratio', type: 'number', minimum: 0, exclusiveMinimum: true),
                new OA\Property(property: 'is_base', type: 'boolean'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Unit created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function store(MaterialUnitStoreRequest $request, Material $material): JsonResponse
    {
        $this->authorizeOrganization($material);
        $this->authorizeBrand($material);
        $this->authorize('create', MaterialUnit::class);

        $data = $request->validated();
        $data['material_id'] = $material->id;

        $unit = $this->service->create($data);

        return (new MaterialUnitResource($unit))->response()->setStatusCode(201);
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/materials/{material}/units/{unit}',
        summary: 'Update a unit',
        tags: ['MaterialUnits'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'material', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'unit', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'unit', type: 'string', maxLength: 50),
                new OA\Property(property: 'ratio', type: 'number'),
                new OA\Property(property: 'is_base', type: 'boolean'),
            ]
        )),
        responses: [new OA\Response(response: 200, description: 'Unit updated')],
    )]
    public function update(MaterialUnitUpdateRequest $request, Material $material, MaterialUnit $unit): MaterialUnitResource
    {
        $this->authorizeOrganization($material);
        $this->authorizeBrand($material);
        // plan-040 NEW-MR-4: the unit must belong to the route material.
        // Without this a caller could mutate another material's unit through
        // /materials/{A}/units/{unit-of-B} (the nested binding only checks the
        // unit exists, not that it hangs off material A).
        $this->assertUnitBelongsToMaterial($material, $unit);
        $this->authorize('update', $unit);

        return new MaterialUnitResource(
            $this->service->update($unit, $request->validated())
        );
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/materials/{material}/units/{unit}',
        summary: 'Delete a unit',
        tags: ['MaterialUnits'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'material', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'unit', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 409, description: 'Unit is in use and cannot be deleted'),
            new OA\Response(response: 422, description: 'Cannot delete base unit'),
        ],
    )]
    public function destroy(Material $material, MaterialUnit $unit): JsonResponse
    {
        $this->authorizeOrganization($material);
        $this->authorizeBrand($material);
        // plan-040 NEW-MR-4: the unit must belong to the route material.
        $this->assertUnitBelongsToMaterial($material, $unit);
        $this->authorize('delete', $unit);

        // Block deleting a unit that lots / transactions were recorded in —
        // removing it would orphan their conversions (TC-UNIT-108).
        if ($this->service->isInUse($unit)) {
            return response()->json([
                'error' => 'UNIT_IN_USE',
                'message' => 'This unit is used by existing lots or stock transactions and cannot be deleted.',
            ], 409);
        }

        $this->service->delete($unit);

        return response()->json(null, 204);
    }

    /**
     * plan-040 NEW-MR-4: guard the nested {material}/units/{unit} binding so a
     * unit can only be mutated/deleted through its OWNING material. A mismatch
     * is a 404 — the unit does not exist *under this material*.
     */
    private function assertUnitBelongsToMaterial(Material $material, MaterialUnit $unit): void
    {
        if ((string) $unit->material_id !== (string) $material->id) {
            abort(404);
        }
    }
}
