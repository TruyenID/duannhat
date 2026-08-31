"use client";

import { useState } from "react";
import { useTranslations } from 'next-intl';
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { useCart } from "@/context/cart-context";
import { useCurrency } from "@/lib/currency";
import CartDrawer from "./cart-drawer";
import { ShoppingCart } from "lucide-react";

export default function CartBar() {
  const { totalItems, totalPrice, justAdded } = useCart();
  const t = useTranslations('cart');
  const { format: fmt } = useCurrency();
  const [drawerOpen, setDrawerOpen] = useState(false);

  const isEmpty = totalItems === 0;

  return (
    <>
      {/* Floating cart bar */}
      <div
        className={`fixed bottom-6 right-6 z-40 transition-all duration-300 md:bottom-6 ${
          isEmpty ? "bottom-6" : "bottom-20 md:bottom-6"
        }`}
      >
        {isEmpty ? (
          <Button
            size="icon-lg"
            variant="secondary"
            className="h-12 w-12 rounded-full opacity-40 shadow-lg"
            // godx-tempo#1719 — biến thể giỏ rỗng chỉ có icon nên không có tên
            // nào cả. Nhánh còn lại đã tự có tên nhờ chữ giá + "Xem giỏ hàng".
            // Dùng `cart.empty` chứ không phải `viewCart`: nút đang disabled,
            // nói "Xem giỏ hàng" là hứa một hành động không bấm được.
            aria-label={t('empty')}
            disabled
          >
            <ShoppingCart className="h-5 w-5" />
          </Button>
        ) : (
          <button
            onClick={() => setDrawerOpen(true)}
            className={`flex items-center gap-2.5 rounded-full bg-white px-4 py-3 shadow-[0_4px_20px_rgba(0,0,0,0.15)] transition-all duration-300 hover:shadow-[0_6px_28px_rgba(0,0,0,0.2)] ${
              justAdded ? "scale-105" : "scale-100"
            }`}
          >
            <div className="relative">
              <ShoppingCart className={`h-5 w-5 text-foreground ${justAdded ? "animate-bounce" : ""}`} />
              <Badge
                className="absolute -right-2 -top-2 flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-emerald-500 p-0 text-[10px] font-bold text-white ring-2 ring-white"
              >
                {totalItems}
              </Badge>
            </div>
            <div className="flex flex-col items-start -space-y-0.5">
              <span className="text-base font-bold leading-tight text-foreground">
                {fmt(totalPrice)}
              </span>
              <span className="text-[11px] font-normal leading-tight text-muted-foreground">
                {t('viewCart')}
              </span>
            </div>
          </button>
        )}
      </div>

      {/* Cart drawer */}
      <CartDrawer open={drawerOpen} onOpenChange={setDrawerOpen} />
    </>
  );
}
