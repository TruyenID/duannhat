"use client";

import { useMemo, useState } from "react";
import { Trash2, RotateCcw, Eye, EyeOff } from "lucide-react";
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from "@godxjp/ui";
import { Button } from "@godxjp/ui";
import { Badge } from "@godxjp/ui";
import {
  useMenu,
  useRemoveMenuProduct,
  useToggleMenuProduct,
  useSyncFromMaster,
} from "@/hooks/api/use-menus";
import type { Menu, MenuProduct, MenuProductSku } from "@/services/menu-service";
import { useTranslation } from "@/providers/app-provider";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";

import { Spinner } from "@godxjp/ui";

export interface MenuItemsDrawerProps {
  brandSlug: string;
  menu: Menu | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function MenuItemsDrawer({ brandSlug, menu, open, onOpenChange }: MenuItemsDrawerProps) {
  const { t } = useTranslation();
  const menuId = menu?.id ?? "";

  // Refetch full menu data (with menu_products) when drawer opens
  const { data: menuResponse, isLoading } = useMenu(brandSlug, menuId);
  const fullMenu = menuResponse?.data ?? null;

  const menuProducts = useMemo<MenuProduct[]>(
    () => (fullMenu?.menu_products ?? []).slice().sort((a, b) => a.display_order - b.display_order),
    [fullMenu?.menu_products]
  );

  // Mutations
  const removeProduct = useRemoveMenuProduct(brandSlug);
  const toggleProduct = useToggleMenuProduct(brandSlug);
  const syncMaster = useSyncFromMaster(brandSlug);

  const [confirmRemove, setConfirmRemove] = useState<{ menuProductId: string } | null>(null);

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="sm:max-w-lg">
        <SheetHeader>
          <SheetTitle>
            {menu?.name ?? t("hq.menus.drawer.fallback_title")} —{" "}
            {t("hq.menus.drawer.title_suffix", { n: menuProducts.length })}
          </SheetTitle>
          <SheetDescription>{t("hq.menus.drawer.desc")}</SheetDescription>
        </SheetHeader>

        <div className="flex items-center gap-2 px-4 pb-2">
          {menu?.master_menu_id && (
            <Button
              variant="outline"
              size="sm"
              className="h-7 gap-1 text-xs"
              onClick={() => syncMaster.mutate(menuId)}
              disabled={syncMaster.isPending}
            >
              {syncMaster.isPending ? (
                <Spinner className="size-3.5" />
              ) : (
                <RotateCcw className="size-3.5" />
              )}
              {t("hq.menus.drawer.sync_master")}
            </Button>
          )}
        </div>

        <div className="flex-1 overflow-y-auto px-4">
          {isLoading ? (
            <div className="flex items-center justify-center py-12">
              <Spinner className="size-5 text-muted-foreground" />
            </div>
          ) : menuProducts.length === 0 ? (
            <div className="py-12 text-center text-sm text-muted-foreground">
              {t("hq.menus.drawer.empty")}
            </div>
          ) : (
            <div className="flex flex-col gap-2">
              {menuProducts.map((mp) => (
                <MenuProductRow
                  key={mp.id}
                  mp={mp}
                  menuId={menuId}
                  onRemove={() => setConfirmRemove({ menuProductId: mp.id })}
                  onToggle={() => {
                    toggleProduct.mutate({ menuId, menuProductId: mp.id });
                  }}
                  isRemoving={removeProduct.isPending}
                  isToggling={toggleProduct.isPending}
                />
              ))}
            </div>
          )}
        </div>
      </SheetContent>

      <DeleteConfirmDialog
        open={!!confirmRemove}
        onOpenChange={(open) => {
          if (!open) setConfirmRemove(null);
        }}
        description={t("hq.menus.drawer.confirm_remove")}
        onConfirm={() => {
          if (confirmRemove) {
            removeProduct.mutate({ menuId, menuProductId: confirmRemove.menuProductId });
            setConfirmRemove(null);
          }
        }}
        isPending={removeProduct.isPending}
      />
    </Sheet>
  );
}

function MenuProductRow({
  mp,
  menuId,
  onRemove,
  onToggle,
  isRemoving,
  isToggling,
}: {
  mp: MenuProduct;
  menuId: string;
  onRemove: () => void;
  onToggle: () => void;
  isRemoving: boolean;
  isToggling: boolean;
}) {
  const { t } = useTranslation();
  const name = mp.product?.name ?? t("hq.menus.drawer.unknown_product");
  const skus = mp.skus ?? [];

  return (
    <div className="rounded-md border border-input p-2">
      <div className="flex items-center gap-2">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-1.5">
            <span className="truncate text-sm font-medium">{name}</span>
            <span
              className={`inline-flex h-4 items-center rounded px-1 text-[10px] font-medium ${
                mp.is_active ? "bg-green-50 text-green-700" : "bg-muted text-muted-foreground"
              }`}
            >
              {mp.is_active ? t("hq.menus.drawer.active") : t("hq.menus.drawer.inactive")}
            </span>
          </div>
          <div className="text-xs text-muted-foreground">
            {skus.length} SKU{skus.length !== 1 ? "s" : ""}
          </div>
        </div>

        <div className="flex items-center gap-0.5">
          <button
            type="button"
            onClick={onToggle}
            disabled={isToggling}
            className="rounded p-1 text-muted-foreground hover:bg-accent"
            title={
              mp.is_active ? t("hq.menus.drawer.deactivate_tip") : t("hq.menus.drawer.activate_tip")
            }
          >
            {mp.is_active ? <Eye className="size-3" /> : <EyeOff className="size-3" />}
          </button>
          <button
            type="button"
            onClick={onRemove}
            disabled={isRemoving}
            className="rounded p-1 text-destructive hover:bg-accent"
          >
            <Trash2 className="size-3" />
          </button>
        </div>
      </div>

      {skus.length > 0 && (
        <div className="mt-1.5 space-y-0.5 border-t pt-1.5">
          {skus.map((sku) => (
            <SkuRow key={sku.id} sku={sku} />
          ))}
        </div>
      )}
    </div>
  );
}

function SkuRow({ sku }: { sku: MenuProductSku }) {
  const { t } = useTranslation();
  const skuName =
    sku.product_sku?.name ?? sku.product_sku?.sku ?? t("hq.menus.drawer.sku_fallback");

  return (
    <div className="flex items-center gap-2 px-1 text-xs text-muted-foreground">
      <span className="flex-1 truncate">{skuName}</span>
      <span className="shrink-0 tabular-nums">{Number(sku.selling_price).toLocaleString()}</span>
      {sku.is_price_overridden && (
        <Badge variant="outline" className="h-4 shrink-0 px-1 text-[9px]">
          {t("hq.menus.drawer.override")}
        </Badge>
      )}
      <span
        className={`inline-flex h-3.5 shrink-0 items-center rounded px-1 text-[9px] font-medium ${
          sku.is_active ? "bg-green-50 text-green-700" : "bg-muted text-muted-foreground"
        }`}
      >
        {sku.is_active ? t("hq.menus.drawer.on") : t("hq.menus.drawer.off")}
      </span>
    </div>
  );
}
