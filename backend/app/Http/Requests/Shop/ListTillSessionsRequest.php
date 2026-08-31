<?php

namespace App\Http\Requests\Shop;

use App\Omnify\Enums\TillSessionStatusEnum;
use App\Services\Till\TillSessionListFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Plan 036 — GET /shops/{shopSlug}/till/sessions filter contract.
 *
 * Authorization is enforced by the controller via Gate::authorize() before
 * this request is hydrated; FormRequest::authorize() always returns true.
 *
 * The default date range (today − 7d → today) is applied by accessor methods
 * after validation, so unset params don't trip "required" rules.
 *
 * Date range hard-capped at 365 days to keep the revenue-rollup subquery
 * bounded — long-range exports go through CSV (one extra page-fetch loop)
 * rather than a single 5,000-row JSON response.
 */
class ListTillSessionsRequest extends FormRequest
{
    public const VARIANCE_BUCKETS = ['none', 'over', 'short', 'out_of_tolerance'];

    public const SORT_KEYS = ['opened_at_desc', 'opened_at_asc', 'variance_abs_desc'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $statusValues = array_map(
            fn (TillSessionStatusEnum $s) => $s->value,
            TillSessionStatusEnum::cases()
        );

        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'till_id' => ['nullable', 'array'],
            'till_id.*' => ['string', 'uuid'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in($statusValues)],
            'opener_id' => ['nullable', 'string', 'uuid'],
            'variance' => ['nullable', Rule::in(self::VARIANCE_BUCKETS)],
            'force_abandoned' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(self::SORT_KEYS)],
            'export' => ['nullable', Rule::in(['csv'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'INVALID_DATE_RANGE: `to` must be on or after `from`.',
            'status.*.in' => 'INVALID_STATUS: unknown session status.',
            'variance.in' => 'INVALID_VARIANCE_FILTER: unknown variance bucket.',
        ];
    }

    /**
     * Extra constraint that doesn't fit the rules table — the date range
     * cap. Done in `withValidator` so the response carries the same shape
     * as the rule-driven 422 path.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $from = $this->resolvedFrom();
            $to = $this->resolvedTo();
            if ($from->diffInDays($to) > 365) {
                $validator->errors()->add(
                    'to',
                    'INVALID_DATE_RANGE: date range may not exceed 365 days.'
                );
            }
        });
    }

    public function resolvedFrom(): Carbon
    {
        $from = $this->input('from');

        return $from
            ? Carbon::parse($from)->startOfDay()
            : now()->subDays(7)->startOfDay();
    }

    public function resolvedTo(): Carbon
    {
        $to = $this->input('to');

        return $to
            ? Carbon::parse($to)->endOfDay()
            : now()->endOfDay();
    }

    public function resolvedPerPage(): int
    {
        return (int) ($this->input('per_page', 25));
    }

    public function resolvedSort(): string
    {
        return (string) ($this->input('sort', 'opened_at_desc'));
    }

    public function isCsvExport(): bool
    {
        return $this->input('export') === 'csv'
            || str_contains((string) $this->header('Accept', ''), 'text/csv');
    }

    /**
     * @return list<string>
     */
    public function tillIds(): array
    {
        $raw = $this->input('till_id', []);

        return is_array($raw) ? array_values(array_filter($raw)) : [];
    }

    /**
     * @return list<string>
     */
    public function statuses(): array
    {
        $raw = $this->input('status', []);

        return is_array($raw) ? array_values(array_filter($raw)) : [];
    }

    /**
     * #1562 — biên giới delivery → Payments.
     *
     * Mọi mặc định (khoảng ngày, per-page, sort) được giải nghĩa TẠI ĐÂY,
     * không phải trong service: các accessor ở trên gọi `now()`, và đồng hồ
     * ứng dụng thô chỉ hợp lệ ở tầng trình bày (#1091).
     */
    public function toFilter(): TillSessionListFilter
    {
        $forceAbandoned = $this->input('force_abandoned');

        return new TillSessionListFilter(
            from: $this->resolvedFrom(),
            to: $this->resolvedTo(),
            perPage: $this->resolvedPerPage(),
            sort: $this->resolvedSort(),
            tillIds: $this->tillIds(),
            statuses: $this->statuses(),
            openerId: $this->input('opener_id'),
            variance: $this->input('variance'),
            forceAbandoned: is_null($forceAbandoned) ? null : $this->boolean('force_abandoned'),
        );
    }
}
