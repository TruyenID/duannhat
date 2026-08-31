<?php

namespace App\Http\Controllers\Api\V1\HQ;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Http\Requests\ProductOptionValueStoreRequest;
use App\Http\Requests\ProductOptionValueUpdateRequest;
use App\Http\Resources\ProductOptionValueResource;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Services\DomainMutation\LocalizedText;
use App\Services\DomainMutation\MutationContext;
use App\Services\DomainMutation\SupportedLocale;
use App\Services\Product\Commands\CreateProductOptionValueCommand;
use App\Services\Product\Commands\ProductOptionValueLifecycleCommand;
use App\Services\Product\Commands\ReviseProductOptionValueCommand;
use App\Services\Product\Contracts\ProductMutationFacade;
use App\Services\Product\Enums\ProductOptionValueLifecycleAction;
use App\Services\Product\ProductQueryService;
use App\Services\Product\ValueObjects\ProductOptionValuePayload;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class ProductOptionValueController extends Controller
{
    use AuthorizesRequests;
    use HasOrganizationContext;

    public function __construct(
        private readonly ProductMutationFacade $mutations,
        private readonly ProductQueryService $queries,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $option = $this->resolveOption($request);
        $this->authorizeOrganization($option->product);
        $this->authorize('viewAny', ProductOptionValue::class);

        $values = $this->queries->optionValues($this->getOrganizationId(), $option->product->brand_id, $option->id);

        return ProductOptionValueResource::collection($values);
    }

    public function store(ProductOptionValueStoreRequest $request): JsonResponse
    {
        $option = $this->resolveOption($request);
        $this->authorizeOrganization($option->product);
        $this->authorize('create', ProductOptionValue::class);

        $valueId = $this->queries->trashedOptionValueBySlug($this->getOrganizationId(), $option->product->brand_id, $option->id, $request->validated('value'))?->id
            ?? (string) Str::uuid();
        $payload = $this->payload($request->validated(), $valueId, null, $option);
        $result = $this->mutations->createOptionValue(new CreateProductOptionValueCommand($this->context($request, "option-value:create:{$valueId}"), $option->id, $option->product->brand_id, $payload, $payload->fingerprint()));
        $value = $this->queries->optionValue($this->getOrganizationId(), $option->product->brand_id, $result->aggregateId);

        return (new ProductOptionValueResource($value))
            ->response()
            ->setStatusCode($result->changed ? 201 : 200);
    }

    public function show(Request $request): ProductOptionValueResource
    {
        $value = $this->resolveValue($request);
        $this->authorizeOrganization($value->option->product);
        $this->authorize('view', $value);

        return new ProductOptionValueResource(
            $this->queries->optionValue($this->getOrganizationId(), $value->option->product->brand_id, $value->id)
        );
    }

    public function update(ProductOptionValueUpdateRequest $request): ProductOptionValueResource
    {
        $value = $this->resolveValue($request);
        $this->authorizeOrganization($value->option->product);
        $this->authorize('update', $value);

        $payload = $this->payload($request->validated(), $value->id, $value, $value->option);
        $this->mutations->reviseOptionValue(new ReviseProductOptionValueCommand($this->context($request, "option-value:revise:{$value->id}"), $value->option->product->brand_id, $payload, $payload->fingerprint()));
        $value = $this->queries->optionValue($this->getOrganizationId(), $value->option->product->brand_id, $value->id);

        return new ProductOptionValueResource($value);
    }

    public function destroy(Request $request): JsonResponse
    {
        $value = $this->resolveValue($request);
        $this->authorizeOrganization($value->option->product);
        $this->authorize('delete', $value);

        $action = $request->boolean('force') ? ProductOptionValueLifecycleAction::ForceArchive : ProductOptionValueLifecycleAction::Archive;
        $this->mutations->archiveOptionValue(new ProductOptionValueLifecycleCommand($this->context($request, "option-value:{$action->value}:{$value->id}"), $value->id, $value->option->product->brand_id, $action));

        return response()->json(null, 204);
    }

    private function resolveOption(Request $request): ProductOption
    {
        $option = $request->route('option');

        if ($option instanceof ProductOption) {
            return $option->loadMissing('product');
        }

        return $this->queries->routableOption($option);
    }

    private function resolveValue(Request $request): ProductOptionValue
    {
        $value = $request->route('value');

        if ($value instanceof ProductOptionValue) {
            return $value->loadMissing('option.product');
        }

        return $this->queries->routableOptionValue($value);
    }

    private function payload(array $data, string $id, ?ProductOptionValue $current, ProductOption $option): ProductOptionValuePayload
    {
        $translations = [];
        $cleared = [];
        foreach (SupportedLocale::cases() as $locale) {
            $row = $data[$locale->value] ?? null;
            if (is_array($row) && trim((string) ($row['label'] ?? '')) !== '') {
                $translations[] = new LocalizedText($locale, $row['label']);
            } elseif (array_key_exists($locale->value, $data)) {
                $cleared[] = $locale->value;
            }
        }
        $position = (int) ($data['position'] ?? $current?->position ?? ($option->values()->max('position') + 1));

        return new ProductOptionValuePayload($id, $data['label'] ?? $current?->label, $data['value'] ?? $current?->value, $position, (bool) ($data['is_active'] ?? $current?->is_active ?? true), $translations, $cleared);
    }

    private function context(Request $request, string $key): MutationContext
    {
        return new MutationContext($this->getOrganizationId(), $request->user()?->id, $request->header('X-Correlation-ID') ?: (string) Str::uuid(), $request->header('Idempotency-Key') ?: $key);
    }
}
