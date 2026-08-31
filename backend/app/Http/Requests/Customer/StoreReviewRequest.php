<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Public endpoint — order UUID is the auth gate
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reviews' => ['required', 'array', 'min:1'],
            'reviews.*.order_item_id' => ['required', 'uuid'],
            'reviews.*.product_id' => ['required', 'uuid'],
            // plan-026: star rating replaces the binary sentiment input.
            'reviews.*.rating' => ['required', 'integer', 'min:1', 'max:5'],
            'reviews.*.tags' => ['nullable', 'array', 'max:10'],
            'reviews.*.tags.*' => ['string', 'max:50'],
            'reviews.*.comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
