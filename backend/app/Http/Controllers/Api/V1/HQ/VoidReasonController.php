<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\VoidReasonStoreRequest;
use App\Http\Requests\VoidReasonUpdateRequest;
use App\Http\Resources\VoidReasonResource;
use App\Models\VoidReason;
use App\Services\Shop\VoidReasonService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * plan-051 (#1149) — HQ CRUD for the brand-scoped VoidReason master.
 *
 * index / store / update only. Deactivation is `update {is_active: false}` —
 * there is NO hard-delete endpoint: historical order lines reference reasons
 * by id, so rows are only ever hidden from pickers, never destroyed.
 *
 * When the brand has ZERO rows, index carries a `suggestions` array — five
 * built-in starter reasons (label / stock_effect / requires_note,
 * is_builtin_suggestion=true). They are creation hints for the admin UI,
 * NEVER persisted rows.
 */
class VoidReasonController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    /**
     * The five suggested starter reasons (DESIGN §1.1). Kept in code — they
     * only exist in the HTTP response, never in the database.
     *
     * @var list<array{label: array{ja: string, en: string, vi: string}, stock_effect: string, requires_note: bool}>
     */
    public const SUGGESTIONS = [
        [
            'label' => ['ja' => '打ち間違い', 'en' => 'Entered by mistake', 'vi' => 'Bấm nhầm'],
            'stock_effect' => 'restock',
            'requires_note' => false,
        ],
        [
            'label' => ['ja' => 'お客様の注文変更', 'en' => 'Customer changed item', 'vi' => 'Khách đổi món'],
            'stock_effect' => 'restock',
            'requires_note' => true,
        ],
        [
            'label' => ['ja' => '調理ミス・廃棄', 'en' => 'Cooking mistake / discarded', 'vi' => 'Nấu hỏng / đổ bỏ'],
            'stock_effect' => 'waste',
            'requires_note' => false,
        ],
        [
            'label' => ['ja' => '材料切れ', 'en' => 'Out of ingredients', 'vi' => 'Hết nguyên liệu'],
            'stock_effect' => 'restock',
            'requires_note' => false,
        ],
        [
            'label' => ['ja' => 'サービス（コンプ）', 'en' => 'Comp for customer', 'vi' => 'Comp cho khách'],
            'stock_effect' => 'none',
            'requires_note' => true,
        ],
    ];

    #[OA\Get(
        path: '/api/v1/hq/{brandSlug}/void-reasons',
        summary: 'List void reasons',
        description: 'All void reasons of the brand (active + inactive), ordered by sort_order. When the brand has zero rows, the response includes a `suggestions` array of built-in starter reasons (not persisted).',
        tags: ['VoidReasons'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'List of void reasons'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VoidReason::class);

        $reasons = VoidReason::query()
            ->with('translations')
            ->where('organization_id', $this->getOrganizationId())
            ->where('brand_id', $request->attributes->get('brand_id'))
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        $payload = ['data' => VoidReasonResource::collection($reasons)->resolve($request)];

        if ($reasons->isEmpty()) {
            $payload['suggestions'] = array_map(
                static fn (array $suggestion): array => $suggestion + ['is_builtin_suggestion' => true],
                self::SUGGESTIONS,
            );
        }

        return response()->json($payload);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/void-reasons',
        summary: 'Create a void reason',
        tags: ['VoidReasons'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['stock_effect'],
                properties: [
                    new OA\Property(property: 'ja', type: 'object', nullable: true),
                    new OA\Property(property: 'en', type: 'object', nullable: true),
                    new OA\Property(property: 'vi', type: 'object', nullable: true),
                    new OA\Property(property: 'stock_effect', type: 'string', enum: ['waste', 'restock', 'none']),
                    new OA\Property(property: 'requires_note', type: 'boolean', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean', nullable: true),
                    new OA\Property(property: 'sort_order', type: 'integer', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Void reason created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function store(VoidReasonStoreRequest $request): JsonResponse
    {
        $this->authorize('create', VoidReason::class);

        $data = $request->validated();
        $data['organization_id'] = $this->getOrganizationId();
        // brand_id đến từ {brandSlug} trên URL (ResolveBrandFromSlug), không bao
        // giờ từ client — nên nó là THAM SỐ, không phải một khoá trong $data (#1666).
        $voidReason = app(VoidReasonService::class)->create(
            (string) $request->attributes->get('brand_id'),
            $data,
        );

        return (new VoidReasonResource($voidReason->load('translations')))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/api/v1/hq/{brandSlug}/void-reasons/{voidReason}',
        summary: 'Update a void reason',
        description: 'Partial update. Soft deactivation = {is_active: false}; there is no delete endpoint.',
        tags: ['VoidReasons'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'voidReason', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(VoidReasonUpdateRequest $request, VoidReason $voidReason): VoidReasonResource
    {
        $this->authorizeOrganization($voidReason);
        // 404 (not 403) when the row belongs to a sibling brand of the same
        // org — do not leak the existence of another brand's rows.
        $this->authorizeBrand($voidReason);
        $this->authorize('update', $voidReason);

        $voidReason->update($request->validated());

        return new VoidReasonResource($voidReason->load('translations'));
    }
}
