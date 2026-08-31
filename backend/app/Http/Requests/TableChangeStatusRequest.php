<?php

namespace App\Http\Requests;

use App\Omnify\Enums\TableStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TableChangeStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(TableStatusEnum::class)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
