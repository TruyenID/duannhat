<?php

namespace App\Http\Resources;

use App\Omnify\Modules\MaterialLot\Resources\MaterialLotResourceBase;
use Illuminate\Http\Request;

/**
 * MaterialLotResource — editable extension of the omnify-generated base.
 *
 * Adds plan-017 Tier 1.D `is_temperature_compliant` — computed from the
 * lot's `received_temperature` against the parent material's
 * `temperature_min` / `temperature_max`. Tri-state:
 *   - true   : temp is within range (or material doesn't require check)
 *   - false  : temp is outside range; ops must have set
 *              `temperature_override_reason` for the receive to proceed
 *   - null   : no temperature recorded (pre-Tier-1.D legacy lots, or
 *              material doesn't require check and ops left it blank)
 */
class MaterialLotResource extends MaterialLotResourceBase
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        $data['is_temperature_compliant'] = $this->computeTemperatureCompliance();

        return $data;
    }

    private function computeTemperatureCompliance(): ?bool
    {
        if ($this->received_temperature === null) {
            return null;
        }

        $material = $this->relationLoaded('material') ? $this->material : $this->material()->first();
        if (! $material || ! $material->requires_temperature_check) {
            return true;
        }

        $temp = (float) $this->received_temperature;
        $min = $material->temperature_min !== null ? (float) $material->temperature_min : null;
        $max = $material->temperature_max !== null ? (float) $material->temperature_max : null;

        if ($min !== null && $temp < $min) {
            return false;
        }
        if ($max !== null && $temp > $max) {
            return false;
        }

        return true;
    }
}
