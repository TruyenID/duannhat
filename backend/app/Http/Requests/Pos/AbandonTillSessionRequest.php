<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Plan 030 — POST /pos/till/sessions/{session}/abandon.
 *
 * Reason optional at the validation layer — UI policy may require it; the
 * abandoned_at column accepts a null reason for migration of older sessions.
 */
class AbandonTillSessionRequest extends FormRequest
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
        return [
            'abandon_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
