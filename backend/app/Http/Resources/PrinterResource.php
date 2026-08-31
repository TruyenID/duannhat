<?php

/**
 * Printer Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Models\Printer;
use App\Omnify\Modules\Printer\Resources\PrinterResourceBase;
use Illuminate\Http\Request;

/**
 * PrinterResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class PrinterResource extends PrinterResourceBase
{
    /**
     * plan-052 (#1166) — the four transport/capability columns are not in the
     * Omnify schema yet (the YAML alignment is deferred; see the migration).
     * They must still reach the workstation, which is where the profile is
     * APPLIED and which has to keep working with Cloud unreachable.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = array_merge(parent::toArray($request), [
            'transport' => $this->transport?->value,
            'model_profile' => $this->model_profile,
            'last_status' => $this->last_status?->value,
        ]);

        /*
         * plan-052 P-16 / plan-053 T5.4 — the CloudPRNT credential leaves this
         * API exactly once, in the response that minted it.
         *
         * The generated base puts `print_token` in `schemaArray()` like any
         * other column, so until T5.4 every printer list and every printer
         * detail response carried it. That was harmless only by accident:
         * nothing minted a token, so the field was always null. The moment the
         * transport opened it would have become a shop-wide secret readable by
         * anyone who can list printers — and readable again tomorrow, which is
         * the property that turns a credential into a screenshot.
         *
         * `PeripheralDeviceResource` already made this call for the same class
         * of secret ("keep `secret` off the API surface"); this is the same
         * decision, plus the reveal-once P-16 explicitly asks for.
         */
        unset($payload['print_token']);

        if ($this->resource instanceof Printer && is_string($this->resource->revealedPrintToken)) {
            $payload['print_token'] = $this->resource->revealedPrintToken;
        }

        return $payload;
    }
}
