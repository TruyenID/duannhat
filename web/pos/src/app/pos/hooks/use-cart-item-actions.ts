import { useCallback, useState } from "react";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { addItemSurfaceFields } from "@/services/order-service";
import type {
  useAddItems,
  useReleaseCoupon,
  useUpdateItem,
  useVoidItem,
} from "@/hooks/api/use-orders";
import type { StackingConflict } from "../components/stacking-conflict-dialog";
import type { VoidItemSubmit } from "../components/void-item-dialog";
import type {
  CustomerOrder,
  ShopMenuProduct,
  ShopMenuProductSku,
  ToppingSelection,
} from "../types";

export interface UseCartItemActionsArgs {
  activeOrder: CustomerOrder | undefined;
  addItems: ReturnType<typeof useAddItems>;
  updateItem: ReturnType<typeof useUpdateItem>;
  voidItem: ReturnType<typeof useVoidItem>;
  releaseCoupon: ReturnType<typeof useReleaseCoupon>;
  setErrorMessage: (message: string | null) => void;
  surfaceError: (e: unknown) => void;
}

export interface UseCartItemActionsResult {
  addItem: (
    mp: ShopMenuProduct,
    sku: ShopMenuProductSku,
    toppings?: ToppingSelection[],
    note?: string,
  ) => Promise<void>;
  changeQuantity: (itemId: string, next: number) => Promise<void>;
  updateItemStatus: (
    itemId: string,
    status: "pending" | "preparing" | "ready" | "served",
  ) => Promise<void>;

  /** Non-null while the void-item dialog is open. */
  voidItemTarget: { id: string; label: string } | null;
  openVoidItemDialog: (itemId: string, label: string) => void;
  clearVoidItemTarget: () => void;
  confirmVoidItem: (payload: VoidItemSubmit) => Promise<void>;

  /** Non-null while the stacking-conflict dialog is open. */
  stackingConflict: StackingConflict | null;
  stackingConflictPending: boolean;
  dismissStackingConflict: () => void;
  resolveStackingConflict: () => Promise<void>;
}

/**
 * What the cashier does to LINES in the cart: add one, change its quantity,
 * bump its prep status, void it.
 *
 * Editing a line's variant/toppings is a separate hook (`useEditOrderItem`) —
 * it needs the menu catalog to resolve what the line came from, which none of
 * these do.
 */
