<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StoreBranchReviewRequest extends FormRequest
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
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            // plan-026 aspect ratings (Dịch vụ / Nhân viên / Không gian)
            'service_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'staff_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'space_rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            // Highlight chips ("Điểm nổi bật")
            'highlights' => ['nullable', 'array', 'max:20'],
            'highlights.*' => ['string', 'max:50'],
            // Photo file ids (temp uploads from review-photos endpoint) to attach.
            'photo_file_ids' => ['nullable', 'array', 'max:6'],
            'photo_file_ids.*' => ['uuid'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
