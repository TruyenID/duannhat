<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopesWarehouseToOrganization;
use App\Models\Material;
use App\Models\Warehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MaterialLotReceiveRequest extends FormRequest
{
    use ScopesWarehouseToOrganization;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'material_id' => ['required', 'string', 'exists:materials,id'],
            'warehouse_id' => ['required', 'string', $this->warehouseExistsRule()],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'supplier_lot_code' => ['nullable', 'string', 'max:100'],
            'received_at' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:received_at'],
            'received_qty' => ['required', 'numeric', 'gt:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'cost_per_unit' => ['nullable', 'numeric', 'gte:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'coa_urls' => ['nullable', 'array'],
            'coa_urls.*' => ['string', 'max:500', 'url'],
            'received_temperature' => ['nullable', 'numeric'],
            'temperature_override_reason' => ['nullable', 'string', 'max:1000'],
            'allergen_override_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, Validator>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $warehouseId = $this->input('warehouse_id');
                $materialId = $this->input('material_id');

                $warehouse = Warehouse::find($warehouseId);
                if (! $warehouse || empty($warehouse->allergen_policy)) {
                    return;
                }

                $policy = $warehouse->allergen_policy;
                $forbidden = $policy['forbidden'] ?? [];

                if (empty($forbidden)) {
                    return;
                }

                $material = Material::with('allergens')->find($materialId);
                if (! $material) {
                    return;
                }

                $materialAllergenNames = $material->allergens->pluck('name')->map(fn ($n) => strtolower((string) $n))->all();
                $forbiddenLower = array_map('strtolower', $forbidden);
                $violations = array_intersect($materialAllergenNames, $forbiddenLower);

                if (empty($violations)) {
                    return;
                }

                if ($this->filled('allergen_override_reason')) {
                    return;
                }

                $validator->errors()->add(
                    'allergen_policy',
                    'allergen_forbidden_at_warehouse: '.implode(', ', $violations)
                        .'. Provide allergen_override_reason to override.',
                );
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'expiry_date.after_or_equal' => 'expiry_date must be on or after received_at.',
            'received_qty.gt' => 'received_qty must be greater than 0.',
        ];
    }
}
