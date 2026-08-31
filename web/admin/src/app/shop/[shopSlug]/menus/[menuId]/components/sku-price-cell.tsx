"use client";

import { useEffect, useState, type KeyboardEvent } from "react";
import { RotateCcw } from "lucide-react";
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Spinner,
} from "@godxjp/ui";
import { useResetShopMenuSkuPrice, useUpdateShopMenuSkuPrice } from "@/hooks/api/use-shop-menus";
import { useTranslation } from "@/providers/app-provider";
import type { ShopMenuProductSku } from "@/types/shop";
import { cn } from "@/lib/utils";
import { formatPrice } from "./helpers";

export interface SkuPriceCellProps {
  shopSlug: string;
  menuId: string;
  menuProductId: string;
  sku: ShopMenuProductSku;
}

export function SkuPriceCell({ shopSlug, menuId, menuProductId, sku }: SkuPriceCellProps) {
  const { t } = useTranslation();
  const updatePrice = useUpdateShopMenuSkuPrice(shopSlug, menuId);
  const resetPrice = useResetShopMenuSkuPrice(shopSlug, menuId);
  const [isEditing, setIsEditing] = useState(false);
  const [draft, setDraft] = useState(String(sku.selling_price ?? 0));
  const [pendingPrice, setPendingPrice] = useState<number | null>(null);
  const [confirmReset, setConfirmReset] = useState(false);

  useEffect(() => {
    if (!isEditing) setDraft(String(sku.selling_price ?? 0));
  }, [sku.selling_price, isEditing]);

  const commit = () => {
    const next = Number(draft);
    if (!Number.isFinite(next) || next <= 0) {
      setDraft(String(sku.selling_price ?? 0));
      setIsEditing(false);
      return;
    }
    if (next === sku.selling_price) {
      setIsEditing(false);
      return;
    }
    setPendingPrice(next);
    setIsEditing(false);
  };

  const handleKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Enter" && !e.nativeEvent.isComposing) {
      e.preventDefault();
      commit();
    }
    if (e.key === "Escape") {
      e.preventDefault();
      setDraft(String(sku.selling_price ?? 0));
      setIsEditing(false);
    }
  };

  const confirmOverwrite = () => {
    if (pendingPrice == null) return;
    updatePrice.mutate(
      {
        menuProductId,
        menuProductSkuId: sku.id,
        data: { selling_price: pendingPrice },
      },
      {
        onSuccess: () => setPendingPrice(null),
        onError: () => setPendingPrice(null),
      }
    );
  };

  const cancelOverwrite = () => {
    if (updatePrice.isPending) return;
    setPendingPrice(null);
    setDraft(String(sku.selling_price ?? 0));
  };

  const confirmResetPrice = () => {
    resetPrice.mutate(
      { menuProductId, menuProductSkuId: sku.id },
      {
        onSuccess: () => setConfirmReset(false),
        onError: () => setConfirmReset(false),
      }
    );
  };

  const cancelResetPrice = () => {
    if (resetPrice.isPending) return;
    setConfirmReset(false);
  };

  return (
    <div className="flex flex-wrap items-center justify-end gap-x-1.5 gap-y-1">
      {sku.is_price_overridden && (
        <Button
          variant="ghost"
          size="sm"
          className="h-6 gap-1 px-1.5 text-[10px]"
          onClick={() => setConfirmReset(true)}
          disabled={resetPrice.isPending || updatePrice.isPending}
          title={
            sku.default_price != null
              ? t("shop.menu.detail.master_price_label", { price: formatPrice(sku.default_price) })
              : t("shop.menu.detail.reset_to_default")
          }
        >
          <RotateCcw className="size-3" />
        </Button>
      )}
      {sku.is_price_overridden && (
        <span className="rounded-full border border-amber-300 bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800">
          {t("shop.menu.detail.overridden_label")}
        </span>
      )}
      {isEditing ? (
        <Input
          autoFocus
          type="number"
          inputMode="decimal"
          min={0}
          step="0.01"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onFocus={(e) => e.currentTarget.select()}
          onBlur={commit}
          onKeyDown={handleKeyDown}
          disabled={updatePrice.isPending}
          className="h-7 w-24 text-right text-sm tabular-nums"
        />
      ) : (
        <button
          type="button"
          className={cn(
            "inline-flex h-7 min-w-16 items-center justify-end rounded-md border border-transparent px-2 text-sm font-medium tabular-nums hover:border-border hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none",
            (updatePrice.isPending || resetPrice.isPending) && "opacity-60"
          )}
          onClick={() => setIsEditing(true)}
          disabled={updatePrice.isPending || resetPrice.isPending}
        >
          {formatPrice(sku.selling_price)}
        </button>
      )}

      <Dialog
        open={pendingPrice != null}
        onOpenChange={(open) => {
          if (!open) cancelOverwrite();
        }}
      >
        <DialogContent className="sm:max-w-md" data-slot="sku-overwrite-price-dialog">
          <DialogHeader>
            <DialogTitle>{t("shop.menu.detail.overwrite_price.title")}</DialogTitle>
            <DialogDescription>
              {t("shop.menu.detail.overwrite_price.description", {
                current: formatPrice(sku.selling_price),
                next: formatPrice(pendingPrice ?? 0),
              })}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              size="sm"
              onClick={cancelOverwrite}
              disabled={updatePrice.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button size="sm" onClick={confirmOverwrite} disabled={updatePrice.isPending}>
              {updatePrice.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("shop.menu.detail.overwrite_price.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog
        open={confirmReset}
        onOpenChange={(open) => {
          if (!open) cancelResetPrice();
        }}
      >
        <DialogContent className="sm:max-w-md" data-slot="sku-reset-price-dialog">
          <DialogHeader>
            <DialogTitle>{t("shop.menu.detail.reset_price.title")}</DialogTitle>
            <DialogDescription>
              {t("shop.menu.detail.reset_price.description", {
                current: formatPrice(sku.selling_price),
                default: formatPrice(sku.default_price ?? sku.product_sku?.selling_price ?? 0),
              })}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              size="sm"
              onClick={cancelResetPrice}
              disabled={resetPrice.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button size="sm" onClick={confirmResetPrice} disabled={resetPrice.isPending}>
              {resetPrice.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("shop.menu.detail.reset_price.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
