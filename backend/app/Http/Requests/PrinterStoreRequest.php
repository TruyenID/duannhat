<?php

/**
 * Printer Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPrinterTransport;
use App\Omnify\Enums\PrinterConnectionTypeEnum;
use App\Omnify\Enums\PrinterCutTypeEnum;
use App\Omnify\Enums\PrinterRoleEnum;
use App\Omnify\Modules\Printer\Requests\PrinterStoreRequestBase;
use App\Rules\PrinterAddress;
use Illuminate\Validation\Rule;

/**
 * PrinterStoreRequest — creating a physical ESC/POS printer for a shop.
 *
 * Only user-supplied fields are exposed. `organization_id` / `branch_id` are
 * stamped by the controller from the resolved shop context, and `last_seen_at`
 * is reported by the workstation — never by an admin.
 *
 * `roles` is an ARRAY (BR-P02): one printer can serve several roles at once,
 * which is how a small shop runs kitchen + receipt off a single device. This
 * mirrors workstation migration 013; do not collapse it back to a single value.
 */
class PrinterStoreRequest extends PrinterStoreRequestBase
{
    use ValidatesPrinterTransport;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            // No `whereNull('deleted_at')`: the DB unique index is on
            // (branch_id, name) with no soft-delete component, so a trashed
            // printer still owns its name. Excluding trashed rows here would
            // pass validation and then hit a raw 1062 as a 500.
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('printers', 'name')
                    ->where('branch_id', $this->attributes->get('shop_id')),
            ],

            // At least one role — a printer holding none would never receive a
            // job and silently look "configured" (BR-P02).
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(PrinterRoleEnum::values())],

            'connection_type' => [
                'required', 'string',
                Rule::in(PrinterConnectionTypeEnum::values()),
            ],

            // Address is mandatory for both connection types — a printer with
            // no address is unreachable. The shape check lives in the rule.
            'address' => [
                'required', 'string', 'max:255',
                new PrinterAddress($this->input('connection_type')),
            ],

            // 58mm and 80mm are the only thermal widths the ESC/POS formatter
            // has column layouts for (see service/print_*.go).
            'paper_width' => ['sometimes', 'integer', Rule::in([58, 80])],

            'cut_type' => ['sometimes', 'string', Rule::in(PrinterCutTypeEnum::values())],
            'encoding' => ['sometimes', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],

            // plan-052 P-39 / plan-053 T5.4 — see the trait for why this list is
            // derived rather than written out.
            'transport' => $this->transportRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->transportMessages();
    }
}
