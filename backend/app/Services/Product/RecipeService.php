<?php

namespace App\Services\Product;

use App\Contracts\Notifiable;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\Recipe;
use App\Models\User;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Services\Inventory\Contracts\MaterialDirectory;
use App\Traits\GeneratesSku;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecipeService
{
    use GeneratesSku;

    protected string $skuPrefix = 'RE';

    protected string $skuModel = Recipe::class;

    public function __construct(
        private readonly ProductSkuService $skuService,
        private readonly AllergenRollupService $rollup,
        // #962 — nguyên liệu thuộc Inventory. Công thức chỉ TRA CỨU (tồn tại /
        // brand / đơn vị đã đăng ký) và BÁO sản lượng khi được duyệt lần đầu.
        private readonly MaterialDirectory $materials,
    ) {}

    /**
     * Fields that, when changed, force an approved Recipe back to pending
     * per the two-tier re-approval rule (DESIGN Decision 4). Non-structural
     * edits (description, instructions, preparation_time, is_active) leave
     * the approval state alone.
     */
    private const STRUCTURAL_FIELDS = [
        'ingredients',
        'material_id',
        'output_quantity',
        'output_unit',
    ];

    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @param  array{organization_id: string, search?: string, is_active?: bool, material_id?: string|null, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Recipe::query()
            ->with('material:id,name,sku');

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        // Output-material filter — backs the "recipes for this material" deep
        // link from the material detail page (/recipes?material_id=...).
        $query->when($filters['material_id'] ?? null, fn ($q, $v) => $q->where('material_id', $v));

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhereTranslationLike('name', "%{$search}%");
            });
        });

        $query->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']));

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): Recipe
    {
        return Recipe::with(['material', 'skus' => fn ($q) => $q->select('id', 'product_id', 'name', 'sku', 'recipe_id')->with('product:id,name')])->findOrFail($id);
    }

    // =========================================================================
    //  Create
    // =========================================================================

    public function create(array $data): Recipe
    {
        return DB::transaction(function () use ($data) {
            if (empty($data['sku'])) {
                $data['sku'] = $this->generateUniqueSku(
                    additionalWhere: ['organization_id' => $data['organization_id']]
                );
            }

            if (! empty($data['ingredients'])) {
                $data['ingredients'] = $this->normalizeIngredients($data['ingredients']);
            }

            // Plan-022 T18 — fail-early constraints on output material + each
            // ingredient. Draft recipes are allowed to have an empty
            // ingredients list (work-in-progress), but every other shape rule
            // (brand match, self-reference, unit registered, qty > 0) applies
            // the moment the field is present.
            $this->validateIngredientsAndOutput($data);

            // Pull sku_ids off before Recipe::create() — it isn't a column.
            $skuIds = $this->extractSkuIds($data);

            $recipe = Recipe::create($data);
            $this->flushTranslations($recipe);

            if ($skuIds !== null) {
                $this->syncOutputSkus($recipe, $skuIds);
            }

            $this->rollup->recomputeForRecipe($recipe);

            return $recipe->load(['material', 'skus' => fn ($q) => $q->select('id', 'product_id', 'name', 'sku', 'recipe_id')->with('product:id,name')]);
        });
    }

    // =========================================================================
    //  Update
    // =========================================================================

    /**
     * Approval-workflow + ownership columns that the update path must never
     * write — mirror of RecipeUpdateRequest::FORBIDDEN_UPDATE_FIELDS. Approval
     * state transitions only through approve()/reject().
     */
    private const FORBIDDEN_UPDATE_FIELDS = [
        'approval_status',
        'approved_by_id',
        'approved_at',
        'rejected_by_id',
        'rejected_at',
    ];

    public function update(Recipe $recipe, array $data): Recipe
    {
        return DB::transaction(function () use ($recipe, $data) {
            // plan-040 NEW-MR-1: defence-in-depth guard for direct/import
            // callers that bypass the FormRequest. Approval state is owned by
            // approve()/reject(); a generic update may never forge it.
            foreach (self::FORBIDDEN_UPDATE_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    throw ValidationException::withMessages([
                        $field => "{$field} cannot be set through recipe update — use the approve/reject actions.",
                    ]);
                }
            }

            // plan-040 NEW-MR-2: a recipe can never be reassigned to a brand in
            // another organization. brand_id is stripped at the request layer;
            // this reconciles direct callers and rejects a cross-org brand.
            $this->assertBrandWithinOrg($recipe->organization_id, $data['brand_id'] ?? null);

            if (! empty($data['ingredients'])) {
                $data['ingredients'] = $this->normalizeIngredients($data['ingredients']);
            }

            $this->validateIngredientsAndOutput($data, $recipe);

            $structuralChanged = $this->detectStructuralChange($recipe, $data);
            $wasApproved = $recipe->getApprovalStatus() === ApprovalStatusEnum::Approved;

            $skuIds = $this->extractSkuIds($data);

            $recipe->update($data);
            $this->flushTranslations($recipe);

            if ($skuIds !== null) {
                $this->syncOutputSkus($recipe, $skuIds);
            }

            if ($structuralChanged) {
                $this->rollup->recomputeForRecipe($recipe);

                if ($wasApproved) {
                    $recipe->markAsPending();
                    $recipe->logAudit('recipe.auto_repending', [
                        'changed_fields' => $this->changedStructuralFields($recipe, $data),
                    ]);
                }
            }

            return $recipe->load(['material', 'skus' => fn ($q) => $q->select('id', 'product_id', 'name', 'sku', 'recipe_id')->with('product:id,name')]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function detectStructuralChange(Recipe $recipe, array $data): bool
    {
        foreach (self::STRUCTURAL_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $current = $recipe->getOriginal($field);
            $next = $data[$field];

            if ($field === 'ingredients') {
                $current = is_array($current) ? $current : (is_string($current) ? json_decode($current, true) : []);
            }

            if ($current != $next) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function changedStructuralFields(Recipe $recipe, array $data): array
    {
        $changed = [];

        foreach (self::STRUCTURAL_FIELDS as $field) {
            if (array_key_exists($field, $data) && $recipe->wasChanged($field)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    public function submitForApproval(Recipe $recipe): Recipe
    {
        return DB::transaction(function () use ($recipe) {
            // Lock the row for the life of the transaction so a concurrent
            // submit/approve/reject cannot read the same status, both pass
            // assertApprovalStatus, and race to write conflicting transitions.
            $recipe = $this->lockRecipe($recipe);

            $recipe->assertApprovalStatus([
                ApprovalStatusEnum::Draft,
                ApprovalStatusEnum::Rejected,
            ], 'submit for approval');

            // Plan-022 T18 — B1: recipe must have ≥ 1 ingredient before it can
            // leave draft. C1: every referenced material must still exist (not
            // soft-deleted). Re-running validateIngredientsAndOutput catches
            // drift since the last edit.
            $this->assertReadyForApproval($recipe);
            $this->validateIngredientsAndOutput(['ingredients' => $recipe->ingredients ?? []], $recipe);

            $previousStatus = $recipe->getApprovalStatus()->value;

            $recipe->markAsPending();
            $recipe->logAudit('recipe.submitted_for_approval', [
                'previous_status' => $previousStatus,
            ]);

            return $recipe->load(['material', 'skus' => fn ($q) => $q->select('id', 'product_id', 'name', 'sku', 'recipe_id')->with('product:id,name')]);
        });
    }

    public function approve(Recipe $recipe, User $approver): Recipe
    {
        return DB::transaction(function () use ($recipe, $approver) {
            $recipe = $this->lockRecipe($recipe);

            $recipe->assertApprovalStatus([ApprovalStatusEnum::Pending], 'approve');

            if ($recipe->created_by_id === $approver->getKey()) {
                throw new \InvalidArgumentException(
                    'Cannot approve your own recipe submission.'
                );
            }

            // Defence in depth: same checks as submit, in case state drifted
            // between submit and approve (ingredient material soft-deleted, etc.).
            $this->assertReadyForApproval($recipe);

            $recipe->markAsApproved($approver);
            $recipe->logAudit('recipe.approved', [
                'approver_id' => (string) $approver->getKey(),
            ]);

            // Plan-022 T18 / A4 — auto-promote the output material from RAW to
            // PRODUCED on first approval. Without this, an admin had to remember
            // to set Material.yield_unit by hand after every new recipe approval.
            // Idempotent: only fires when yield_unit is currently NULL.
            $this->syncOutputMaterialFromRecipe($recipe);

            $this->dispatchRecipeNotification($recipe, $approver, 'recipe.approved', [
                'recipe_name' => $recipe->name,
                'approver' => $approver->name,
            ]);

            return $recipe->load(['material', 'skus' => fn ($q) => $q->select('id', 'product_id', 'name', 'sku', 'recipe_id')->with('product:id,name')]);
        });
    }

    public function reject(Recipe $recipe, User $reviewer, string $reason): Recipe
    {
        return DB::transaction(function () use ($recipe, $reviewer, $reason) {
            $recipe = $this->lockRecipe($recipe);

            $recipe->assertApprovalStatus([ApprovalStatusEnum::Pending], 'reject');

            $recipe->markAsRejected($reviewer, $reason);
            $recipe->logAudit('recipe.rejected', [
                'approver_id' => (string) $reviewer->getKey(),
                'rejection_reason' => $reason,
            ]);

            $this->dispatchRecipeNotification($recipe, $reviewer, 'recipe.rejected', [
                'recipe_name' => $recipe->name,
                'reviewer' => $reviewer->name,
                'reason' => $reason,
            ]);

            return $recipe->load(['material', 'skus' => fn ($q) => $q->select('id', 'product_id', 'name', 'sku', 'recipe_id')->with('product:id,name')]);
        });
    }

    /**
     * Re-fetch the recipe under a pessimistic `FOR UPDATE` row lock inside the
     * current transaction so approval-state transitions serialize against each
     * other. Returns a fresh model reflecting the committed DB state.
     */
    private function lockRecipe(Recipe $recipe): Recipe
    {
        return $recipe->newQuery()->lockForUpdate()->findOrFail($recipe->getKey());
    }

    /**
     * plan-040 NEW-MR-2: reject a brand_id that resolves to a different
     * organization than the resource. Brand has no direct organization_id FK —
     * it joins to Organization via console_organization_id (see
     * ResolveBrandFromSlug). A null/absent brand_id is a no-op.
     */
    private function assertBrandWithinOrg(?string $organizationId, ?string $brandId): void
    {
        if ($brandId === null || $organizationId === null) {
            return;
        }

        $brand = Brand::find($brandId);

        $brandOrgId = $brand === null
            ? null
            : Organization::where('console_organization_id', $brand->console_organization_id)->value('id');

        if ((string) $brandOrgId !== (string) $organizationId) {
            throw ValidationException::withMessages([
                'brand_id' => 'brand_id must belong to the same organization as the recipe.',
            ]);
        }
    }

    /**
     * Silent-fail notification dispatch for recipe approval lifecycle.
     * Recipient = the recipe submitter (`created_by_id`). Phase 12 replaces
     * this with Audience::user($recipe->creator).
     */
    private function dispatchRecipeNotification(Recipe $recipe, User $actor, string $type, array $params): void
    {
        // Plan-023 M7 T7.13 — when the rule engine is on, the seeded
        // system rules for recipe.approved / recipe.rejected take over
        // dispatch. Short-circuit so we don't double-send — but only when a
        // live rule with a resolvable audience actually covers this emitter,
        // so flipping NOTIFICATION_USE_RULES can't silently mute recipe
        // approval notifications that have no replacement rule.
        // #962 — câu hỏi "máy luật đã che phủ emitter này chưa" đi qua cổng công
        // bố của Notifications thay vì đọc thẳng `NotificationRule`. Cùng một
        // truy vấn, cùng một định nghĩa che phủ — chỉ khác chỗ đặt câu hỏi.
        if (config('notifications.use_rules')
            && app(NotificationDispatcher::class)->coversEmitter('Recipe', 'model.updated', (string) $recipe->organization_id)) {
            return;
        }

        try {
            $submitter = $recipe->created_by_id
                ? User::find($recipe->created_by_id)
                : null;

            if ($submitter === null || ! ($submitter instanceof Notifiable)) {
                return;
            }

            app(NotificationDispatcher::class)->toRecipients(
                new NotificationRequest(
                    type: $type,
                    params: $params,
                    organizationId: (string) $recipe->organization_id,
                    actor: $actor,
                    subject: $recipe,
                    idempotencyKey: "{$type}:{$recipe->id}",
                ),
                [$submitter],
            );
        } catch (\Throwable $e) {
            \Log::warning('recipe-notification: dispatch failed', [
                'recipe_id' => $recipe->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    //  Delete & Restore
    // =========================================================================

    public function delete(Recipe $recipe): bool
    {
        return $recipe->delete();
    }

    public function restore(Recipe $recipe): Recipe
    {
        $recipe->restore();

        return $recipe->load(['material', 'skus' => fn ($q) => $q->select('id', 'product_id', 'name', 'sku', 'recipe_id')->with('product:id,name')]);
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    /**
     * @return array<int, array{id: string, name: string, sku: string|null}>
     */
    public function lookup(string $organizationId, ?string $brandId = null): array
    {
        $query = Recipe::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->select(['id', 'name', 'sku']);

        if ($brandId !== null) {
            $query->where('brand_id', $brandId);
        }

        return $query->orderBy('name')->get()->toArray();
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    /**
     * Persist pending translation rows (seeder-safe).
     *
     * Mirrors AllergenServiceBase::flushTranslations — see that file for the
     * full explanation. Required because Astrotomic's own `saved` event hook
     * is suppressed under WithoutModelEvents (seeders).
     */
    private function flushTranslations(Recipe $recipe): void
    {
        foreach ($recipe->translations as $translation) {
            if (! $translation->exists || $translation->isDirty()) {
                if (! empty($connectionName = $recipe->getConnectionName())) {
                    $translation->setConnection($connectionName);
                }
                $translation->setAttribute(
                    $recipe->getTranslationRelationKey(),
                    $recipe->getKey()
                );
                $translation->save();
            }
        }
    }

    /**
     * Pop `sku_ids` from the payload (it is not a Recipe column) and return
     * the cleaned array of UUIDs. Returns `null` when the caller didn't send
     * the key at all — distinguishes "leave SKU links alone" from "clear all
     * SKU links" (the latter passes `sku_ids: []`).
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>|null
     */
    private function extractSkuIds(array &$data): ?array
    {
        if (! array_key_exists('sku_ids', $data)) {
            return null;
        }

        $raw = $data['sku_ids'];
        unset($data['sku_ids']);

        if (! is_array($raw)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            fn ($id) => is_string($id) ? $id : null,
            $raw,
        ))));

        return $ids;
    }

    /**
     * Sync `ProductSku.recipe_id` for the output SKUs. Three steps:
     *   1. Detach any SKU that currently points to this recipe but is not in
     *      the new list (sets recipe_id = NULL).
     *   2. Attach SKUs in `$skuIds` that aren't yet linked (sets recipe_id
     *      = $recipe->id).
     *   3. Cross-brand guard — an SKU whose product belongs to a different
     *      brand is rejected via ValidationException so the caller gets a
     *      422 instead of silently writing a foreign-brand link.
     *
     * Idempotent: re-running with the same list is a no-op.
     *
     * @param  array<int, string>  $skuIds
     */
    private function syncOutputSkus(Recipe $recipe, array $skuIds): void
    {
        $this->skuService->syncRecipeSkuAssignments(
            $recipe->id,
            $skuIds,
            (string) $recipe->brand_id,
        );
    }

    /**
     * Normalize ingredients to ensure each entry has required fields.
     *
     * @param  array<int, array{type?: string, quantity?: float, unit?: string}>  $ingredients
     * @return array<int, array{type: string, quantity: float, unit: string}>
     */
    private function normalizeIngredients(array $ingredients): array
    {
        return array_map(function (array $ingredient) {
            return [
                ...$ingredient,
                'type' => $ingredient['type'] ?? 'raw',
                'quantity' => (float) ($ingredient['quantity'] ?? 0),
                'unit' => $ingredient['unit'] ?? 'piece',
            ];
        }, $ingredients);
    }

    // =========================================================================
    //  Plan-022 T18 — shape constraints
    // =========================================================================

    /**
     * Validate output material + ingredient shape against the rules defined in
     * the plan-022 design doc (A1–A3, B2–B6). Called on every create/update
     * and re-checked at submit/approve gates as defence in depth.
     *
     * Merges incoming `$data` with `$existing` so partial updates (e.g.
     * "change output_unit only") are still validated against the full state.
     *
     * @param  array<string, mixed>  $data
     */
    private function validateIngredientsAndOutput(array $data, ?Recipe $existing = null): void
    {
        $materialId = $data['material_id'] ?? $existing?->material_id;
        $brandId = $data['brand_id'] ?? $existing?->brand_id;
        $outputQty = array_key_exists('output_quantity', $data)
            ? $data['output_quantity']
            : $existing?->output_quantity;
        $outputUnit = array_key_exists('output_unit', $data)
            ? $data['output_unit']
            : $existing?->output_unit;
        $ingredients = array_key_exists('ingredients', $data)
            ? $data['ingredients']
            : ($existing?->ingredients ?? []);

        // A2 — output_quantity > 0 (when set).
        if ($outputQty !== null && (float) $outputQty <= 0) {
            throw ValidationException::withMessages([
                'output_quantity' => 'output_quantity must be greater than 0.',
            ]);
        }

        // A1 + A3 — output material brand + unit conformance.
        $outputMaterial = null;
        if ($materialId !== null && $materialId !== '') {
            $outputMaterial = $this->materials->find((string) $materialId);
            if ($outputMaterial === null) {
                throw ValidationException::withMessages([
                    'material_id' => 'Output material not found.',
                ]);
            }

            if ($brandId && (string) $outputMaterial->brandId !== (string) $brandId) {
                throw ValidationException::withMessages([
                    'material_id' => 'Output material belongs to a different brand.',
                ]);
            }

            if (! empty($outputUnit)) {
                $allowed = $this->materials->registeredUnits($outputMaterial->id);
                if (! empty($allowed) && ! in_array($outputUnit, $allowed, true)) {
                    throw ValidationException::withMessages([
                        'output_unit' => sprintf(
                            "output_unit '%s' is not registered for material %s. Allowed: %s",
                            $outputUnit,
                            $outputMaterial->sku ?? $outputMaterial->id,
                            implode(', ', $allowed),
                        ),
                    ]);
                }
            }
        }

        // B — ingredients.
        if (is_array($ingredients) && $ingredients !== []) {
            $seen = [];
            foreach ($ingredients as $idx => $ing) {
                $field = "ingredients.{$idx}";
                $ingMatId = $ing['material_id'] ?? null;
                $ingQty = (float) ($ing['quantity'] ?? 0);
                $ingUnit = $ing['unit'] ?? null;

                if ($ingMatId === null || $ingMatId === '') {
                    throw ValidationException::withMessages([
                        "{$field}.material_id" => 'ingredient.material_id is required.',
                    ]);
                }

                // B4 — quantity > 0.
                if ($ingQty <= 0) {
                    throw ValidationException::withMessages([
                        "{$field}.quantity" => 'ingredient.quantity must be greater than 0.',
                    ]);
                }

                // B3 — no self-reference.
                if ($materialId !== null && (string) $ingMatId === (string) $materialId) {
                    throw ValidationException::withMessages([
                        "{$field}.material_id" => 'Recipe cannot include its own output material as an ingredient.',
                    ]);
                }

                // B6 — no duplicate material in the same recipe.
                if (isset($seen[(string) $ingMatId])) {
                    throw ValidationException::withMessages([
                        "{$field}.material_id" => 'Duplicate ingredient — each material may appear only once per recipe.',
                    ]);
                }
                $seen[(string) $ingMatId] = true;

                $ingMaterial = $this->materials->find((string) $ingMatId);
                if ($ingMaterial === null) {
                    throw ValidationException::withMessages([
                        "{$field}.material_id" => 'Ingredient material not found.',
                    ]);
                }

                // B2 — cross-brand guard.
                if ($brandId && (string) $ingMaterial->brandId !== (string) $brandId) {
                    throw ValidationException::withMessages([
                        "{$field}.material_id" => sprintf(
                            'Ingredient %s belongs to a different brand.',
                            $ingMaterial->sku ?? $ingMaterial->id,
                        ),
                    ]);
                }

                // B5 — ingredient.unit must be a registered MaterialUnit on
                // that material. Materials with no MaterialUnits row yet skip
                // this check (they will be set up in a later step).
                if (! empty($ingUnit)) {
                    $allowed = $this->materials->registeredUnits($ingMaterial->id);
                    if (! empty($allowed) && ! in_array($ingUnit, $allowed, true)) {
                        throw ValidationException::withMessages([
                            "{$field}.unit" => sprintf(
                                "Unit '%s' is not registered for ingredient %s. Allowed: %s",
                                $ingUnit,
                                $ingMaterial->sku ?? $ingMaterial->id,
                                implode(', ', $allowed),
                            ),
                        ]);
                    }
                }
            }
        }
    }

    /**
     * B1 + C1 — gating at submit/approve. Requires the recipe to have at
     * least one ingredient, its output material to exist and be in the same
     * brand, and every referenced material to still exist (not soft-deleted).
     */
    private function assertReadyForApproval(Recipe $recipe): void
    {
        $ingredients = is_array($recipe->ingredients) ? $recipe->ingredients : [];

        if ($ingredients === []) {
            throw ValidationException::withMessages([
                'ingredients' => 'Recipe must have at least one ingredient before submission.',
            ]);
        }

        // Output side must point to SOMETHING — either a Material (the
        // canonical produced-BTP shape) or at least one ProductSku (the
        // direct menu-item shape). The two are not mutually exclusive; if
        // material_id is set we still validate it exists.
        if ($recipe->material_id !== null && $this->materials->find((string) $recipe->material_id) === null) {
            throw ValidationException::withMessages([
                'material_id' => 'Output material no longer exists.',
            ]);
        }

        if ($recipe->material_id === null && $recipe->skus()->count() === 0) {
            throw ValidationException::withMessages([
                'material_id' => 'Recipe needs an output — pick either a material or at least one product SKU.',
            ]);
        }

        foreach ($ingredients as $idx => $ing) {
            $mid = $ing['material_id'] ?? null;
            if ($mid === null || $this->materials->find((string) $mid) === null) {
                throw ValidationException::withMessages([
                    "ingredients.{$idx}.material_id" => 'Ingredient material no longer exists — remove or replace before submission.',
                ]);
            }
        }
    }

    /**
     * Plan-022 T18 / A4 — when a recipe is approved for the first time,
     * promote the output material from RAW to PRODUCED by stamping
     * `yield_unit` + `yield_quantity` from the recipe, and registering the
     * unit in `material_units` if it isn't already there.
     *
     * Idempotent: only fires when material.yield_unit is currently NULL.
     * Later recipe approvals never overwrite an already-set yield_unit —
     * use the Material edit page for explicit changes.
     *
     * #962 — cả hai luật trên (idempotent theo `yield_unit`, và đăng ký đơn vị
     * gốc) là luật của Inventory, nên chúng sống trong
     * {@see MaterialDirectory::adoptRecipeYield()}. Ở đây chỉ còn việc BÁO
     * sản lượng của công thức vừa được duyệt.
     */
    private function syncOutputMaterialFromRecipe(Recipe $recipe): void
    {
        if (empty($recipe->output_unit) || empty($recipe->material_id)) {
            return;
        }

        $this->materials->adoptRecipeYield(
            (string) $recipe->material_id,
            (string) $recipe->output_unit,
            $recipe->output_quantity === null ? null : (float) $recipe->output_quantity,
        );
    }
}
