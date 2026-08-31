<?php

namespace App\Http\Requests\Shop;

use App\Services\Printing\Enums\PrintConfidence;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\PrintTransport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * plan-052 M2 / T2.2 — filters for the Print jobs list.
 *
 * `from` / `to` are BRANCH-LOCAL DATES (#1091). A manager in Hanoi opening the
 * Tokyo shop's screen and typing 2026-07-28 means Tokyo's 28th; resolving them
 * against the viewer's clock would hand them a different nine hours of rows
 * than the person standing in the shop sees.
 */
class PrintJobIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The policy runs in the controller; the shop-scoping middleware has
        // already proved this user may reach this shop at all.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes'],
            'status.*' => ['string', Rule::in(array_column(PrintJobStatus::cases(), 'value'))],

            'kind' => ['sometimes'],
            'kind.*' => ['string', Rule::in(array_column(PrintJobKind::cases(), 'value'))],

            'transport' => ['sometimes', 'string', Rule::in(array_column(PrintTransport::cases(), 'value'))],
            'confidence' => ['sometimes', 'string', Rule::in(array_column(PrintConfidence::cases(), 'value'))],
            'printer_id' => ['sometimes', 'string', 'max:36'],

            /*
             * #1875 — "has order X had a red invoice printed, and how many times?"
             *
             * `print_jobs` has carried `order_id` and `payment_id` since #1166 and
             * both columns are indexed, but nothing exposed them, so the only way
             * to answer that question about a specific order was a direct database
             * query. Shop scoping is unaffected: the controller's
             * organization_id + branch_id constraints are applied first and these
             * only narrow within them.
             */
            'order_id' => ['sometimes', 'string', 'max:36'],
            'payment_id' => ['sometimes', 'string', 'max:36'],
            'money_documents_only' => ['sometimes', 'boolean'],
            'unresolved_only' => ['sometimes', 'boolean'],

            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],

            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** Accept `status=failed` as readily as `status[]=failed` — a filter link should not need an array literal. */
    protected function prepareForValidation(): void
    {
        foreach (['status', 'kind'] as $key) {
            $value = $this->input($key);

            if (is_string($value) && $value !== '') {
                $this->merge([$key => array_values(array_filter(array_map('trim', explode(',', $value))))]);
            }
        }
    }

    /** @return list<string> */
    public function statuses(): array
    {
        /** @var list<string> $values */
        $values = (array) $this->input('status', []);

        return array_values(array_map('strval', $values));
    }

    /** @return list<string> */
    public function kinds(): array
    {
        /** @var list<string> $values */
        $values = (array) $this->input('kind', []);

        return array_values(array_map('strval', $values));
    }

    public function perPage(): int
    {
        return min(max((int) $this->input('per_page', 25), 1), 100);
    }
}
