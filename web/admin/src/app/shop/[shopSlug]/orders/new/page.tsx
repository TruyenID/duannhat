"use client";
import { useMemo, useReducer, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { PageHeader } from "@/components/layout/page-header";
import { useTables } from "@/hooks/api/use-tables";
import { useShopMenu, useShopMenus } from "@/hooks/api/use-shop-menus";
import { useCreateOrder, useAddOrderItems } from "@/hooks/api/use-orders";
import { useTranslation } from "@/providers/app-provider";
import type { CustomerOrderType } from "@/services/order-service";
import type { Customer } from "@/services/customer-service";
import { cartReducer, INITIAL_CART, toCreatePayload } from "./components/cart-reducer";
import { CustomerSearchInput } from "./components/customer-search-input";
import { MenuProductTile } from "./components/menu-product-tile";
import { OrderItemRow } from "./components/order-item-row";
import { CartSummary } from "./components/cart-summary";
import { TablePicker } from "./components/table-picker";
import { Button, Card, Label, Spinner, Tabs, TabsList, TabsTrigger, Textarea } from "@godxjp/ui";
export default function NewOrderPage() {
  const { t } = useTranslation();
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const router = useRouter();
  // Order config
  const [orderType, setOrderType] = useState<CustomerOrderType>("dine_in");
  const [tableId, setTableId] = useState<string>("");
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [note, setNote] = useState("");
  // Cart
  const [cart, dispatch] = useReducer(cartReducer, INITIAL_CART);
  // Data fetching
  const { data: menusData, isLoading: menusLoading } = useShopMenus(shopSlug, {
    status: "Active",
  });
  const menus = menusData?.data ?? [];
  // Cashier-picked active menu. Falls through to the first menu until the user
  // taps a different tab — staying uncontrolled on the first render lets the
  // page work even when the shop only has a single menu.
  const [selectedMenuId, setSelectedMenuId] = useState<string>("");
  const activeMenuId = selectedMenuId || menus[0]?.id || "";
  // Pull the full menu detail (eager-loaded menu_products → skus) and flatten
  // every active SKU into a picker row. Inactive products / inactive SKUs are
  // hidden from the order screen because shop staff already toggled them off.
  const { data: menuDetail, isLoading: itemsLoading } = useShopMenu(shopSlug, activeMenuId);
  // Group SKUs under their product so the picker stays product-first —
  // cashier taps a product card, then picks a variant when there are several.
  // Inactive products / inactive SKUs are dropped because shop staff already
  // toggled them off upstream.
  const menuProducts = useMemo(() => {
    const products = menuDetail?.data?.menu_products ?? [];
    return products.flatMap((p) => {
      if (!p.is_active) return [];
      const productName = p.product?.name?.trim() || p.product_id;
      const skus = (p.skus ?? [])
        .filter((s) => s.is_active)
        .map((s) => {
          const variantName = s.product_sku?.name?.trim() || s.product_sku?.sku || "Default";
          return {
            id: s.id,
            skuId: s.product_sku_id,
            unitPrice: Number(s.effective_selling_price ?? s.selling_price),
            name: variantName,
            cartName: `${productName} — ${variantName}`,
          };
        });
      if (skus.length === 0) return [];
      return [
        {
          id: p.id,
          productId: p.product_id,
          productName,
          images: (p.product?.gallery ?? []).map((img) => ({
            id: img.id,
            url: img.url,
          })),
          skus,
        },
      ];
    });
  }, [menuDetail]);
  const { data: tablesData } = useTables(shopSlug);
  const tables = tablesData?.data ?? [];
  const createOrder = useCreateOrder(shopSlug);
  const addItems = useAddOrderItems(shopSlug);
  // Validation
  const tableRequired = orderType === "dine_in" && !tableId;
  const canSubmit = cart.items.length > 0 && !tableRequired;
  const isSubmitting = createOrder.isPending || addItems.isPending;
  async function handleSubmit() {
    const tableIds = orderType === "dine_in" && tableId ? [tableId] : undefined;
    try {
      const result = await createOrder.mutateAsync({
        order_type: orderType,
        customer_id: customer?.id ?? null,
        table_ids: tableIds,
        note: note || null,
      });
      await addItems.mutateAsync({
        orderId: result.data.id,
        data: { items: toCreatePayload(cart) },
      });
      dispatch({ type: "CLEAR" });
      router.push(`/shop/${shopSlug}/orders/${result.data.id}`);
    } catch {
      // toast handled by hooks
    }
  }
  return (
    <>
      <PageHeader title={t("shop.orders.new.title")} backHref={`/shop/${shopSlug}/orders`} />
      <div className="grid min-h-0 flex-1 grid-cols-1 gap-4 overflow-hidden p-4 lg:grid-cols-12">
        {/* Left pane — menu items (scroll riêng) */}
        <div className="overflow-y-auto lg:col-span-7">
          <Card className="p-4">
            <div className="mb-3 flex items-center justify-between gap-3">
              <h2 className="text-sm font-semibold">{t("shop.orders.new.menu_heading")}</h2>
              {menus.length > 1 && (
                <Tabs value={activeMenuId} onValueChange={setSelectedMenuId}>
                  <TabsList className="h-7">
                    {menus.map((m) => (
                      <TabsTrigger key={m.id} value={m.id} className="px-3 text-xs">
                        {m.name}
                      </TabsTrigger>
                    ))}
                  </TabsList>
                </Tabs>
              )}
            </div>
            {(menusLoading || itemsLoading) && (
              <div className="flex items-center justify-center gap-2 py-8 text-sm text-muted-foreground">
                <Spinner className="size-4" /> {t("shop.orders.new.loading_menu")}
              </div>
            )}
            {!menusLoading && !activeMenuId && (
              <p className="py-8 text-center text-sm text-muted-foreground">
                {t("shop.orders.new.no_active_menu")}
              </p>
            )}
            {!itemsLoading && menuProducts.length === 0 && activeMenuId && (
              <p className="py-8 text-center text-sm text-muted-foreground">
                {t("shop.orders.new.empty_menu")}
              </p>
            )}
            {menuProducts.length > 0 && (
              <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                {menuProducts.map((p) => (
                  <MenuProductTile
                    key={p.id}
                    productId={p.productId}
                    productName={p.productName}
                    images={p.images}
                    skus={p.skus}
                    onAdd={(sku) =>
                      dispatch({
                        type: "ADD_SKU",
                        skuId: sku.skuId,
                        name: sku.cartName,
                        unitPrice: sku.unitPrice,
                      })
                    }
                  />
                ))}
              </div>
            )}
          </Card>
        </div>
        {/* Right pane — order config + cart (scroll riêng) */}
        <div className="flex flex-col overflow-y-auto lg:col-span-5">
          <Card className="flex flex-1 flex-col p-4">
            {/* Order config */}
            <div className="shrink-0 space-y-4">
              {/* Order type toggle */}
              <div className="space-y-1.5">
                <Label>{t("shop.orders.new.order_type")}</Label>
                <div className="flex gap-2">
                  <Button
                    variant={orderType === "dine_in" ? "default" : "outline"}
                    size="sm"
                    type="button"
                    onClick={() => setOrderType("dine_in")}
                  >
                    {t("shop.orders.new.dine_in")}
                  </Button>
                  <Button
                    variant={orderType === "takeaway" ? "default" : "outline"}
                    size="sm"
                    type="button"
                    onClick={() => {
                      setOrderType("takeaway");
                      setTableId("");
                    }}
                  >
                    {t("shop.orders.new.takeaway")}
                  </Button>
                </div>
              </div>
              {/* Table picker (dine-in only) */}
              {orderType === "dine_in" && (
                <div className="space-y-1.5">
                  <Label>{t("shop.orders.new.table_label")}</Label>
                  <TablePicker
                    tables={tables}
                    value={tableId}
                    onChange={setTableId}
                    error={
                      tableRequired && cart.items.length > 0
                        ? t("shop.orders.new.pick_table_hint")
                        : undefined
                    }
                  />
                </div>
              )}
              {/* Customer lookup */}
              <div className="space-y-1.5">
                <Label>{t("shop.orders.new.customer_label")}</Label>
                <CustomerSearchInput
                  shopSlug={shopSlug}
                  selectedCustomer={customer}
                  onSelect={setCustomer}
                  onClear={() => setCustomer(null)}
                />
              </div>
              {/* Note */}
              <div className="space-y-1.5">
                <Label>{t("shop.orders.new.note_label")}</Label>
                <Textarea
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                  rows={2}
                  maxLength={500}
                  className="field-sizing-fixed text-sm"
                />
                <p className="text-right text-xs text-muted-foreground">{note.length}/500</p>
              </div>
            </div>
            {/* Cart items */}
            <div className="mt-4 min-h-0 flex-1">
              <h3 className="mb-1 text-sm font-semibold">{t("shop.orders.new.cart")}</h3>
              {cart.items.length === 0 ? (
                <p className="py-4 text-center text-sm text-muted-foreground">
                  {t("shop.orders.new.cart_empty")}
                </p>
              ) : (
                <div className="divide-y">
                  {cart.items.map((item) => (
                    <OrderItemRow
                      key={item.product_sku_id}
                      item={item}
                      onChangeQuantity={(qty) =>
                        dispatch({
                          type: "CHANGE_QUANTITY",
                          skuId: item.product_sku_id,
                          quantity: qty,
                        })
                      }
                      onRemove={() =>
                        dispatch({
                          type: "REMOVE_SKU",
                          skuId: item.product_sku_id,
                        })
                      }
                    />
                  ))}
                </div>
              )}
            </div>
            {/* Summary + submit — always visible at bottom */}
            <div className="shrink-0">
              <CartSummary
                cart={cart}
                isPending={isSubmitting}
                disabled={!canSubmit}
                onSubmit={handleSubmit}
              />
            </div>
          </Card>
        </div>
      </div>
    </>
  );
}
