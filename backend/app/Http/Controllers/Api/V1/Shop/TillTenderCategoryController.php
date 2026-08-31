<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\TillTenderCategory;
use App\Models\TillTenderType;
use App\Services\Till\TillTenderCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-shop CRUD for till tender categories — the groupings (Thẻ / QR /
 * Tiền điện tử / …) under which TillTenderType rows are listed on the
 * shift-close screen and the shop-settings tender-types tab.
 *
 * Visibility tiers mirror TillTenderTypeController:
 *   - Org-wide system rows (branch_id NULL, is_system=1): seeded once per
 *     org with the legacy 3 keys (card / qr / emoney); READ-ONLY here.
 *   - Branch-scoped custom rows (branch_id = shop.id, is_system=0): created
 *     by the shop manager, fully editable + deletable. Deleting is blocked
 *     when any TillTenderType still references the key — otherwise the
 *     orphan tenders would render under no card on the close screen.
 *
 * `key` is the slug stored on `till_tender_types.category`. Lowercase
 * `[a-z0-9_]` only so it survives URL params + JSON encoding without
 * escapes. Editing the key on an existing category is intentionally not
 * supported — would require a rename cascade across tender_types +
 * historical till_settlement_tender_details snapshots. Delete + recreate
 * if you really need a rename.
 */
class TillTenderCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');
        $orgId = $request->attributes->get('organization_id');

        $rows = TillTenderCategory::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->where(function ($q) use ($shop) {
                $q->whereNull('branch_id')->orWhere('branch_id', $shop->id);
            })
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (TillTenderCategory $r) => $this->shape($r))->all(),
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
            'key' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // Phép kiểm trùng `key` phải phủ CẢ nhóm dùng chung toàn tổ chức mà chi
        // nhánh nhìn thấy, không chỉ nhóm của chính nó — luật đó ở service (#1666).
        [$row, $key] = app(TillTenderCategoryService::class)
            ->createUnlessKeyTaken($orgId, $shop->id, $data);

        if ($row === null) {
            abort(response()->json([
                'message' => "A tender category with key '{$key}' already exists for this shop or its org.",
                'code' => 'TENDER_CATEGORY_KEY_TAKEN',
            ], 422));
        }

        return response()->json(['data' => $this->shape($row)], 201);
    }

    public function update(Request $request, TillTenderCategory $tenderCategory): JsonResponse
    {
        $this->assertEditable($request, $tenderCategory);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $tenderCategory->fill($data)->save();

        return response()->json(['data' => $this->shape($tenderCategory)]);
    }

    public function destroy(Request $request, TillTenderCategory $tenderCategory): JsonResponse
    {
        $this->assertEditable($request, $tenderCategory);

        // Refuse if any TillTenderType still belongs to this category in
        // the current shop's scope — orphan tenders would render
        // category-less on the close screen.
        $inUse = TillTenderType::query()
            ->where('organization_id', $tenderCategory->organization_id)
            ->where('category', $tenderCategory->key)
            ->where(function ($q) use ($tenderCategory) {
                $q->whereNull('branch_id')->orWhere('branch_id', $tenderCategory->branch_id);
            })
            ->exists();

        if ($inUse) {
            abort(response()->json([
                'message' => 'Category still has tender types attached. Move or delete them first.',
                'code' => 'TENDER_CATEGORY_IN_USE',
            ], 409));
        }

        $tenderCategory->delete();

        return response()->json(['data' => null], 204);
    }

    private function assertEditable(Request $request, TillTenderCategory $row): void
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');
        $orgId = $request->attributes->get('organization_id');

        if ($row->organization_id !== $orgId) {
            abort(404);
        }

        if ($row->is_system || $row->branch_id === null) {
            abort(response()->json([
                'message' => 'System tender categories are seeded and read-only.',
                'code' => 'TENDER_CATEGORY_SYSTEM_READONLY',
            ], 403));
        }

        if ($row->branch_id !== $shop->id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(TillTenderCategory $r): array
    {
        return [
            'id' => $r->id,
            'organization_id' => $r->organization_id,
            'branch_id' => $r->branch_id,
            'key' => $r->key,
            'name' => $r->name,
            'sort_order' => $r->sort_order,
            'is_active' => $r->is_active,
            'is_system' => $r->is_system,
        ];
    }
}
