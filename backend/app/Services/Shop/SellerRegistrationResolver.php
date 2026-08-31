<?php

namespace App\Services\Shop;

use App\Models\Branch;
use App\Models\Brand;

/**
 * #1152 — the インボイス T+13 registration number (適格請求書発行事業者登録番号)
 * effective for a branch.
 *
 * Resolution: branch override → brand default → null. A franchise branch is
 * its own 事業者 and carries its own number; a directly-run branch inherits
 * the brand's.
 *
 * Where the resolved value goes — exactly two callers today:
 *  1. `Api\V1\Workstation\BranchController` serializes it into the branch feed
 *     as `shop_settings.seller_registration_number`, the key the workstation's
 *     print paths already read. That caller applies the display toggle
 *     (`show_seller_registration_on_receipt` off ⇒ it sends '' so even old
 *     workstation builds simply print nothing).
 *  2. `Services\Print\TemplateValidator` evaluates it for the catalog's
 *     `seller_has_registration_number` condition (`require_enabled_when`).
 *
 * This docblock USED to say the value is snapshotted onto 適格請求書 + 赤伝 at
 * issuance. That is no longer true: #1779 removed the formal-invoice path
 * entirely (PosInvoiceService, PosReturnInvoiceService, the CustomerInvoice
 * model), so nothing issues an invoice to snapshot onto — 赤伝 is now printed
 * straight from the workstation with no record written. The resolver itself
 * was never part of that removal and still runs on both paths above.
 */
class SellerRegistrationResolver
{
    public function resolve(Branch $branch): ?string
    {
        // #1301 — a model loaded with a partial select() answers a missing
        // attribute with null, not an error, so "the column was not selected"
        // and "the shop has no number" arrive here as the same value. That is
        // how a stored 登録番号 was served as an empty string and every receipt
        // printed without the number 適格請求書 requires — silently, with the
        // data sitting correctly in the database the whole time.
        //
        // Read the column when it is genuinely absent from the instance rather
        // than guess. Costs one query only in that case; callers that hydrate
        // the branch normally never reach it.
        $own = array_key_exists('invoice_registration_number', $branch->getAttributes())
            ? $branch->invoice_registration_number
            : Branch::query()->whereKey($branch->getKey())->value('invoice_registration_number');

        $own = trim((string) ($own ?? ''));
        if ($own !== '') {
            return $own;
        }

        $brandNumber = Brand::query()
            ->where('console_brand_id', $branch->console_brand_id)
            ->value('invoice_registration_number');

        $brandNumber = trim((string) ($brandNumber ?? ''));

        return $brandNumber !== '' ? $brandNumber : null;
    }
}
