<?php

/**
 * MaterialUnit Service
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Omnify;

use App\Models\MaterialLot;
use App\Models\MaterialUnit;
use App\Models\StockTransactionItem;
use App\Omnify\Modules\MaterialUnit\Services\MaterialUnitServiceBase;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialUnitService extends MaterialUnitServiceBase
{
    /** Decimal places the ratio column stores (decimal(10,4)). */
    private const RATIO_SCALE = 4;

    /**
     * List units for one material, ordered by base-first then unit string.
     *
     * @return Collection<int, MaterialUnit>
     */
    public function listForMaterial(string $materialId): Collection
    {
        return MaterialUnit::where('material_id', $materialId)
            ->orderByDesc('is_base')
            ->orderBy('unit')
            ->get();
    }

    /**
     * Create a unit. Enforces:
     *   - ratio > 0
     *   - unit string unique per material (case-insensitive)
     *   - only one base unit per material (auto-demotes existing base when
     *     a new is_base=true unit is added)
     */
    public function create(array $data): MaterialUnit
    {
        $this->assertRatioPositive($data['ratio'] ?? null);
        $this->assertUniqueUnit((string) $data['material_id'], (string) $data['unit']);

        return DB::transaction(function () use ($data) {
            if (! empty($data['is_base'])) {
                // The new unit is the base: its provided ratio expresses it in
                // the CURRENT base's terms, so every existing unit is rescaled
                // relative to it (TC-UNIT-105). The base itself is ratio 1.
                $k = (float) ($data['ratio'] ?? 1);
                $this->rescaleOtherUnits((string) $data['material_id'], null, $k);
                $data['ratio'] = 1;
            }

            return parent::create($data);
        });
    }

    /**
     * Update a unit. Enforces same invariants as create() plus blocks
     * is_base flip from true → false (must promote a different unit
     * to base instead).
     */
    public function update(MaterialUnit $model, array $data): MaterialUnit
    {
        if (array_key_exists('ratio', $data)) {
            $this->assertRatioPositive($data['ratio']);
        }

        if (! empty($data['unit']) && $data['unit'] !== $model->unit) {
            $this->assertUniqueUnit((string) $model->material_id, (string) $data['unit'], $model->id);
        }

        if (array_key_exists('is_base', $data)
            && $model->is_base
            && ! $data['is_base']
        ) {
            throw ValidationException::withMessages([
                'is_base' => 'Cannot demote a base unit. Promote a different unit to base first.',
            ]);
        }

        return DB::transaction(function () use ($model, $data) {
            if (! empty($data['is_base']) && ! $model->is_base) {
                // Promoting this unit to base: rescale every OTHER unit's ratio
                // relative to it, then force this unit's ratio to exactly 1
                // (TC-UNIT-105). k = this unit's ratio in the current base's
                // terms (honour a ratio sent in the same request if present).
                $k = (float) ($data['ratio'] ?? $model->ratio);
                $this->rescaleOtherUnits((string) $model->material_id, $model->id, $k);
                $data['ratio'] = 1;
            } elseif ($model->is_base) {
                // plan-040 NEW-MR-5: the base unit's ratio is an invariant 1
                // (every conversion is expressed relative to it). A PUT that
                // tries to set ratio≠1 on the existing base must not corrupt the
                // grain — pin it back to 1 regardless of the supplied value.
                $data['ratio'] = 1;
            }

            return parent::update($model, $data);
        });
    }

    /**
     * Rescale every unit of a material (except $exceptId, the unit being
     * promoted) so their ratios are expressed relative to the new base, and
     * demote any prior base. new_ratio = old_ratio / k, rounded to the column
     * scale. Throws if a ratio would round away to zero — that means the new
     * base is too coarse to represent that unit within 4 decimal places.
     */
    private function rescaleOtherUnits(string $materialId, ?string $exceptId, float $k): void
    {
        if ($k <= 0) {
            return;
        }

        $query = MaterialUnit::where('material_id', $materialId);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        foreach ($query->get() as $other) {
            $newRatio = round((float) $other->ratio / $k, self::RATIO_SCALE);

            if ($newRatio <= 0) {
                throw ValidationException::withMessages([
                    'is_base' => "Cannot set the new base unit: '{$other->unit}' ratio would lose all precision (more than ".self::RATIO_SCALE.' decimal places).',
                ]);
            }

            $other->ratio = $newRatio;
            $other->is_base = false;
            $other->save();
        }
    }

    /**
     * Delete a unit. Base units cannot be deleted (would orphan stock
     * conversions). Promote a different unit to base first.
     */
    public function delete(MaterialUnit $model): bool
    {
        if ($model->is_base) {
            throw ValidationException::withMessages([
                'unit' => 'Cannot delete the base unit. Promote a different unit to base first.',
            ]);
        }

        return parent::delete($model);
    }

    /**
     * Whether a unit is referenced by existing inventory data — material lots
     * received in it or stock-transaction items recorded in it. Deleting such a
     * unit would orphan those conversions, so the caller must block it
     * (TC-UNIT-108).
     */
    public function isInUse(MaterialUnit $model): bool
    {
        $usedInLots = MaterialLot::where('material_id', $model->material_id)
            ->where('entered_unit', $model->unit)
            ->exists();

        if ($usedInLots) {
            return true;
        }

        return StockTransactionItem::where('material_id', $model->material_id)
            ->where('unit', $model->unit)
            ->exists();
    }

    private function assertRatioPositive(mixed $ratio): void
    {
        if ($ratio === null) {
            return;
        }
        if ((float) $ratio <= 0) {
            throw ValidationException::withMessages([
                'ratio' => 'ratio must be positive.',
            ]);
        }
    }

    private function assertUniqueUnit(string $materialId, string $unit, ?string $exceptId = null): void
    {
        $query = MaterialUnit::where('material_id', $materialId)
            ->whereRaw('LOWER(unit) = ?', [mb_strtolower($unit)]);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'unit' => "Unit '{$unit}' is already registered for this material.",
            ]);
        }
    }
}
