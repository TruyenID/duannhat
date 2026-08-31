<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the shop-side activate/pause toggle for a menu schedule window.
 *
 * Branch menus own their own schedule rows, so the shop toggles `is_active`
 * directly on the schedule — no override row is involved. Only `is_active`
 * is accepted; timing edits go through BranchMenuScheduleUpsertRequest.
 */
class BranchMenuScheduleSetActiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gate checked in controller via $this->authorize()
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }
}
