<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * plan-045 — validate a line-refund request. The refundable ceiling
 * (refunded_quantity + quantity ≤ original quantity) is enforced in
 * CustomerOrderService::refundItem (needs the DB row + lock), so this only
 * checks the shape.
 */
class RefundOrderItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controller authorizes via CustomerOrderPolicy
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
