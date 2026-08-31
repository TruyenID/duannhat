<?php

namespace App\Services\Product;

use App\Exceptions\ComponentInUseException;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialUnit;
use App\Models\Organization;
use App\Services\Product\Contracts\MaterialAllergenPropagation;
use App\Services\Product\Contracts\RecipeGraph;
use App\Traits\GeneratesSku;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vòng đời CRUD của `Material`.
 *
 * #962 — class này SỞ HỮU aggregate `Material`/`MaterialUnit` của **Inventory**,
 * dù file nằm dưới `App\Services\Product`. Nó phát 23/36 cạnh
 * Catalog → Inventory, và mọi cạnh trong số đó là nó đọc chính aggregate nó
 * quản — tức ranh giới vẽ sai chỗ, không phải nợ vượt biên (cùng chẩn đoán với
 * #1541 / #1543 / #1562). Bọc `Material` sau một interface để "hợp thức hoá"
 * sẽ khoá cứng cái sai; khai lại quyền sở hữu trong `config/modules.php` thì
 * không. Namespace giữ nguyên: đổi tên namespace là refactor rủi ro và không
 * cần cho việc này.
 *
 * Đổi lại, hai thứ nó cần từ Catalog phải đi qua cổng công bố: đồ thị nguyên
 * liệu của công thức ({@see RecipeGraph}) và hệ quả duyệt lại khi dị nguyên đổi
 * ({@see MaterialAllergenPropagation}).
 */
class MaterialService
{
    use GeneratesSku;

    public function __construct(
        private readonly MaterialAllergenPropagation $allergenPropagation,
        private readonly RecipeGraph $recipeGraph,
    ) {}

    protected string $skuPrefix = 'MA';

    protected string $skuModel = Material::class;

    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @param  array{organization_id: string, search?: string, is_active?: bool, with_trashed?: bool, sort?: string, per_page?: int, kind?: 'raw'|'produced'}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Material::query()
            ->with(['outputSku:id,name,sku', 'allergens'])
            // Plan-017 MOD-1 — surface lot counts on the list page so HQ ops
            // can spot materials with low / no active inventory or impending
            // expiry without drilling in. withCount stays a single
            // GROUP BY query each — no N+1 risk.
            ->withCount([
                'activeLots',
                'expiringSoonLots',
                // Plan-022 T4.4 — replaces the legacy "components count"
                // column. The list UI surfaces "is this material batched?"
                // by counting active recipes; zero means raw material /
                // not yet recipe-built.
                'recipes as active_recipes_count' => fn ($q) => $q->where('is_active', true),
            ]);

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhereTranslationLike('name', "%{$search}%");
            });
        });

        $query->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']));

        // Plan-022 — `kind` filter. Raw = no yield_unit (and by induction no
        // active recipe). Produced = has yield_unit (set by validateYieldShape
        // when the material acquires its first ingredient list). We match on
        // yield_unit because it is the persisted truth and lets the SQL stay
        // a single WHERE clause; cross-checking active_recipes_count would
        // require a HAVING on the withCount, which MySQL allows but the SQLite
        // test connection rejects.
        $query->when($filters['kind'] ?? null, function ($q, $kind) {
            if ($kind === 'raw') {
                $q->whereNull('yield_unit');
            } elseif ($kind === 'produced') {
                $q->whereNotNull('yield_unit');
            }
        });

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): Material
    {
        return Material::with(['outputSku', 'recipes', 'allergens'])->findOrFail($id);
    }

    // =========================================================================
    //  Create
    // =========================================================================

    public function create(array $data): Material
    {
        return DB::transaction(function () use ($data) {
            if (empty($data['sku'])) {
                $data['sku'] = $this->generateUniqueSku(
                    additionalWhere: ['organization_id' => $data['organization_id']]
                );
            }

            // Plan-022 T4.5 — Material.components column dropped. BOM lives
            // on Recipe.ingredients (RecipeService does its own duplicate +
            // circular-reference validation against the canonical source).
            // Strip any stale `components` key from legacy callers so the
            // mass-assignment doesn't trip.
            unset($data['components']);

            $this->validateYieldShape($data, materialId: null);

            // allergen_ids is a pivot payload, not a column — pull it out
            // before mass-assign so Material::create() doesn't strip it
            // silently and we can sync it onto material_allergens after.
            $allergenIds = $data['allergen_ids'] ?? null;
            unset($data['allergen_ids']);

            $material = Material::create($data);
            $this->flushTranslations($material);

            // A produced material's yield_unit IS its measurement grain, so
            // seed it as the base MaterialUnit immediately. Without this the
            // material is born with a required yield_unit but zero units, and
            // the operator must re-declare the same unit on the separate Units
            // tab (where nothing forces base == yield_unit → silent drift).
            // Mirrors RecipeService's output_unit base-unit seeding.
            $this->ensureBaseUnitFromYield($material);

            if (! empty($allergenIds)) {
                $material->allergens()->sync($allergenIds);
                $this->propagateAllergenChange($material);
            }

            return $material->load(['outputSku', 'recipes', 'allergens']);
        });
    }

    // =========================================================================
    //  Update
    // =========================================================================

    public function update(Material $material, array $data): Material
    {
        return DB::transaction(function () use ($material, $data) {
            // Plan-022 T4.5 — see create(); strip stale `components` key.
            unset($data['components']);

            // plan-040 NEW-MR-2: a material can never be reassigned to a brand
            // in another organization. brand_id is stripped at the request
            // layer; this reconciles direct/import callers and rejects a
            // cross-org brand before mass-assignment.
            $this->assertBrandWithinOrg($material->organization_id, $data['brand_id'] ?? null);

            $this->validateYieldShape($data, materialId: $material->id, current: $material);

            $allergenIds = $data['allergen_ids'] ?? null;
            unset($data['allergen_ids']);

            $material->update($data);
            $this->flushTranslations($material);

            // Catch materials promoted to "produced" via update (yield_unit set
            // after creation) that still have no units. Same seeding as create.
            $this->ensureBaseUnitFromYield($material);

            if ($allergenIds !== null) {
                $previousAllergenIds = $material->allergens()
                    ->pluck('allergens.id')
                    ->map(fn ($id) => (string) $id)
                    ->sort()
                    ->values()
                    ->all();

                $material->allergens()->sync($allergenIds);

                $newAllergenIds = collect($allergenIds)
                    ->map(fn ($id) => (string) $id)
                    ->sort()
                    ->values()
                    ->all();

                if ($previousAllergenIds !== $newAllergenIds) {
                    $this->propagateAllergenChange($material);
                }
            }

            return $material->load(['outputSku', 'recipes', 'allergens']);
        });
    }

    // =========================================================================
    //  Delete & Restore
    // =========================================================================

    public function delete(Material $material): bool
    {
        $usedBy = $this->findMaterialsUsingMaterial($material->id);

        if ($usedBy->isNotEmpty()) {
            throw new ComponentInUseException(
                "Cannot delete material '{$material->name}': it is used by other materials.",
                $usedBy->map(fn (Material $m) => ['id' => $m->id, 'name' => $m->name])->toArray()
            );
        }

        return $material->delete();
    }

    public function restore(Material $material): Material
    {
        return DB::transaction(function () use ($material) {
            $material->restore();

            return $material;
        });
    }

    // =========================================================================
    //  Usage Check
    // =========================================================================

    /**
     * Check which materials reference this material in their components JSON.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function checkUsage(Material $material): array
    {
        return $this->findMaterialsUsingMaterial($material->id)
            ->map(fn (Material $m) => ['id' => $m->id, 'name' => $m->name])
            ->toArray();
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    /**
     * @param  array<int, string>  $includeIds
     * @return array<int, array{id: string, name: string, sku: string|null, yield_quantity: string, yield_unit: string|null, components_count: int}>
     */
    public function lookup(string $organizationId, array $includeIds = [], ?string $brandId = null): array
    {
        $query = Material::query()
            ->with(['materialUnits:id,material_id,unit,ratio,is_base'])
            ->select(['id', 'name', 'sku', 'yield_quantity', 'yield_unit']);

        // The org/brand boundary is applied to BOTH arms of the includeIds OR
        // below, so the tenant scope is never short-circuited.
        $applyScope = function ($q) use ($organizationId, $brandId) {
            $q->where('organization_id', $organizationId);
            if ($brandId !== null) {
                $q->where('brand_id', $brandId);
            }
        };

        if (! empty($includeIds)) {
            // plan-040 NEW-MR-7: `includeIds` force-includes specific rows even
            // when inactive (the form's currently-selected materials), but a
            // bare top-level `orWhereIn('id', ...)` ORs against the WHOLE WHERE
            // (org AND active [AND brand]) and so leaks cross-tenant rows for
            // any foreign id passed. Nest both arms inside the org/brand scope:
            //   (scope AND is_active) OR (scope AND id IN includeIds)
            // so included rows bypass `is_active` but never the tenant boundary.
            $query->where(function ($outer) use ($applyScope, $includeIds) {
                $outer->where(function ($q) use ($applyScope) {
                    $applyScope($q);
                    $q->where('is_active', true);
                })->orWhere(function ($q) use ($applyScope, $includeIds) {
                    $applyScope($q);
                    $q->whereIn('id', $includeIds);
                });
            });
        } else {
            $applyScope($query);
            $query->where('is_active', true);
        }

        $materials = $query->orderBy('name')->get();

        // Plan-022 T4.3 — components_count was the legacy "can this material
        // be batched" hint and read off Material.components. Recipe is now
        // the canonical BOM source, so derive the count from the active
        // recipe's ingredient list. Materials without a recipe fall through
        // as 0 (and the UI surfaces "create a recipe before batching").
        $recipeIngredientCounts = $this->recipeGraph->activeIngredientCounts(
            $materials->pluck('id')->map(fn ($id) => (string) $id)->all(),
        );

        return $materials->map(fn ($m) => [
            'id' => $m->id,
            'name' => $m->name,
            'sku' => $m->sku,
            'yield_quantity' => $m->yield_quantity,
            'yield_unit' => $m->yield_unit,
            'components_count' => $recipeIngredientCounts[(string) $m->id] ?? 0,
            // Registered units (base first) so the lot-receive form can offer a
            // unit picker instead of free text — the entered unit must match one
            // of these (MaterialLotService::assertUnitBelongsToMaterial).
            'units' => $m->materialUnits
                ->sortByDesc('is_base')
                ->map(fn ($u) => [
                    'unit' => $u->unit,
                    'ratio' => $u->ratio,
                    'is_base' => (bool) $u->is_base,
                ])->values()->all(),
        ])->all();
    }

    /**
     * Recompute downstream Recipe.allergen_rollup and auto-repend any
     * `approved` Recipe whose rollup actually changed (two-tier rule —
     * empty-delta updates do not trigger repend).
     *
     * #962 — cả bốn bước đó là quy trình DUYỆT CÔNG THỨC, luật của Catalog.
     * Ở đây chỉ còn lời báo "dị nguyên của nguyên liệu này vừa đổi".
     */
    private function propagateAllergenChange(Material $material): void
    {
        $this->allergenPropagation->propagateAllergenChange(
            (string) $material->id,
            (string) $material->organization_id,
        );
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

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
                'brand_id' => 'brand_id must belong to the same organization as the material.',
            ]);
        }
    }

    /**
     * Persist pending translation rows (seeder-safe).
     *
     * Mirrors AllergenServiceBase::flushTranslations — see that file for the
     * full explanation. Required because Astrotomic's own `saved` event hook
     * is suppressed under WithoutModelEvents (seeders).
     */
    private function flushTranslations(Material $material): void
    {
        foreach ($material->translations as $translation) {
            if (! $translation->exists || $translation->isDirty()) {
                if (! empty($connectionName = $material->getConnectionName())) {
                    $translation->setConnection($connectionName);
                }
                $translation->setAttribute(
                    $material->getTranslationRelationKey(),
                    $material->getKey()
                );
                $translation->save();
            }
        }
    }

    /**
     * Seed a produced material's yield_unit as its base MaterialUnit when the
     * material has no units yet. yield_unit is required + free-text on the
     * create form while units live on a separate tab, so without this the two
     * are declared in different places with nothing keeping them in sync. By
     * registering yield_unit as the base (ratio 1) at create/update time, the
     * base unit == yield_unit by construction and the Units tab starts from a
     * correct base the operator only layers conversions onto. No-op for raw
     * materials (null yield_unit) or when any unit already exists.
     */
    private function ensureBaseUnitFromYield(Material $material): void
    {
        $yieldUnit = $material->yield_unit;

        if ($yieldUnit === null || $yieldUnit === '') {
            return;
        }

        if ($material->materialUnits()->exists()) {
            return;
        }

        $material->materialUnits()->create([
            'unit' => $yieldUnit,
            'ratio' => 1.0,
            'is_base' => true,
        ]);
    }

    /**
     * Plan-022 T2 / T19 — yield shape validation.
     *
     * Rule: a material is "produced" if any of these declare it so:
     *   • The incoming payload sets a non-empty `yield_unit` (T19 explicit
     *     declaration — HQ Form "Kind: Bán thành phẩm" sends this upfront).
     *   • The material already has `yield_unit` set on the row.
     *   • The payload carries `ingredients` (legacy test helpers).
     *   • The material already has an active recipe.
     *
     *   produced  → require yield_quantity > 0 AND yield_unit non-null AND
     *               yield_unit registered in material_units (if any are set)
     *   raw       → no yield_unit, no yield checks.
     */
    private function validateYieldShape(array &$data, ?string $materialId, ?Material $current = null): void
    {
        $isProduced = $this->isProducedMaterial($data, $materialId, $current);

        if (! $isProduced) {
            // Raw material — make sure no stale yield_unit leaks through. If
            // an empty string was sent (HTML form default), normalise to null.
            if (array_key_exists('yield_unit', $data) && $data['yield_unit'] === '') {
                $data['yield_unit'] = null;
            }

            return;
        }

        $yieldQty = (float) ($data['yield_quantity'] ?? $current?->yield_quantity ?? 0);
        $yieldUnit = $data['yield_unit'] ?? $current?->yield_unit;

        if ($yieldQty <= 0) {
            throw ValidationException::withMessages([
                'yield_quantity' => 'yield_quantity must be > 0 for a produced material.',
            ]);
        }

        if ($yieldUnit === null || $yieldUnit === '') {
            throw ValidationException::withMessages([
                'yield_unit' => 'yield_unit is required for a produced material.',
            ]);
        }

        if ($materialId !== null) {
            $registeredUnits = MaterialUnit::query()
                ->where('material_id', $materialId)
                ->pluck('unit')
                ->all();

            if ($registeredUnits !== [] && ! in_array($yieldUnit, $registeredUnits, true)) {
                throw ValidationException::withMessages([
                    'yield_unit' => sprintf(
                        "yield_unit '%s' is not registered in material_units for this material. Allowed: %s",
                        $yieldUnit,
                        implode(', ', $registeredUnits),
                    ),
                ]);
            }
        }
    }

    /**
     * Plan-022 T2 / T19 — "produced" detection. The single source of truth is
     * `yield_unit`: any non-null value (in the incoming payload OR on the
     * existing row) means produced. Recipe presence and `ingredients` are
     * fallback signals retained for legacy test helpers + the T18.3 auto-sync
     * path; once `yield_unit` is stamped, this method returns true regardless.
     */
    private function isProducedMaterial(array $data, ?string $materialId, ?Material $current = null): bool
    {
        // T19 — explicit declaration on the payload OR persisted on the row.
        $declaredUnit = $data['yield_unit'] ?? $current?->yield_unit ?? null;
        if (! empty($declaredUnit)) {
            return true;
        }

        if (! empty($data['ingredients'])) {
            return true;
        }

        if ($materialId !== null && $this->recipeGraph->hasActiveRecipeWithIngredients($materialId)) {
            return true;
        }

        return false;
    }

    /**
     * Plan-022 T4.3 — find every material whose ACTIVE Recipe's ingredients
     * JSON references the given material id. Replaces the legacy
     * `JSON_CONTAINS(components, ?)` scan now that Recipe.ingredients is the
     * canonical BOM source.
     *
     * @return Collection<int, Material>
     */
    private function findMaterialsUsingMaterial(string $materialId): Collection
    {
        $parentMaterialIds = $this->recipeGraph->producedMaterialIdsConsuming($materialId);

        if ($parentMaterialIds === []) {
            return new Collection;
        }

        return Material::whereIn('id', $parentMaterialIds)->get();
    }
}
