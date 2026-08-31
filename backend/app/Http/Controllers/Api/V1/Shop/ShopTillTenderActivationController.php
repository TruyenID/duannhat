<?php

namespace App\Http\Controllers\Api\V1\Shop;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\TillTenderTypeResource;
use App\Models\Branch;
use App\Models\TillTenderType;
use App\Services\Omnify\TillTenderTypeService;
use App\Services\Till\TenderTypeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #1156 — per-branch tender activation.
 *
 *   GET   /shops/{shopSlug}/till/tender-types              — EFFECTIVE list
 *   PATCH /shops/{shopSlug}/till/tender-types/{tenderKey}  — branch override
 *
 * Distinct from the raw-row CRUD surface at /shops/{shopSlug}/tender-types
 * (TillTenderTypeController): that one manages vocabulary ROWS by id; this
 * one answers "which tenders does THIS branch settle with?" and flips a
 * single per-branch switch. The PATCH materializes (or updates) a
 * branch-scoped override row for the given tender_key — org-wide seeded
 * rows are never mutated, so other branches are untouched.
 *
 * Authorization mirrors TillTenderTypeController: ResolveShopFromSlug has
 * already 403'd users without access to this shop and bound shop/org
 * context onto request attributes.
 */
class ShopTillTenderActivationController extends Controller
{
    public function __construct(
        private readonly TenderTypeResolver $resolver,
        private readonly TillTenderTypeService $service,
    ) {}

    /**
     * Effective tender list for the shop's branch — org vocabulary with this
     * branch's overrides applied (see TenderTypeResolver for the matrix).
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');
        $orgId = $request->attributes->get('organization_id');
        if (! $orgId) {
            abort(400, 'Organization context not resolved.');
        }

        $rows = $this->resolver->effectiveForBranch((string) $orgId, (string) $shop->id);

        return response()->json([
            'data' => TillTenderTypeResource::collection($rows)->resolve($request),
        ]);
    }

    /**
     * Flip a tender on/off for this branch.
     *
     * Creates the branch override row on first flip (copying the org row's
     * fields, translations included, with branch_id + the submitted
     * is_active); subsequent flips update the same row in place. Also
     * accepts a branch-only custom tender's key, in which case its own
     * is_active is toggled directly.
     */
    public function update(Request $request, string $tenderKey): JsonResponse
    {
        /** @var Branch $shop */
        $shop = $request->attributes->get('shop');
        $orgId = $request->attributes->get('organization_id');
        if (! $orgId) {
            abort(400, 'Organization context not resolved.');
        }

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        $isActive = (bool) $data['is_active'];

        $branchRow = TillTenderType::query()
            ->where('organization_id', $orgId)
            ->where('branch_id', $shop->id)
            ->where('tender_key', $tenderKey)
            ->first();

        if ($branchRow) {
            $row = $this->service->update($branchRow, ['is_active' => $isActive]);

            return response()->json([
                'data' => (new TillTenderTypeResource($row))->resolve($request),
            ]);
        }

        $orgRow = TillTenderType::query()
            ->with('translations')
            ->where('organization_id', $orgId)
            ->whereNull('branch_id')
            ->where('tender_key', $tenderKey)
            ->first();

        if (! $orgRow) {
            abort(response()->json([
                'message' => "Tender '{$tenderKey}' does not exist in this organization's vocabulary.",
                'code' => 'TENDER_KEY_UNKNOWN',
            ], 404));
        }

        $payload = [
            'tender_key' => $orgRow->tender_key,
            'category' => $orgRow->category instanceof \BackedEnum
                ? $orgRow->category->value
                : $orgRow->category,
            'parent_tender_key' => $orgRow->parent_tender_key,
            'currency_code' => $orgRow->currency_code,
            'payment_method_code' => $orgRow->payment_method_code,
            'is_expected_anchor' => (bool) $orgRow->is_expected_anchor,
            'requires_terminal_total' => (bool) $orgRow->requires_terminal_total,
            'sort_order' => (int) $orgRow->sort_order,
            'is_active' => $isActive,
            'organization_id' => (string) $orgId,
            'branch_id' => $shop->id,
        ];

        // Carry the translated names onto the override so the close screen
        // renders identically in ja/en/vi. Falls back to the raw column when
        // the org row was created without translation rows (tests, imports).
        $hasTranslations = false;
        foreach ($orgRow->translations as $translation) {
            if ($translation->name !== null) {
                $payload['name:'.$translation->locale] = $translation->name;
                $hasTranslations = true;
            }
        }
        if (! $hasTranslations) {
            $payload['name'] = $orgRow->name;
        }

        $row = $this->service->create($payload);

        return response()->json([
            'data' => (new TillTenderTypeResource($row))->resolve($request),
        ], 201);
    }
}
