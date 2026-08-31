<?php

/**
 * StockTransfer Receive Request — plan-040 TD.1 (H1, NEW-STK-6).
 *
 * Validates the per-item received quantities posted to
 * POST /stock-transfers/{stockTransfer}/receive:
 *   - each `items.*.id` must belong to the route-bound transfer
 *   - each `items.*.received_quantity` must be > 0 and <= that item's
 *     own `sent_quantity` (closure rule against the transfer's items)
 *
 * SAFE TO EDIT - hand-written, never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Models\StockTransfer;
use Illuminate\Foundation\Http\FormRequest;

class StockTransferReceiveRequest extends FormRequest
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
        // plan-040 TD.1: build a sent_quantity lookup keyed by item id so the
        // per-item closure rules below can bound received_quantity against the
        // exact line they target (not a flat numeric max).
        $sentByItemId = $this->transferItemSentQuantities();

        return [
            // `items` is optional — when omitted the service receives the full
            // sent quantity per line. When provided it must be a non-empty
            // array and every line is bound + bounded below.
            'items' => ['sometimes', 'nullable', 'array', 'min:1'],
            'items.*.id' => [
                'required',
                'string',
                // plan-040 TD.1 / NEW-STK-6: the item id must belong to the
                // bound transfer — reject ids from any other transfer.
                function (string $attribute, mixed $value, \Closure $fail) use ($sentByItemId): void {
                    if (! array_key_exists((string) $value, $sentByItemId)) {
                        $fail('The selected item does not belong to this transfer.');
                    }
                },
            ],
            'items.*.received_quantity' => [
                'required',
                'numeric',
                'gt:0',
                // plan-040 TD.1 (H1): received cannot exceed what was sent on
                // that specific line. Resolve the sibling `id` to look up its
                // sent_quantity.
                function (string $attribute, mixed $value, \Closure $fail) use ($sentByItemId): void {
                    $index = explode('.', $attribute)[1] ?? null;
                    $itemId = $this->input("items.{$index}.id");

                    if ($itemId === null || ! array_key_exists((string) $itemId, $sentByItemId)) {
                        // Ownership failure is reported by the items.*.id rule.
                        return;
                    }

                    if ((float) $value > (float) $sentByItemId[(string) $itemId]) {
                        $fail('The received quantity may not be greater than the sent quantity.');
                    }
                },
            ],
        ];
    }

    /**
     * Map the bound transfer's items to {id => sent_quantity}.
     *
     * @return array<string, float>
     */
    private function transferItemSentQuantities(): array
    {
        $transfer = $this->route('stockTransfer');

        if (! $transfer instanceof StockTransfer) {
            return [];
        }

        return $transfer->items()
            ->pluck('sent_quantity', 'id')
            ->map(fn ($qty) => (float) $qty)
            ->all();
    }
}
