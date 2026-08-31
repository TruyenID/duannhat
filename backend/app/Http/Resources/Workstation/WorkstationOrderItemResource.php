<?php

namespace App\Http\Resources\Workstation;

use App\Http\Resources\CustomerOrderItemResource;
use Illuminate\Http\Request;

/**
 * An order line as the WORKSTATION PULL wants it (#2713).
 *
 * Identical to `CustomerOrderItemResource` except for the nested SKU, which is
 * collapsed to the three fields the workstation actually decodes.
 *
 * Why this exists: `CustomerOrderItemResource` embeds the full
 * `ProductSkuResource` → `ProductResource`, and `ProductResourceBase`
 * (generated, MUST NOT be edited) serializes `thumbnail`, `gallery` and
 * `translations` with NO `whenLoaded` guard. `thumbnail` is a MorphOne,
 * `gallery` a MorphMany, `translations` an Astrotomic relation — so on the 5 s
 * kitchen tick each one lazy-loaded a query per distinct SKU/product and then
 * shipped bytes the client drops on the floor. Measured on a 3-order × 2-line
 * fixture: 34 queries / 25,033 bytes before, and the workstation read four
 * strings out of it.
 *
 * The contract on the other side is `cloudProductSkuStub` in
 * `workstation/internal/service/sync_pull.go:598-604`:
 *
 *     type cloudProductSkuStub struct {
 *         Name    string            `json:"name"`     // variant label
 *         Sku     string            `json:"sku"`      // human SKU code
 *         Product *struct{ Name string `json:"name"` } `json:"product"`
 *     }
 *
 * consumed by `nameFromStub` (:610) → `resolveMenuItemName` (:844) → the item
 * upsert (:1208). Nothing else on the nested object is read by any Go field.
 *
 * NOTE — this endpoint deliberately does NOT send `menu_item_name`, and adding
 * it would be a regression, not an improvement. `resolveMenuItemName` takes the
 * Cloud-sent name FIRST (:845) and only then falls to the stub, but
 * `CustomerOrderItem::getMenuItemNameAttribute` resolves `$sku->name` (the bare
 * variant label, e.g. "Large") ahead of the product name, whereas
 * `nameFromStub` builds "Product · Variant". Sending it would silently rename
 * every synced line to its variant label on kitchen tickets and bills.
 */
class WorkstationOrderItemResource extends CustomerOrderItemResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = parent::toArray($request);

        // parent put the full ProductSkuResource here. It is an unserialized
        // JsonResource object at this point — overwriting the key discards it
        // before `thumbnail` / `gallery` / `translations` are ever touched.
        $base['product_sku'] = $this->whenLoaded('productSku', fn () => [
            'name' => $this->productSku->name,
            'sku' => $this->productSku->sku,
            'product' => $this->productSku->relationLoaded('product') && $this->productSku->product !== null
                ? ['name' => $this->productSku->product->name]
                : null,
        ]);

        // The SAME bug, one level down. `orderItemToppings` is the raw Omnify
        // relation key, and it is a straight DUPLICATE of the flat `toppings`
        // array the parent hand-builds — the one the workstation actually reads
        // (`cloudOrderItemPayload.Toppings`, sync_pull.go:572). Nothing decodes
        // `orderItemToppings`: the only mention of that name in the Go tree is
        // a comment about the Eloquent relation (sync_pull.go:569).
        //
        // It is not merely redundant, it is expensive: `OrderItemToppingResourceBase`
        // embeds `toppingGroupItem` → `ToppingGroupItemResourceBase` → `product`
        // → `ProductResource`, and the controller eager-loads exactly that chain
        // (for the flat array's group/product NAMES), so `whenLoaded` passes and
        // every topping drags a second full product blob — thumbnail, gallery and
        // translations included — onto the 5 s tick.
        unset($base['orderItemToppings']);

        return $base;
    }
}
