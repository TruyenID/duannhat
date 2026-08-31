<?php

/**
 * Printer Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPrinterTransport;
use App\Models\Printer;
use App\Omnify\Enums\PrinterConnectionTypeEnum;
use App\Omnify\Enums\PrinterCutTypeEnum;
use App\Omnify\Enums\PrinterRoleEnum;
use App\Omnify\Modules\Printer\Requests\PrinterUpdateRequestBase;
use App\Rules\PrinterAddress;
use Illuminate\Validation\Rule;

/**
 * PrinterUpdateRequest — partial update of a shop's printer.
 *
 * Every field is `sometimes` so a PATCH touching only `address` (the common
 * case: the printer got a new DHCP lease) doesn't force the client to resend
 * the whole record.
 *
 * NOTE on address validation: the rule needs to know the connection type, but
 * a partial update may omit it. We fall back to the printer's CURRENT
 * connection_type from the route model so validating an address-only PATCH
 * doesn't silently apply the wrong (network) ruleset to a USB printer.
 */
class PrinterUpdateRequest extends PrinterUpdateRequestBase
{
    use ValidatesPrinterTransport;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $printerId = (string) $this->route('printer');

        return [
            // See PrinterStoreRequest: the unique index ignores deleted_at, so
            // trashed rows must stay in scope here too.
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('printers', 'name')
                    ->where('branch_id', $this->attributes->get('shop_id'))
                    ->ignore($printerId),
            ],

            'roles' => ['sometimes', 'required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(PrinterRoleEnum::values())],

            'connection_type' => [
                'sometimes', 'required', 'string',
                Rule::in(PrinterConnectionTypeEnum::values()),
            ],

            'address' => [
                'sometimes', 'required', 'string', 'max:255',
                new PrinterAddress($this->effectiveConnectionType()),
            ],

            'paper_width' => ['sometimes', 'integer', Rule::in([58, 80])],
            'cut_type' => ['sometimes', 'string', Rule::in(PrinterCutTypeEnum::values())],
            'encoding' => ['sometimes', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],

            // plan-052 P-39 / plan-053 T5.4 — see the trait.
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

    /**
     * The connection type the address should be validated against: the one
     * being submitted, else the one already stored on the record.
     *
     * Scoped to the resolved shop so this lookup can't be used to probe
     * another tenant's printer — the controller enforces the same scope again
     * before writing.
     */
    private function effectiveConnectionType(): ?string
    {
        $submitted = $this->input('connection_type');
        if (is_string($submitted) && $submitted !== '') {
            return $submitted;
        }

        $printerId = (string) $this->route('printer');
        if ($printerId === '') {
            return null;
        }

        $stored = Printer::query()
            ->where('branch_id', $this->attributes->get('shop_id'))
            ->whereKey($printerId)
            ->value('connection_type');

        // The model casts this column to PrinterConnectionTypeEnum, so `value()`
        // hands back an enum instance rather than the raw string.
        return $stored instanceof PrinterConnectionTypeEnum ? $stored->value : $stored;
    }
}
