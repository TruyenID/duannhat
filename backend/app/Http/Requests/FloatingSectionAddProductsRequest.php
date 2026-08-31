<?php

namespace App\Http\Requests;

use App\Models\FloatingSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FloatingSectionAddProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var FloatingSection|null $section */
        $section = $this->route('floatingSection');

        return [
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => [
                'required',
                'uuid',
                'distinct',
                Rule::exists('products', 'id')
                    ->where(fn ($query) => $query
                        ->where('organization_id', $section?->organization_id)
                        ->where('brand_id', $section?->brand_id)
                        ->whereNull('deleted_at')),
            ],
        ];
    }
}
