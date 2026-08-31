<?php

namespace App\Http\Requests\Workstation;

use App\Services\Printing\Enums\PrintConfidence;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\UposPrinterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * plan-052 T1.2 — shape of a ws_lan journal batch synced UP.
 *
 * Validation is deliberately thin. These rows describe prints that ALREADY
 * HAPPENED at the shop; rejecting one loses history that exists nowhere else,
 * so only what Cloud genuinely cannot store is refused (a missing id would
 * break idempotency, an unknown kind would break the retry/TTL registry).
 */
class WorkstationPrintJobBatchRequest extends FormRequest
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
            'jobs' => ['required', 'array', 'min:1', 'max:200'],
            'jobs.*.id' => ['required', 'uuid'],
            'jobs.*.kind' => ['required', Rule::in(array_column(PrintJobKind::cases(), 'value'))],
            'jobs.*.status' => ['required', Rule::in(array_column(PrintJobStatus::cases(), 'value'))],
            'jobs.*.confidence' => ['sometimes', Rule::in(array_column(PrintConfidence::cases(), 'value'))],
            'jobs.*.printer_id' => ['nullable', 'string', 'max:36'],
            'jobs.*.order_id' => ['nullable', 'string', 'max:36'],
            'jobs.*.payment_id' => ['nullable', 'string', 'max:36'],
            'jobs.*.payload' => ['nullable', 'array'],
            'jobs.*.reprint_no' => ['nullable', 'integer', 'min:1'],
            'jobs.*.requested_by_id' => ['nullable', 'string', 'max:36'],
            'jobs.*.requested_via' => ['nullable', 'string', 'max:32'],
            'jobs.*.reprint_reason' => ['nullable', 'string', 'max:255'],
            // TR-28 (#1171) — `<scope>:<version>` of the layout that drew the
            // sheet. A RULE is required, not optional politeness: the controller
            // hands the ingest `$request->validated('jobs')`, which contains only
            // keys that have one, so an unruled field is stripped in silence —
            // the device would send it, nothing would error, and the column would
            // stay NULL forever.
            //
            // No format regex, deliberately. The shape is checked where it is
            // READ ({@see \App\Services\Print\TemplateStamp::parse()}, which
            // answers null for anything malformed, so no reprint acts on a value
            // it cannot understand). Refusing the batch instead would discard an
            // evening of real prints — history that exists nowhere else — over a
            // provenance string, which is the trade this endpoint does not make.
            'jobs.*.template_version' => ['nullable', 'string', 'max:32'],
            'jobs.*.attempts' => ['nullable', 'integer', 'min:0'],
            'jobs.*.last_error' => ['nullable', 'string', 'max:255'],
            // T1.3 — the machine's state in UPOS words, observed by the tier
            // that owns its queue (P-38).
            'jobs.*.printer_status' => ['nullable', Rule::in(array_column(UposPrinterStatus::cases(), 'value'))],
            // The device's own clock at the moment the paper came out (P-07).
            'jobs.*.printed_at' => ['nullable', 'date'],
            'jobs.*.issued_at' => ['nullable', 'date'],
            'jobs.*.acked_at' => ['nullable', 'date'],
        ];
    }
}
