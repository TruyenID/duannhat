import { useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { useTranslation } from "@/providers/app-provider";
import type { useAddItems, useUpdateItem, useVoidItem } from "@/hooks/api/use-orders";
import type { useShopMenuProducts } from "@/hooks/api/use-shop-menus";
import {
  buildItemReplacementPayload,
  buildOrderItemSelectionUpdate,
  findMenuProductBySku,
} from "../lib/product-selection-surface";
import type {
  CustomerOrder,
  CustomerOrderItem,
  ShopMenuProduct,
  ShopMenuProductSku,
  ToppingSelection,
} from "../types";

/**
 * Resolve and snapshot the complete edit target at click time. Keeping only
 * an item id made the next render depend on a second menu query; when that
 * query was pending/stale the JSX silently rendered nothing.
 */
export interface EditingLine {
  item: CustomerOrderItem;
  menuProduct: ShopMenuProduct;
}

export interface UseEditOrderItemArgs {
  shopSlug: string;
  activeOrder: CustomerOrder | undefined;
  menuProductsQuery: ReturnType<typeof useShopMenuProducts>;
  addItems: ReturnType<typeof useAddItems>;
  updateItem: ReturnType<typeof useUpdateItem>;
  voidItem: ReturnType<typeof useVoidItem>;
  setErrorMessage: (message: string | null) => void;
  surfaceError: (e: unknown) => void;
}

export interface UseEditOrderItemResult {
  /** Non-null while the edit dialog should be on screen. */
  editingLine: EditingLine | null;
  /** Open the dialog for a cart line — resolves its menu product first. */
  openEditItem: (itemId: string) => Promise<void>;
  /** Cancel without saving. */
  closeEditItem: () => void;
  submitEditItem: (
    sku: ShopMenuProductSku,
    toppings: ToppingSelection[],
    note: string | undefined,
  ) => Promise<void>;
}

/**
 * Editing an existing cart line: resolving which ShopMenuProduct it came
 * from (so the variant + topping pickers have something to show), and
 * writing the change back.
 *
 * Kept together because the write path depends on the resolution: swapping
 * the SKU is not an update at all but an add + void pair (#1148), and that
 * only makes sense with the snapshot the open step took.
 */
export function useEditOrderItem({
  shopSlug,
  activeOrder,
  menuProductsQuery,
  addItems,
  updateItem,
  voidItem,
  setErrorMessage,
  surfaceError,
}: UseEditOrderItemArgs): UseEditOrderItemResult {
  const { t } = useTranslation();
  const qc = useQueryClient();
  const [editingLine, setEditingLine] = useState<EditingLine | null>(null);

  function cachedMenuProductGroups(): ShopMenuProduct[][] {
    return qc
      .getQueriesData<unknown>({
        queryKey: ["shop-menus", shopSlug],
      })
      .flatMap(([, cached]) => {
        if (!cached || typeof cached !== "object") return [];
        const data = (cached as { data?: unknown }).data;
        if (Array.isArray(data)) return [data as ShopMenuProduct[]];
        if (data && typeof data === "object") {
          const menuProducts = (data as { menu_products?: unknown }).menu_products;
          if (Array.isArray(menuProducts)) {
            return [menuProducts as ShopMenuProduct[]];
          }
        }
        return [];
      });
  }

  async function openEditItem(itemId: string) {
    const item = activeOrder?.items?.find((candidate) => candidate.id === itemId);
    if (!item) {
      setErrorMessage(t("pos.cart.undefined_item"));
      return;
    }

    let menuProduct = findMenuProductBySku(
      item.product_sku_id,
      menuProductsQuery.data?.data ?? [],
      ...cachedMenuProductGroups(),
    );

    // A restored order can reference a product from before the current menu
    // query was hydrated. Retry the authoritative selected-menu request once
    // before surfacing a real error to the operator.
    if (!menuProduct) {
      const refreshed = await menuProductsQuery.refetch();
      menuProduct = findMenuProductBySku(
        item.product_sku_id,
        refreshed.data?.data ?? [],
        ...cachedMenuProductGroups(),
      );
    }

    if (!menuProduct) {
      setErrorMessage(
        t("pos.cart.edit_product_unavailable", {
          sku: item.product_sku_id,
        }),
      );
      return;
    }

    setErrorMessage(null);
    setEditingLine({ item, menuProduct });
  }

  function closeEditItem() {
    setEditingLine(null);
  }

  async function submitEditItem(
    sku: ShopMenuProductSku,
    toppings: ToppingSelection[],
    note: string | undefined,
  ) {
    if (!editingLine) return;
    setErrorMessage(null);
    try {
      if (sku.product_sku_id !== editingLine.item.product_sku_id) {
        // #1148 — SKU is immutable on a line (server 409s the key). A
        // different variant = ADD the new line first (nothing is destroyed
        // if it fails), then VOID the old one with a traceable reason so
        // the kitchen/stock trail of the original dish stays honest.
        await addItems.mutateAsync(
          buildItemReplacementPayload(
            sku,
            toppings,
            note,
            // OrderItem.quantity is typed `number | string` (PHP-decimal
            // strings ride some resources) — coerce for the payload builder.
            Number(editingLine.item.quantity),
          ),
        );
        await voidItem.mutateAsync({
          itemId: editingLine.item.id,
          voidReason: t("pos.void_reason.sku_change"),
        });
      } else {
        await updateItem.mutateAsync({
          itemId: editingLine.item.id,
          body: buildOrderItemSelectionUpdate(toppings, note),
        });
      }
      setEditingLine(null);
    } catch (e) {
      surfaceError(e);
    }
  }

  return { editingLine, openEditItem, closeEditItem, submitEditItem };
}
