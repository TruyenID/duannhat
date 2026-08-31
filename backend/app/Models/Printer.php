<?php

/**
 * Printer Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Http\Resources\PrinterResource;
use App\Omnify\Modules\Printer\Models\PrinterBaseModel;
use App\Services\Printing\CloudPrntService;
use App\Services\Printing\Enums\PrintTransport;
use App\Services\Printing\Enums\UposPrinterStatus;
use App\Services\Printing\PrinterCapabilityProfile;
use Database\Factories\PrinterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Printer — add project-specific model logic here.
 */
class Printer extends PrinterBaseModel
{
    use HasFactory;

    /**
     * plan-052 P-16 — the CloudPRNT token, in the clear, for the single
     * response that minted it.
     *
     * Not a column and never persisted here: the stored value is
     * `print_token`, and it is redacted from every API representation
     * ({@see PrinterResource}). A secret an operator can
     * re-read from a list endpoint is a secret that ends up in a screenshot in
     * a group chat, which is why the peripheral-device registry made the same
     * choice before this one did.
     */
    public ?string $revealedPrintToken = null;

    /**
     * plan-053 T5.4 — a printer on the CloudPRNT transport ALWAYS has a
     * credential.
     *
     * It is minted here, at the model, rather than in the controller, because
     * the invariant has to survive every writer: the shop API, an ops artisan
     * command, a seeder. The failure it prevents is quiet and expensive — a
     * printer configured for `cloudprnt` with a null token cannot authenticate,
     * so it polls forever, gets 401 forever, and the shop sees a machine that
     * is plugged in, online, and simply never prints.
     *
     * 64 hex-ish characters from `Str::random` (base62, ~380 bits) clears
     * P-16's "≥32 bytes" with room to spare and fits the 128-char column.
     *
     * Switching a printer AWAY from cloudprnt deliberately does NOT clear the
     * token: {@see CloudPrntService::authenticate()}
     * already refuses any printer whose transport is not cloudprnt, so the
     * revocation is immediate either way — and keeping the value means moving a
     * machine back does not silently hand it a different credential than the
     * one taped to its underside.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (self $printer): void {
            if ($printer->transport !== PrintTransport::CloudPrnt) {
                return;
            }

            if (is_string($printer->print_token) && $printer->print_token !== '') {
                return;
            }

            $printer->print_token = Str::random(64);
            $printer->revealedPrintToken = $printer->print_token;
        });
    }

    /**
     * plan-052 (#1166) adds four columns to `printers` by hand-written
     * migration; the Omnify-generated base model does not know about them yet
     * (the YAML alignment is deferred — see the migration's docblock).
     *
     * Extending the generated accessors rather than redeclaring them keeps the
     * base authoritative for everything else, so a regen of the printer module
     * cannot silently drop a plan-052 column.
     */
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'transport' => PrintTransport::class,
            'model_profile' => 'array',
            'last_status' => UposPrinterStatus::class,
        ]);
    }

    /**
     * @return list<string>
     */
    public function getFillable(): array
    {
        return array_values(array_unique(array_merge(
            parent::getFillable(),
            ['transport', 'print_token', 'model_profile', 'last_status'],
        )));
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PrinterFactory
    {
        return PrinterFactory::new();
    }

    /**
     * DESIGN §3b — the machine's capabilities, resolved. A printer that has
     * never been through the setup wizard still answers here (escpos_generic,
     * P-29): a shop that has not filled in a form must still be able to print.
     */
    public function capabilityProfile(): PrinterCapabilityProfile
    {
        return PrinterCapabilityProfile::resolve($this->model_profile);
    }
}
