<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Resources\VariantUnitResource;
use App\Models\ProductSku;
use App\Models\VariantUnit;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\Commands\CreateVariantUnitCommand;
use App\Services\Product\Commands\ReviseVariantUnitCommand;
use App\Services\Product\Commands\VariantUnitLifecycleCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\Enums\VariantUnitLifecycleAction;
use App\Services\Product\ValueObjects\VariantUnitPayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class VariantUnitController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(private readonly ProductMutationFacade $mutations) {}

    // =========================================================================
    //  CRUD
    // =========================================================================

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/skus/{sku}/units',
        summary: 'List units for a SKU',
        description: 'Returns the list of selling units (e.g. piece, box) configured for the SKU. Base unit first.',
        tags: ['SkuUnits'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of SKU units', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 404, description: 'SKU not found'),
        ]
    )]
    public function index(ProductSku $sku): AnonymousResourceCollection
    {
        $this->authorizeVariantOrganization($sku);

        $units = $sku->units()->orderByDesc('is_base')->get();

        return VariantUnitResource::collection($units);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/skus/{sku}/units',
        summary: 'Add a unit to a SKU',
        description: 'Creates a new selling unit for the SKU.',
        tags: ['SkuUnits'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sku', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['unit', 'ratio', 'sku'],
                properties: [
                    new OA\Property(property: 'unit', type: 'string', maxLength: 50),
                    new OA\Property(property: 'ratio', type: 'number'),
                    new OA\Property(property: 'sku', type: 'string', maxLength: 50),
                    new OA\Property(property: 'barcode', type: 'string', maxLength: 100, nullable: true),
                    new OA\Property(property: 'price', type: 'number', nullable: true),
                    new OA\Property(property: 'is_base', type: 'boolean', nullable: true),
                    new OA\Property(property: 'is_sellable', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Unit created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function store(Request $request, ProductSku $sku): JsonResponse
    {
        $this->authorizeVariantOrganization($sku);
        $this->authorize('create', [VariantUnit::class, $sku]);

        $validated = $request->validate([
            'unit' => ['required', 'string', 'max:50'],
            'ratio' => ['required', 'numeric', 'min:0'],
            'sku' => ['required', 'string', 'max:50'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_base' => ['nullable', 'boolean'],
            'is_sellable' => ['nullable', 'boolean'],
        ]);

        $unitId = (string) Str::uuid();
        $payload = $this->payload($validated);
        $this->mutations->createVariantUnit(new CreateVariantUnitCommand(
            $this->context($request, "variant-unit:create:{$unitId}"),
            $unitId,
            $sku->id,
            $sku->product->brand_id,
            $payload,
            $payload->fingerprint(),
        ));
        $unit = VariantUnit::findOrFail($unitId);

        return (new VariantUnitResource($unit))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/sku-units/{unit}',
        summary: 'Get a SKU unit',
        description: 'Returns a single SKU unit by UUID.',
        tags: ['SkuUnits'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'unit', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'SKU unit', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function show(VariantUnit $unit): VariantUnitResource
    {
        $this->authorizeUnitOrganization($unit);
        $this->authorize('view', $unit);

        return new VariantUnitResource($unit);
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/sku-units/{unit}',
        summary: 'Update a SKU unit',
        description: 'Updates an existing SKU unit. Fields are nullable for partial updates.',
        tags: ['SkuUnits'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'unit', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'unit', type: 'string', maxLength: 50, nullable: true),
                new OA\Property(property: 'ratio', type: 'number', nullable: true),
                new OA\Property(property: 'sku', type: 'string', maxLength: 50, nullable: true),
                new OA\Property(property: 'barcode', type: 'string', maxLength: 100, nullable: true),
                new OA\Property(property: 'price', type: 'number', nullable: true),
                new OA\Property(property: 'is_base', type: 'boolean', nullable: true),
                new OA\Property(property: 'is_sellable', type: 'boolean', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ]
    )]
    public function update(Request $request, VariantUnit $unit): VariantUnitResource
    {
        $this->authorizeUnitOrganization($unit);
        $this->authorize('update', $unit);

        $validated = $request->validate([
            'unit' => ['sometimes', 'required', 'string', 'max:50'],
            'ratio' => ['sometimes', 'required', 'numeric', 'min:0'],
            'sku' => ['sometimes', 'required', 'string', 'max:50'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_base' => ['nullable', 'boolean'],
            'is_sellable' => ['nullable', 'boolean'],
        ]);

        $payload = $this->payload($validated, $unit);
        $this->mutations->reviseVariantUnit(new ReviseVariantUnitCommand(
            $this->context($request, "variant-unit:revise:{$unit->id}", $unit),
            $unit->id,
            $unit->productSku->product->brand_id,
            $payload,
            $payload->fingerprint(),
        ));

        return new VariantUnitResource($unit->refresh());
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/sku-units/{unit}',
        summary: 'Delete a SKU unit',
        description: 'Deletes a SKU unit.',
        tags: ['SkuUnits'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'unit', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function destroy(Request $request, VariantUnit $unit): JsonResponse
    {
        $this->authorizeUnitOrganization($unit);
        $this->authorize('delete', $unit);

        $this->mutations->removeVariantUnit(new VariantUnitLifecycleCommand(
            $this->context($request, "variant-unit:remove:{$unit->id}", $unit),
            $unit->id,
            $unit->productSku->product->brand_id,
            VariantUnitLifecycleAction::Remove,
        ));

        return response()->json(null, 204);
    }

    // =========================================================================
    //  Actions
    // =========================================================================

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/sku-units/{unit}/set-base',
        summary: 'Set unit as the base unit',
        description: 'Marks this unit as the base unit for its SKU. Unsets the previous base unit.',
        tags: ['SkuUnits'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'unit', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Set as base', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function setBase(Request $request, VariantUnit $unit): VariantUnitResource
    {
        $this->authorizeUnitOrganization($unit);
        $this->authorize('update', $unit);

        $this->mutations->setBaseVariantUnit(new VariantUnitLifecycleCommand(
            $this->context($request, "variant-unit:make-base:{$unit->id}", $unit),
            $unit->id,
            $unit->productSku->product->brand_id,
            VariantUnitLifecycleAction::MakeBase,
        ));

        return new VariantUnitResource($unit->refresh());
    }

    // =========================================================================
    //  Helpers
    // =========================================================================

    /**
     * Authorize that the SKU's product belongs to the user's organization.
     */
    private function authorizeVariantOrganization(ProductSku $sku): void
    {
        $sku->loadMissing('product');

        if ($sku->product->organization_id !== $this->getOrganizationId()) {
            abort(403, 'Resource does not belong to your organization');
        }
        $this->authorizeBrand($sku->product);
    }

    /**
     * Authorize that the unit's variant's product belongs to the user's organization.
     */
    private function authorizeUnitOrganization(VariantUnit $unit): void
    {
        $unit->loadMissing('productSku.product');

        if ($unit->productSku->product->organization_id !== $this->getOrganizationId()) {
            abort(403, 'Resource does not belong to your organization');
        }
        $this->authorizeBrand($unit->productSku->product);
    }

    /** @param array<string, mixed> $data */
    private function payload(array $data, ?VariantUnit $current = null): VariantUnitPayload
    {
        return new VariantUnitPayload(
            (string) ($data['unit'] ?? $current?->unit),
            (string) ($data['ratio'] ?? $current?->ratio ?? 0),
            (string) ($data['sku'] ?? $current?->sku),
            array_key_exists('barcode', $data) ? $data['barcode'] : $current?->barcode,
            (string) ($data['price'] ?? $current?->price ?? 0),
            (bool) ($data['is_base'] ?? $current?->is_base ?? false),
            (bool) ($data['is_sellable'] ?? $current?->is_sellable ?? true),
        );
    }

    private function context(Request $request, string $fallbackKey, ?VariantUnit $current = null): MutationContext
    {
        return new MutationContext(
            $this->getOrganizationId(),
            $request->user()?->id,
            $request->header('X-Correlation-ID') ?: (string) Str::uuid(),
            $request->header('Idempotency-Key') ?: $fallbackKey,
        );
    }
}
