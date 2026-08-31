<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\Printing\Enums\PrintTransport;
use Illuminate\Validation\Rule;

/**
 * plan-052 P-39 / plan-053 T5.4 (#1171) — the config-layer gate on
 * `printers.transport`, shared by the store and update requests so the two can
 * never disagree about which transports exist.
 *
 * ── What changed, and what did not ────────────────────────────────────────
 *
 * Until T5.4 the field was not in `rules()` at all, so it never survived
 * `validated()` and never reached the model. That WAS a real protection, but an
 * accidental one — `Printer::getFillable()` deliberately merges `transport`
 * back in for the workstation replica path, and the omnify-generated request
 * base declares it `required|string`, so a future `rules()` calling
 * `parent::rules()` (the ordinary thing to write) would have opened the field
 * to any string at all, `epos_http` included.
 *
 * Opening the field for `cloudprnt` removes that accident. It is replaced here
 * by a deliberate gate, and the gate is DERIVED from
 * {@see PrintTransport::isSelectable()} rather than written out as a literal
 * list: a hand-written `Rule::in(['ws_lan', 'cloudprnt'])` is a second place
 * that has to be edited when a renderer lands, and the failure mode of
 * forgetting is a transport that stays refused with no reason recorded
 * anywhere.
 *
 * ── The message matters as much as the refusal ────────────────────────────
 *
 * P-39 asks for "refused at the config layer, fail-closed, WITH A CLEAR
 * MESSAGE". Laravel's default (`The selected transport is invalid`) sends an
 * operator to look at their printer, which is fine — the printer is fine. The
 * feature is not built. Say so.
 */
trait ValidatesPrinterTransport
{
    /**
     * @return array<int, mixed>
     */
    protected function transportRules(): array
    {
        return ['sometimes', 'string', Rule::in(PrintTransport::selectableValues())];
    }

    /**
     * @return array<string, string>
     */
    protected function transportMessages(): array
    {
        $refused = PrintTransport::refusedValues();

        if ($refused === []) {
            return [];
        }

        return [
            'transport.in' => __(
                'Transport :refused cannot be selected yet: this server has no renderer for the payload those '
                .'printers speak, so a job sent over them would never be printable. Available transports: :allowed.',
                [
                    'refused' => implode(' / ', $refused),
                    'allowed' => implode(', ', PrintTransport::selectableValues()),
                ],
            ),
        ];
    }
}
