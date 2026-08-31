<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\DenominationResource;
use App\Models\Denomination;
use App\Services\Omnify\DenominationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per-shop CRUD for cash denominations. Two visibility tiers:
 *
 *   - Global (organization_id NULL): system seed, every org sees them,
 *     no one can edit/delete via this controller (returned read-only).
 *   - Org-scoped (organization_id = shop's org): editable by shop admin,
 *     overlays the global set in pos-web shift open/close screens.
 *
 * Auth: belongs-to-org checked via `organization_id` on every mutation.
 * Scope: queries always filter by request's `organization_id` attribute
 * (set by ResolveShopFromSlug middleware).
 */
class DenominationController extends Controller
{
    public function __construct(private readonly DenominationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        $currency = strtoupper((string) $request->query('currency', ''));

        $query = Denomination::query()
            ->where('is_active', true)
            ->where(function ($q) use ($orgId) {
                $q->whereNull('organization_id')
                    ->orWhere('organization_id', $orgId);
            });

        if ($currency !== '') {
            $query->where('currency_code', $currency);
        }

        $rows = $query
            ->orderBy('currency_code')
            ->orderByRaw("CASE kind WHEN 'note' THEN 0 WHEN 'coin' THEN 1 ELSE 2 END")
            ->orderByDesc('value')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => DenominationResource::collection($rows)->resolve($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $orgId = $request->attributes->get('organization_id');
        if (! $orgId) {
            abort(400, 'Organization context not resolved.');
        }

        $data = $request->validate([
            'currency_code' => ['required', 'string', 'size:3'],
            'value' => ['required', 'numeric', 'min:0.0001'],
            'kind' => ['required', Rule::in(['note', 'coin'])],
            'label' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['currency_code'] = strtoupper($data['currency_code']);
        $data['organization_id'] = $orgId;
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['is_active'] = $data['is_active'] ?? true;

        $denom = $this->service->create($data);

        return response()->json([
            'data' => (new DenominationResource($denom))->resolve($request),
        ], 201);
    }

    public function update(Request $request, Denomination $denomination): JsonResponse
    {
        $this->assertEditable($request, $denomination);

        $data = $request->validate([
            'value' => ['sometimes', 'numeric', 'min:0.0001'],
            'kind' => ['sometimes', Rule::in(['note', 'coin'])],
            'label' => ['sometimes', 'nullable', 'string', 'max:64'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $denom = $this->service->update($denomination, $data);

        return response()->json([
            'data' => (new DenominationResource($denom))->resolve($request),
        ]);
    }

    public function destroy(Request $request, Denomination $denomination): JsonResponse
    {
        $this->assertEditable($request, $denomination);

        $this->service->delete($denomination);

        return response()->json(['data' => null], 204);
    }

    private function assertEditable(Request $request, Denomination $denomination): void
    {
        $orgId = $request->attributes->get('organization_id');

        if ($denomination->organization_id === null) {
            abort(response()->json([
                'message' => 'Global denominations cannot be edited; create an org-scoped override instead.',
                'code' => 'DENOMINATION_GLOBAL_READONLY',
            ], 403));
        }

        if ($denomination->organization_id !== $orgId) {
            abort(404);
        }
    }
}
