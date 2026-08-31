import { ProductOptionsDialog } from "./product-options-dialog";
import type { EditingLine } from "../hooks/use-edit-order-item";
import type { ShopMenuProductSku, ToppingSelection } from "../types";

interface EditItemDialogProps {
  /** The cart line being edited, resolved together with its menu product. */
  line: EditingLine;
  onSubmit: (
    sku: ShopMenuProductSku,
    toppings: ToppingSelection[],
    note: string | undefined,
  ) => void | Promise<void>;
  onClose: () => void;
}

/**
 * ProductOptionsDialog in `edit` mode, pre-seeded from an existing cart line.
 *
 * The edit target is resolved atomically in the click handler (see
 * `useEditOrderItem`), so rendering this can no longer enter a silent
 * item-without-product state.
 */
export function EditItemDialog({ line, onSubmit, onClose }: EditItemDialogProps) {
  return (
    <ProductOptionsDialog
      product={line.menuProduct}
      skus={(line.menuProduct.skus ?? []).filter((s) => s.is_active)}
      toppingGroups={line.menuProduct.product?.topping_groups ?? []}
      mode="edit"
      initialSelections={(line.item.toppings ?? []).map((t) => ({
        topping_group_item_id: t.topping_group_item_id,
        product_sku_id: t.product_sku_id,
        quantity: t.quantity,
      }))}
      initialSelectedSkuId={
        line.menuProduct.skus?.find(
          (s) => s.product_sku_id === line.item.product_sku_id,
        )?.id
      }
      initialNote={line.item.note ?? undefined}
      onSubmit={onSubmit}
      open={true}
      onOpenChange={(next) => {
        if (!next) onClose();
      }}
    />
  );
}
