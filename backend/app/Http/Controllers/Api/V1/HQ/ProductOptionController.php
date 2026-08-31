<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\ProductOptionExpandRequest;
use App\Http\Requests\ProductOptionStoreRequest;
use App\Http\Requests\ProductOptionSyncValuesRequest;
use App\Http\Requests\ProductOptionUpdateRequest;
use App\Http\Resources\ProductOptionResource;
use App\Http\Resources\ProductSkuResource;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Services\DomainMutation\LocalizedText;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\SupportedLocale;
use App\Services\Product\Commands\CreateProductOptionCommand;
use App\Services\Product\Commands\ExpandProductOptionCommand;
use App\Services\Product\Commands\ProductOptionLifecycleCommand;
use App\Services\Product\Commands\ReviseProductOptionCommand;
use App\Services\Product\Commands\SyncProductOptionValuesCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\Enums\ProductOptionLifecycleAction;
use App\Services\Product\ProductQueryService;
use App\Services\Product\ValueObjects\ProductOptionPayload;
use App\Services\Product\ValueObjects\ProductOptionValuePayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class ProductOptionController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly ProductMutationFacade $mutations,
        private readonly ProductQueryService $queries,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $product = $this->resolveProduct($request);
        $this->authorizeOrganization($product);
        $this->authorize('viewAny', ProductOption::class);

        $options = $this->queries->options($this->getOrganizationId(), $product->brand_id, $product->id);

        return ProductOptionResource::collection($options);
    }

    public function store(ProductOptionStoreRequest $request): JsonResponse
    {
        $product = $this->resolveProduct($request);
        $this->authorizeOrganization($product);
        $this->authorize('create', ProductOption::class);

        $optionId = $this->queries->trashedOptionByKey($this->getOrganizationId(), $product->brand_id, $product->id, $request->validated('key'))?->id
            ?? (string) Str::uuid();
        $payload = $this->payload($request->validated(), $optionId);
        $result = $this->mutations->createOption(new CreateProductOptionCommand($this->context($request, "option:create:{$optionId}"), $product->id, $product->brand_id, $payload, $payload->fingerprint()));
        $option = $this->queries->option($this->getOrganizationId(), $product->brand_id, $result->aggregateId);

        return (new ProductOptionResource($option))
            ->response()
            ->setStatusCode($result->changed ? 201 : 200);
    }

    public function show(Request $request): ProductOptionResource
    {
        $option = $this->resolveOption($request);
        $this->authorizeOrganization($option->product);
        $this->authorize('view', $option);

        return new ProductOptionResource(
            $this->queries->option($this->getOrganizationId(), $option->product->brand_id, $option->id)
        );
    }

    public function update(ProductOptionUpdateRequest $request): ProductOptionResource
    {
        $option = $this->resolveOption($request);
        $this->authorizeOrganization($option->product);
        $this->authorize('update', $option);

        $payload = $this->payload($request->validated(), $option->id, $option);
        $this->mutations->reviseOption(new ReviseProductOptionCommand($this->context($request, "option:revise:{$option->id}", $option), $option->product->brand_id, $payload, $payload->fingerprint()));
        $option = $this->queries->option($this->getOrganizationId(), $option->product->brand_id, $option->id);

        return new ProductOptionResource($option);
    }

    #[OA\Post(
        path: '/api/v1/hq/{brandSlug}/products/{product}/options/expand',
        summary: 'Expand product with a new option',
        description: 'Adds a new option (with values) to an existing product, assigns a default value to all existing SKUs for the new option position, and optionally generates missing SKU combinations. Existing SKU UUIDs are preserved.',
        tags: ['ProductOptions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'brandSlug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['key', 'name', 'position', 'values', 'default_value_index'],
                properties: [
                    new OA\Property(property: 'key', type: 'string', maxLength: 60, example: 'color', description: 'Lowercase key (a-z, 0-9, _)'),
                    new OA\Property(property: 'name', type: 'string', maxLength: 120, example: 'Color', description: 'Display name'),
                    new OA\Property(property: 'position', type: 'integer', enum: [1, 2, 3], description: 'Option slot (must be unoccupied)'),
                    new OA\Property(property: 'is_active', type: 'boolean', nullable: true, description: 'Defaults to true'),
                    new OA\Property(
                        property: 'values',
                        type: 'array',
                        items: new OA\Items(
                            required: ['value', 'label'],
                            properties: [
                                new OA\Property(property: 'value', type: 'string', maxLength: 60, example: 'red'),
                                new OA\Property(property: 'label', type: 'string', maxLength: 120, example: 'Red'),
                            ],
                            type: 'object',
                        ),
                        minItems: 1,
                    ),
                    new OA\Property(property: 'default_value_index', type: 'integer', minimum: 0, example: 0, description: 'Index into the values array — the value assigned to all existing SKUs'),
                    new OA\Property(property: 'generate_combinations', type: 'boolean', nullable: true, description: 'Auto-generate missing SKU combinations (default true)'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Option created, existing SKUs updated, new combinations generated',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'option', ref: '#/components/schemas/ProductOption'),
                        new OA\Property(property: 'updated_skus', type: 'integer', description: 'Number of existing SKUs updated with the default value'),
                        new OA\Property(property: 'created_skus', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProductSku')),
                    ], type: 'object'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 404, description: 'Product not found'),
            new OA\Response(response: 422, description: 'Validation failed (position taken, duplicate key, invalid default_value_index)', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function expand(ProductOptionExpandRequest $request): JsonResponse
    {
        $product = $this->resolveProduct($request);
        $this->authorizeOrganization($product);
        $this->authorize('create', ProductOption::class);

        $data = $request->validated();
        $optionId = (string) Str::uuid();
        $payload = $this->payload($data, $optionId, null, true);
        $defaultPosition = $data['default_value_index'] + 1;
        $defaultValueId = collect($payload->values)->firstWhere('position', $defaultPosition)->valueId;
        $result = $this->mutations->expandOption(new ExpandProductOptionCommand($this->context($request, "option:expand:{$optionId}"), $product->id, $product->brand_id, $payload, $defaultValueId, $data['generate_combinations'] ?? true, $payload->fingerprint()));
        $option = $this->queries->option($this->getOrganizationId(), $product->brand_id, $result->optionId);
        $created = $this->queries->skusByIds($this->getOrganizationId(), $product->brand_id, $result->createdSkuIds);

        return response()->json([
            'data' => [
                'option' => new ProductOptionResource($option),
                'updated_skus' => $result->updatedSkuCount,
                'created_skus' => ProductSkuResource::collection($created),
            ],
        ], 201);
    }

    /**
     * Batch sync values for an option: rename option name, upsert value
     * labels, insert new values, remove dropped values — all in one
     * transaction. Replaces the per-row PUT/POST/DELETE storm the FE used
     * to make every blur.
     */
    public function syncValues(ProductOptionSyncValuesRequest $request): JsonResponse
    {
        $option = $this->resolveOption($request);
        $this->authorizeOrganization($option->product);
        $this->authorize('update', $option);

        $validated = $request->validated();

        // #2488 — optimistic concurrency. `values` là danh sách TOÀN QUYỀN
        // (thiếu id nào là xoá id đó), nên một form hydrate từ dữ liệu cũ sẽ
        // xoá giá trị nó chưa từng nhìn thấy — hai tab admin cùng mở là kịch
        // bản thật trên production. Client khai tập id nó đã thấy; lệch với
        // tập đang sống thì 409 để FE nạp lại. KHÔNG merge hộ: người dùng
        // phải nhìn thấy dữ liệu mới trước khi quyết lưu gì.
        //
        // Rào nằm ở controller chứ không luồn vào ProductOptionPayload: DTO đó
        // là canonical payload có fingerprint, thêm trường là đổi hợp đồng
        // mutation cho một tiền-điều-kiện thuần đọc. Cửa sổ TOCTOU còn lại là
        // mili-giây giữa check và transaction — cuộc đua nó chắn là cấp con
        // người (hai tab, cách nhau nhiều phút), không phải cấp ghi song song.
        if (($validated['known_value_ids'] ?? null) !== null) {
            $known = collect($validated['known_value_ids'])->map(fn ($id) => (string) $id)->sort()->values()->all();
            $alive = $option->values()->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
            if ($known !== $alive) {
                return response()->json([
                    'message' => 'Option values changed since this form was opened — reload before saving.',
                    'error' => 'OPTION_VALUES_CHANGED',
                ], 409);
            }
        }

        $payload = $this->payload($validated, $option->id, $option, true);
        $result = $this->mutations->syncOptionValues(new SyncProductOptionValuesCommand($this->context($request, "option:sync:{$option->id}", $option), $option->product->brand_id, $payload, $payload->fingerprint()));
        $option = $this->queries->option($this->getOrganizationId(), $option->product->brand_id, $result->optionId);
        $created = $this->queries->skusByIds($this->getOrganizationId(), $option->product->brand_id, $result->createdSkuIds);

        return response()->json([
            'data' => [
                'option' => new ProductOptionResource($option),
                'created_skus' => ProductSkuResource::collection($created),
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $option = $this->resolveOption($request);
        $this->authorizeOrganization($option->product);
        $this->authorize('delete', $option);

        $this->mutations->archiveOption(new ProductOptionLifecycleCommand($this->context($request, "option:archive:{$option->id}", $option), $option->id, $option->product->brand_id, ProductOptionLifecycleAction::Archive));

        return response()->json(null, 204);
    }

    private function resolveProduct(Request $request): Product
    {
        $product = $request->route('product');

        return $this->queries->product($this->getOrganizationId(), $request->attributes->get('brand_id'), $product instanceof Product ? $product->id : $product);
    }

    private function resolveOption(Request $request): ProductOption
    {
        $option = $request->route('option');

        if ($option instanceof ProductOption) {
            return $option->loadMissing(['product', 'values']);
        }

        return $this->queries->routableOption($option);
    }

    private function payload(array $data, string $optionId, ?ProductOption $current = null, bool $withValues = false): ProductOptionPayload
    {
        $translations = [];
        $cleared = [];
        foreach (SupportedLocale::cases() as $locale) {
            $row = $data[$locale->value] ?? null;
            if (is_array($row) && trim((string) ($row['name'] ?? '')) !== '') {
                $translations[] = new LocalizedText($locale, $row['name']);
            } elseif (array_key_exists($locale->value, $data)) {
                $cleared[] = $locale->value;
            }
        }
        $values = [];
        if ($withValues) {
            foreach ($data['values'] ?? [] as $index => $row) {
                $existingSlug = isset($row['id'])
                    ? ProductOptionValue::withTrashed()->where('option_id', $optionId)->whereKey($row['id'])->value('value')
                    : null;
                $values[] = new ProductOptionValuePayload($row['id'] ?? (string) Str::uuid(), $row['label'], $row['value'] ?? $existingSlug ?? Str::slug($row['label'], '_'), $index + 1);
            }
        }

        return new ProductOptionPayload($optionId, $data['name'] ?? $current?->name, $values, $data['key'] ?? $current?->key, (int) ($data['position'] ?? $current?->position), (bool) ($data['is_active'] ?? $current?->is_active ?? true), $translations, $cleared);
    }

    private function context(Request $request, string $key, ?ProductOption $current = null): MutationContext
    {
        return new MutationContext($this->getOrganizationId(), $request->user()?->id, $request->header('X-Correlation-ID') ?: (string) Str::uuid(), $request->header('Idempotency-Key') ?: $key);
    }
}
