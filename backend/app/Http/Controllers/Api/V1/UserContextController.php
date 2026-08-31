<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Services\Iam\UserWorkspaceAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class UserContextController extends Controller
{
    public function __construct(private readonly UserWorkspaceAccess $access) {}

    /**
     * Hard cap on the per_page query parameter so a misbehaving client cannot
     * ask for "give me everything" and accidentally re-introduce the unbounded
     * fetch this controller was rebuilt to avoid.
     */
    private const MAX_PER_PAGE = 100;

    private const DEFAULT_PER_PAGE = 25;

    #[OA\Get(
        path: '/api/v1/me/context',
        summary: 'Get current user context',
        description: 'Returns the authenticated user profile and the count of brands/shops they can access. Use /api/v1/me/brands and /api/v1/me/shops to fetch the actual lists (paginated).',
        tags: ['User Context'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'User profile + counts',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'user',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                                new OA\Property(property: 'name', type: 'string'),
                                new OA\Property(property: 'email', type: 'string', format: 'email'),
                                new OA\Property(property: 'locale', type: 'string', nullable: true),
                                new OA\Property(property: 'timezone', type: 'string', nullable: true),
                            ]
                        ),
                        new OA\Property(property: 'brand_count', type: 'integer'),
                        new OA\Property(property: 'shop_count', type: 'integer'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function context(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $this->formatUser($user),
            'brand_count' => $this->access->brands($user)->where('is_active', true)->count(),
            'shop_count' => $this->access->branches($user)->where('is_active', true)->count(),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/me/brands',
        summary: 'List brands the current user can access (paginated)',
        description: 'Paginated brand list scoped to the user organization, with optional name/slug search. Replaces the unbounded brands array that /me/context used to return.',
        tags: ['User Context'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated brands'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function brands(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $this->access->brands($user)
            ->where('is_active', true)
            ->select(['id', 'name', 'slug', 'description', 'logo_url'])
            ->orderBy('name');

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($this->resolvePerPage($request));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/me/shops',
        summary: 'List shops the current user can access (paginated)',
        description: 'Paginated shop list scoped to the user organization, with optional name/slug search, brand_slug filter, is_active filter, and with_trashed support.',
        tags: ['User Context'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'brand_slug', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', description: 'Omit to return all, true for active only, false for inactive only')),
            new OA\Parameter(name: 'with_trashed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: false, description: 'Include soft-deleted shops')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated shops'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function shops(Request $request): JsonResponse
    {
        $user = $request->user();
        $withTrashed = $request->boolean('with_trashed', false);

        $query = $this->access->branches($user)
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->select(['id', 'name', 'slug', 'console_brand_id', 'is_active', 'deleted_at', 'updated_at'])
            ->orderBy('name');

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($brandSlug = trim((string) $request->input('brand_slug', ''))) {
            $brand = $this->access->brands($user)
                ->where('slug', $brandSlug)
                ->first();

            if (! $brand) {
                return response()->json(['data' => [], 'meta' => $this->emptyMeta()]);
            }

            $query->where('console_brand_id', $brand->console_brand_id);
        }

        $paginator = $query->paginate($this->resolvePerPage($request));

        // Resolve brand names in a single query instead of N+1 like the old endpoint did.
        $brandIds = collect($paginator->items())->pluck('console_brand_id')->filter()->unique()->all();
        $brandsByConsoleId = Brand::whereIn('console_brand_id', $brandIds)
            ->pluck('name', 'console_brand_id');

        $items = collect($paginator->items())->map(fn ($shop) => [
            'id' => $shop->id,
            'name' => $shop->name,
            'slug' => $shop->slug,
            'is_active' => $shop->is_active,
            'deleted_at' => $shop->deleted_at?->toISOString(),
            'updated_at' => $shop->updated_at?->toISOString(),
            'brand_name' => $brandsByConsoleId[$shop->console_brand_id] ?? null,
        ])->all();

        return response()->json([
            'data' => $items,
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    private function resolvePerPage(Request $request): int
    {
        $perPage = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);

        if ($perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    /**
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * @return array{current_page: int, last_page: int, per_page: int, total: int}
     */
    private function emptyMeta(): array
    {
        return [
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => self::DEFAULT_PER_PAGE,
            'total' => 0,
        ];
    }

    /**
     * @return array{id: string, name: string, email: string, locale: string|null, timezone: string|null}
     */
    private function formatUser($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'timezone' => $user->timezone,
        ];
    }
}
