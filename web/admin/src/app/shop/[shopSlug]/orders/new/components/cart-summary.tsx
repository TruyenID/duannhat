"use client";
import { useTranslation } from "@/providers/app-provider";
import { useShopCurrency } from "@/providers/shop-currency-provider";
import { formatCurrency } from "@/lib/currency";
import type { CartState } from "./cart-reducer";
import { subtotal } from "./cart-reducer";
import { Button, Spinner } from "@godxjp/ui";
export interface CartSummaryProps {
  cart: CartState;
  isPending?: boolean;
  disabled?: boolean;
  onSubmit: () => void;
}
export function CartSummary({ cart, isPending, disabled, onSubmit }: CartSummaryProps) {
  const { t, locale } = useTranslation();
  // Cart money is the SHOP's, not the reader's language (#1260) — the product
  // tiles beside this summary already resolve it this way.
  const shopCurrency = useShopCurrency();
  const total = subtotal(cart);
  const itemCount = cart.items.reduce((sum, i) => sum + i.quantity, 0);
  return (
    <div data-slot="cart-summary" className="mt-3 space-y-2 border-t pt-3">
      <div className="flex items-center justify-between text-sm">
        <span className="text-muted-foreground">
          {t("shop.orders.new.items_count", { count: itemCount })}
        </span>
        <span className="font-semibold tabular-nums">
          {formatCurrency(total, locale, shopCurrency ?? undefined)}
        </span>
      </div>
      <Button
        className="w-full"
        disabled={disabled || isPending || cart.items.length === 0}
        onClick={onSubmit}
      >
        {isPending && <Spinner className="mr-2 size-3.5" />}
        {t("shop.orders.new.submit")}
      </Button>
    </div>
  );
}
