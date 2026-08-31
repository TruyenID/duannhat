<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\TillTenderTypeResource;
use App\Models\Branch;
use App\Models\TillTenderCategory;
use App\Models\TillTenderType;
use App\Services\Omnify\TillTenderTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-shop CRUD for cashier-settlement tender types.
 *
 * Two visibility tiers, same shape as DenominationController:
 *   - Org-wide (branch_id NULL): seeded by TillTenderTypeSeeder per org,
 *     applies to all branches of that org. READ-ONLY from this admin
 *     surface — 403 on any mutation.
 *   - Branch-scoped (branch_id = current shop): editable by shop admin,
 *     overlays the org-wide set in pos-web close-page reconciliation.
 *
 * Category is validated against the `till_tender_categories` table — the
 * 3 seeded system rows (card/qr/emoney) plus any custom rows the shop
 * manager creates from the Categories admin surface. Custom categories
 * have no implicit `category_expected` mapping; reconcile() returns 0 for
 * them, which is fine for "manual reconcile" buckets (vouchers, loyalty
 * points, …) where the staff just keys in the gross/cancel totals.
 *
 * `is_expected_anchor` is intentionally hidden from this admin surface:
 * flipping it would change which tenders get per-row vs category-level
 * expected amounts, which is a math contract decision the seed sets, not
 * a per-shop knob.
 */
class TillTenderTypeController extends Controller
{
    public function __construct(private readonly TillTenderTypeService $service) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');
        $orgId = $request->attributes->get('organization_id');

        $rows = TillTenderType::query()
            ->where('is_active', true)
            ->where('organization_id', $orgId)
            ->where(function ($q) use ($shop) {
                $q->whereNull('branch_id')->orWhere('branch_id', $shop->id);
            })
            ->orderBy('sort_order')
            ->orderBy('tender_key')
            ->get();

        return response()->json([
            'data' => TillTenderTypeResource::collection($rows)->resolve($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');
        $orgId = $request->attributes->get('organization_id');
        if (! $orgId) {
            abort(400, 'Organization context not resolved.');
        }

        $data = $request->validate([
            'tender_key' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:50'],
            'payment_method_code' => ['nullable', 'string', 'max:50'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $this->assertCategoryExists($data['category'], $orgId, $shop->id);

        $tenderKey = strtolower($data['tender_key']);

        // Reject if this branch (or its org) already has the same tender_key
        // — uniqueness is per (org_id, branch_id, tender_key) at the schema
        // level, but a friendlier 422 saves a confusing SQLSTATE 23000.
        $clash = TillTenderType::query()
            ->where('organization_id', $orgId)
            ->where('tender_key', $tenderKey)
            ->where(function ($q) use ($shop) {
                $q->whereNull('branch_id')->orWhere('branch_id', $shop->id);
            })
            ->exists();

        if ($clash) {
            abort(response()->json([
                'message' => "A tender with key '{$tenderKey}' already exists for this shop or its org.",
                'code' => 'TENDER_KEY_TAKEN',
            ], 422));
        }

        $row = $this->service->create([
            'tender_key' => $tenderKey,
            'name' => $data['name'],
            'category' => $data['category'],
            'parent_tender_key' => null,
            'currency_code' => strtoupper($data['currency_code'] ?? 'JPY'),
            'payment_method_code' => $data['payment_method_code'] ?? null,
            'is_expected_anchor' => false,
            'requires_terminal_total' => false,
            'sort_order' => $data['sort_order'] ?? 100,
            'is_active' => $data['is_active'] ?? true,
            'organization_id' => $orgId,
            'branch_id' => $shop->id,
        ]);

        return response()->json([
            'data' => (new TillTenderTypeResource($row))->resolve($request),
        ], 201);
    }

    public function update(Request $request, TillTenderType $tenderType): JsonResponse
    {
        $this->assertEditable($request, $tenderType);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:50'],
            'payment_method_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['category'])) {
            /** @var Branch $shop */
            $shop = $request->attributes->get('shop');
            $orgId = $request->attributes->get('organization_id');
            $this->assertCategoryExists($data['category'], $orgId, $shop->id);
        }

        if (isset($data['currency_code'])) {
            $data['currency_code'] = strtoupper($data['currency_code']);
        }

        $row = $this->service->update($tenderType, $data);

        return response()->json([
            'data' => (new TillTenderTypeResource($row))->resolve($request),
        ]);
    }

    public function destroy(Request $request, TillTenderType $tenderType): JsonResponse
    {
        $this->assertEditable($request, $tenderType);

        $this->service->delete($tenderType);

        return response()->json(['data' => null], 204);
    }

    /**
     * Gate any mutation to shop-scoped rows. Org-wide seeded rows are
     * read-only here; cross-org rows are 404 (hides existence).
     */
    private function assertEditable(Request $request, TillTenderType $tenderType): void
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');
        $orgId = $request->attributes->get('organization_id');

        if ($tenderType->organization_id !== $orgId) {
            abort(404);
        }

        if ($tenderType->branch_id === null) {
            abort(response()->json([
                'message' => 'Org-wide tender types are seeded and read-only. Create a shop-scoped override instead.',
                'code' => 'TENDER_GLOBAL_READONLY',
            ], 403));
        }

        if ($tenderType->branch_id !== $shop->id) {
            abort(404);
        }
    }

    /**
     * Ensure the submitted category key exists for this shop's scope —
     * either as an org-wide system row or a row this shop owns. Without
     * this guard staff could plant an arbitrary string into
     * `till_tender_types.category` and the row would render under no card
     * on the close screen.
     */
    private function assertCategoryExists(string $key, string $orgId, string $shopId): void
    {
        $exists = TillTenderCategory::query()
            ->where('organization_id', $orgId)
            ->where('key', $key)
            ->where('is_active', true)
            ->where(function ($q) use ($shopId) {
                $q->whereNull('branch_id')->orWhere('branch_id', $shopId);
            })
            ->exists();

        if (! $exists) {
            abort(response()->json([
                'message' => "Tender category '{$key}' does not exist for this shop. Create it under Settings → Tender Categories first.",
                'code' => 'TENDER_CATEGORY_UNKNOWN',
            ], 422));
        }
    }
}
