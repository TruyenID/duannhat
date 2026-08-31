"use client";
import { Minus, Plus, Trash2 } from "lucide-react";
import { useTranslation } from "@/providers/app-provider";
import { useShopCurrency } from "@/providers/shop-currency-provider";
import { formatCurrency } from "@/lib/currency";
import type { CartItem } from "./cart-reducer";
import { Button } from "@godxjp/ui";
export interface OrderItemRowProps {
  item: CartItem;
  onChangeQuantity: (quantity: number) => void;
  onRemove: () => void;
}
export function OrderItemRow({ item, onChangeQuantity, onRemove }: OrderItemRowProps) {
  const { t, locale } = useTranslation();
  // Line money is the SHOP's, not the reader's language (#1260).
  const shopCurrency = useShopCurrency();
  return (
    <div data-slot="order-item-row" className="flex items-center gap-3 py-2 text-sm">
      <div className="min-w-0 flex-1">
        <p className="truncate font-medium">{item.name}</p>
        <p className="text-xs text-muted-foreground tabular-nums">
          {formatCurrency(item.unit_price, locale, shopCurrency ?? undefined)}{" "}
          {t("shop.orders.new.each")}
        </p>
      </div>
      <div className="flex items-center gap-1">
        <Button
          variant="outline"
          size="icon"
          className="size-6"
          onClick={() => onChangeQuantity(item.quantity - 1)}
        >
          <Minus className="size-3" />
        </Button>
        <span className="w-8 text-center tabular-nums">{item.quantity}</span>
        <Button
          variant="outline"
          size="icon"
          className="size-6"
          onClick={() => onChangeQuantity(item.quantity + 1)}
        >
          <Plus className="size-3" />
        </Button>
      </div>
      <span className="w-20 text-right font-medium tabular-nums">
        {formatCurrency(item.quantity * item.unit_price, locale, shopCurrency ?? undefined)}
      </span>
      <Button
        variant="ghost"
        size="icon"
        className="size-6 text-muted-foreground"
        onClick={onRemove}
      >
        <Trash2 className="size-3" />
      </Button>
    </div>
  );
}
