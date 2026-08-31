<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\MaterialSubstitutionRuleStoreRequest;
use App\Http\Requests\MaterialSubstitutionRuleUpdateRequest;
use App\Http\Resources\MaterialSubstitutionRuleResource;
use App\Models\MaterialSubstitutionRule;
use App\Services\Omnify\MaterialSubstitutionRuleService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class MaterialSubstitutionRuleController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly MaterialSubstitutionRuleService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/material-substitution-rules',
        summary: 'List material substitution rules',
        description: 'Returns substitution rules for the brand, optionally filtered by primary material.',
        tags: ['MaterialSubstitutionRules'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'primary_material_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated list')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MaterialSubstitutionRule::class);

        $rules = $this->service->list([
            'organization_id' => $this->getOrganizationId(),
            'brand_id' => $request->attributes->get('brand_id'),
            'primary_material_id' => $request->input('primary_material_id'),
            'per_page' => min($request->integer('per_page', 25), 100),
        ]);

        return MaterialSubstitutionRuleResource::collection($rules);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/material-substitution-rules',
        summary: 'Create a material substitution rule',
        tags: ['MaterialSubstitutionRules'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['primary_material_id', 'substitute_material_id', 'conversion_factor'],
            properties: [
                new OA\Property(property: 'primary_material_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'substitute_material_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'conversion_factor', type: 'number'),
                new OA\Property(property: 'priority', type: 'integer'),
                new OA\Property(property: 'is_active', type: 'boolean'),
                new OA\Property(property: 'notes', type: 'string', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function store(MaterialSubstitutionRuleStoreRequest $request): JsonResponse
    {
        $this->authorize('create', MaterialSubstitutionRule::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        $data['brand_id'] = $request->attributes->get('brand_id');

        $rule = $this->service->create($data);

        return (new MaterialSubstitutionRuleResource($rule))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/material-substitution-rules/{rule}',
        summary: 'Get a material substitution rule',
        tags: ['MaterialSubstitutionRules'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Rule'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function show(MaterialSubstitutionRule $rule): MaterialSubstitutionRuleResource
    {
        $this->authorizeOrganization($rule);
        $this->authorizeBrand($rule);
        $this->authorize('view', $rule);

        return new MaterialSubstitutionRuleResource(
            $this->service->findById($rule->id)
        );
    }

    #[OA\Put(
        path: '/api/v1/hq/{brandSlug}/material-substitution-rules/{rule}',
        summary: 'Update a material substitution rule',
        tags: ['MaterialSubstitutionRules'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ],
    )]
    public function update(MaterialSubstitutionRuleUpdateRequest $request, MaterialSubstitutionRule $rule): MaterialSubstitutionRuleResource
    {
        $this->authorizeOrganization($rule);
        $this->authorizeBrand($rule);
        $this->authorize('update', $rule);

        $rule = $this->service->update($rule, $request->validated());

        return new MaterialSubstitutionRuleResource(
            $rule->load('substituteMaterial')
        );
    }

    #[OA\Delete(
        path: '/api/v1/hq/{brandSlug}/material-substitution-rules/{rule}',
        summary: 'Delete a material substitution rule',
        tags: ['MaterialSubstitutionRules'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'rule', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found'),
        ],
    )]
    public function destroy(MaterialSubstitutionRule $rule): JsonResponse
    {
        $this->authorizeOrganization($rule);
        $this->authorizeBrand($rule);
        $this->authorize('delete', $rule);

        $this->service->delete($rule);

        return response()->json(null, 204);
    }
}