export function useCartItemActions({
  activeOrder,
  addItems,
  updateItem,
  voidItem,
  releaseCoupon,
  setErrorMessage,
  surfaceError,
}: UseCartItemActionsArgs): UseCartItemActionsResult {
  const { t } = useTranslation();

  const [voidItemTarget, setVoidItemTarget] = useState<{ id: string; label: string } | null>(
    null,
  );

  // plan-019 — when an addItem of a Happy Hour item gets rejected by the
  // exclusive-stacking guard (422 cannot_add_promotion_item_with_coupon),
  // stash the original payload so the StackingConflictDialog's "Auto-remove
  // coupon & add" CTA can re-fire it after releaseCoupon succeeds.
  const [stackingConflict, setStackingConflict] = useState<{
    conflict: StackingConflict;
    retry: () => Promise<void>;
  } | null>(null);
  const [stackingConflictPending, setStackingConflictPending] = useState(false);

  async function addItem(
    mp: ShopMenuProduct,
    sku: ShopMenuProductSku,
    toppings?: ToppingSelection[],
    note?: string,
  ) {
    if (!activeOrder) return;
    setErrorMessage(null);

    // #1320 — WHICH surface the cashier tapped. A spotlight SKU carries the
    // membership id; a menu SKU does not. The two ids name rows in DIFFERENT
    // tables, so they must ride in different fields: putting a spotlight id in
    // `menu_product_sku_id` makes the backend look up a MenuProductSku that
    // does not exist and price the line off something else entirely.
    //
    // Deliberately no `selling_price`: the workstation re-reads the promo price
    // AND re-checks that the schedule window is still open (#1392). The browser
    // cannot check the window — its catalog is cached — so quoting a price from
    // here would be quoting one the shop may no longer offer.
    const payload = [
      {
        product_sku_id: sku.product_sku_id,
        // sku.id here is the MenuProductSku id — pins backend's
        // unit_price lookup to THIS menu's override so the price
        // matches what staff saw in the variant picker even if the
        // same ProductSku is priced differently in other menus.
        ...addItemSurfaceFields(sku),
        quantity: 1,
        // Plan 015 — forward chosen topping selections (empty/undef
        // for legacy non-topping flows). Backend validates against
        // ProductToppingGroup attachments + persists OrderItemTopping.
        toppings: toppings && toppings.length > 0 ? toppings : undefined,
        // Customer kitchen note ("ít cay", "no onion", …). Becomes
        // part of the BR-OI06 merge key — same SKU + same toppings +
        // different note = separate cart line.
        note: note,
      },
    ];

    try {
      await addItems.mutateAsync(payload);
    } catch (e) {
      // plan-019 — stacking conflict surfaces a confirm Dialog with an
      // auto-remove-coupon CTA instead of a generic error toast. The
      // dialog's "yes" handler releases the coupon then retries the same
      // addItem payload so staff doesn't have to re-pick variants/toppings.
      if (
        e instanceof ApiError &&
        e.status === 422 &&
        (e.body as { error_code?: string })?.error_code === "cannot_add_promotion_item_with_coupon"
      ) {
        setStackingConflict({
          conflict: {
            itemName: mp.product?.name ?? sku.product_sku?.name ?? "—",
            couponCode: activeOrder.coupon_code_snapshot ?? "",
          },
          retry: async () => {
            await addItems.mutateAsync(payload);
          },
        });
        return;
      }
      surfaceError(e);
    }
  }

  async function resolveStackingConflict() {
    if (!stackingConflict || !activeOrder) return;
    setStackingConflictPending(true);
    try {
      await releaseCoupon.mutateAsync();
      await stackingConflict.retry();
      setStackingConflict(null);
    } catch (e) {
      surfaceError(e);
    } finally {
      setStackingConflictPending(false);
    }
  }

  function dismissStackingConflict() {
    setStackingConflict(null);
  }

  async function changeQuantity(itemId: string, next: number) {
    if (!activeOrder) return;
    setErrorMessage(null);
    try {
      await updateItem.mutateAsync({ itemId, body: { quantity: next } });
    } catch (e) {
      surfaceError(e);
    }
  }

  async function updateItemStatus(
    itemId: string,
    status: "pending" | "preparing" | "ready" | "served",
  ) {
    if (!activeOrder) return;
    setErrorMessage(null);
    try {
      await updateItem.mutateAsync({ itemId, body: { status } });
      toast.success(t("pos.toast.item_status_updated"));
    } catch (e) {
      surfaceError(e);
    }
  }

  function openVoidItemDialog(itemId: string, label: string) {
    setVoidItemTarget({ id: itemId, label });
  }

  // Stable identity: the page resets this from its tab-change effect, where an
  // unstable callback would either lie in the dep array or re-run the effect.
  const clearVoidItemTarget = useCallback(() => {
    setVoidItemTarget(null);
  }, []);

  async function confirmVoidItem(payload: VoidItemSubmit) {
    if (!activeOrder || !voidItemTarget) return;
    setErrorMessage(null);
    try {
      await voidItem.mutateAsync({
        itemId: voidItemTarget.id,
        voidReasonId: payload.voidReasonId,
        voidReason: payload.note,
      });
      setVoidItemTarget(null);
    } catch (e) {
      surfaceError(e);
    }
  }

  return {
    addItem,
    changeQuantity,
    updateItemStatus,
    voidItemTarget,
    openVoidItemDialog,
    clearVoidItemTarget,
    confirmVoidItem,
    stackingConflict: stackingConflict?.conflict ?? null,
    stackingConflictPending,
    dismissStackingConflict,
    resolveStackingConflict,
  };
}
