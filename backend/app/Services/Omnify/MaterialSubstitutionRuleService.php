<?php

/**
 * MaterialSubstitutionRule Service
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Omnify;

use App\Models\MaterialSubstitutionRule;
use App\Omnify\Modules\MaterialSubstitutionRule\Services\MaterialSubstitutionRuleServiceBase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * MaterialSubstitutionRuleService — HQ CRUD for the per-material substitute
 * fallback rules surfaced on the material detail page.
 */
class MaterialSubstitutionRuleService extends MaterialSubstitutionRuleServiceBase
{
    /**
     * List rules scoped to the org/brand and (optionally) a single primary
     * material. Ordered priority-first (lower = preferred fallback) so the UI
     * table reads top-to-bottom in the order the picker would try them.
     *
     * @param array{
     *     organization_id?: string,
     *     brand_id?: string,
     *     primary_material_id?: string,
     *     is_active?: bool,
     *     per_page?: int,
     * } $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = MaterialSubstitutionRule::query()
            ->with(['substituteMaterial']);

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        if (! empty($filters['primary_material_id'])) {
            $query->where('primary_material_id', $filters['primary_material_id']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        $query->orderBy('priority')->orderByDesc('created_at');

        return $query->paginate($filters['per_page'] ?? 25);
    }

    /**
     * Create a rule. The DB enforces one row per
     * (organization_id, primary_material_id, substitute_material_id) — including
     * soft-deleted rows, since the unique index ignores deleted_at. To keep the
     * client error clean instead of a 500 constraint violation we:
     *   - reject a duplicate of an active rule with a 422, and
     *   - resurrect + overwrite a previously soft-deleted rule for the same pair.
     */
    public function create(array $data): MaterialSubstitutionRule
    {
        return DB::transaction(function () use ($data) {
            $existing = MaterialSubstitutionRule::withTrashed()
                ->where('organization_id', $data['organization_id'])
                ->where('primary_material_id', $data['primary_material_id'])
                ->where('substitute_material_id', $data['substitute_material_id'])
                ->first();

            if ($existing !== null) {
                if ($existing->trashed()) {
                    $existing->restore();
                    $existing->update($data);

                    return $existing->fresh(['substituteMaterial']);
                }

                throw ValidationException::withMessages([
                    'substitute_material_id' => 'A substitution rule for this material pair already exists.',
                ]);
            }

            return parent::create($data);
        });
    }

    /**
     * Eager-load the substitute (and primary) material on detail fetches so the
     * resource can echo the substitute name back to the UI.
     *
     * @param  Builder  $query
     */
    protected function applyFindByIdEagerLoads($query): void
    {
        $query->with(['substituteMaterial']);
    }
}
