"use client";

import { useMemo, useState } from "react";
import {
  ChevronDown,
  ChevronUp,
  EllipsisVertical,
  EyeOff,
  Layers,
  Package,
  Power,
  Pencil,
  TriangleAlert,
} from "lucide-react";
import {
  Badge,
  Button,
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  Input,
  Label,
  Spinner,
  StatusBadge,
} from "@godxjp/ui";
import {
  useShopFloatingSectionToppingOverrides,
  useSyncShopFloatingSectionToppingOverrides,
} from "@/hooks/api/use-shop-floating-sections";
import { useTranslation } from "@/providers/app-provider";
import { useShopCurrency } from "@/providers/shop-currency-provider";
import { formatCurrency } from "@/lib/currency";
import { describeToppingPriceRow, hasScopedToppingRow } from "@/lib/topping-price-rows";
import type { LocaleCode } from "@/i18n";
import type {
  MenuProductToppingItemOverride,
  ShopToppingOverrideSyncRow,
  MenuToppingGroup,
  MenuToppingGroupItem,
} from "@/types/shop";
import type { FloatingSectionProduct } from "@/types/models/FloatingSectionProduct";

// =========================================================================
//  Helpers — identical to the menu topping-groups-section (owner-agnostic).
// =========================================================================

/**
 * Topping prices are the SHOP's money, so the currency comes from the shop and
 * not from the reader's language (#1260). This interpolated `¥` and grouped
 * digits ja-JP unconditionally, which showed a Vietnamese shop its VND prices
 * as yen — and grouped them the Japanese way while doing it.
 *
 * `currency` is null outside a shop route, where formatCurrency falls back to
 * the locale default: the previous behaviour, and the only answer available.
 */
function fmt(
  price: string | number | null | undefined,
  locale: LocaleCode,
  currency: string | null
): string {
  const n = typeof price === "number" ? price : parseFloat((price ?? "") as string);
  if (price == null || Number.isNaN(n)) return formatCurrency(0, locale, currency ?? undefined);
  if (n < 0) return `-${formatCurrency(Math.abs(n), locale, currency ?? undefined)}`;
  return formatCurrency(n, locale, currency ?? undefined);
}

function applyMutation(
  current: MenuProductToppingItemOverride[],
  itemId: string,
  productSkuId: string | null,
  patch: { is_hidden?: boolean; override_price?: number | null }
): ShopToppingOverrideSyncRow[] {
  const passthrough = (ov: MenuProductToppingItemOverride): ShopToppingOverrideSyncRow => ({
    topping_group_item_id: ov.topping_group_item_id,
    product_sku_id: ov.product_sku_id,
    is_hidden: ov.is_hidden,
    override_price: ov.override_price != null ? parseFloat(ov.override_price) : null,
  });

  const existing = current.find(
    (ov) => ov.topping_group_item_id === itemId && ov.product_sku_id === productSkuId
  );
  const filtered = current
    .filter((ov) => !(ov.topping_group_item_id === itemId && ov.product_sku_id === productSkuId))
    .map(passthrough);

  const isHidden = patch.is_hidden ?? existing?.is_hidden ?? false;

  const next: ShopToppingOverrideSyncRow = {
    topping_group_item_id: itemId,
    product_sku_id: productSkuId,
    is_hidden: isHidden,
    // Backend rejects a row that is both hidden AND price-overridden; hiding a
    // variant makes its price moot, so drop any carried-over override price.
    override_price: isHidden
      ? null
      : "override_price" in patch
        ? (patch.override_price ?? null)
        : existing?.override_price != null
          ? parseFloat(existing.override_price)
          : null,
  };

  if (next.is_hidden || next.override_price != null) {
    filtered.push(next);
  }
  return filtered;
}

// =========================================================================
//  EditOverrideDialog
// =========================================================================

interface EditOverrideDialogProps {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  variantLabel: string;
  defaultAddOnPrice: string | null;
  currentOverridePrice: number | null;
  isPending: boolean;
  onSave: (price: number | null) => void;
}

function EditOverrideDialog({
  open,
  onOpenChange,
  variantLabel,
  defaultAddOnPrice,
  currentOverridePrice,
  isPending,
  onSave,
}: EditOverrideDialogProps) {
  const { t, locale } = useTranslation();
  // Shop-scoped screen: topping prices belong to the shop's currency (#1260).
  const shopCurrency = useShopCurrency();
  const [raw, setRaw] = useState(currentOverridePrice != null ? String(currentOverridePrice) : "");

  const parsed = raw.trim() === "" ? null : parseFloat(raw);
  const isValid = raw.trim() === "" || (!Number.isNaN(parsed) && parsed! >= 0);

  function handleSave() {
    if (!isValid) return;
    onSave(parsed);
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-sm" aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>
            {t("shop.menu.topping.edit_dialog_title", { name: variantLabel })}
          </DialogTitle>
        </DialogHeader>
        <div className="space-y-3 py-2">
          <div className="text-xs text-muted-foreground">
            {t("hq.topping_groups.overrides.col_default")}:{" "}
            <span className="font-medium tabular-nums">{fmt(defaultAddOnPrice, locale, shopCurrency)}</span>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="fs-override-price">{t("shop.menu.topping.override_price_label")}</Label>
            <Input
              id="fs-override-price"
              type="number"
              min={0}
              step={1}
              placeholder={t("shop.menu.topping.override_price_placeholder")}
              value={raw}
              onChange={(e) => setRaw(e.target.value)}
              className={!isValid ? "border-destructive" : undefined}
            />
            {!isValid && (
              <p className="text-xs text-destructive">{t("shop.menu.detail.price_invalid")}</p>
            )}
            <p className="text-xs text-muted-foreground">
              {t("shop.menu.topping.override_price_hint")}
            </p>
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" size="sm" onClick={() => onOpenChange(false)}>
            {t("common.cancel")}
          </Button>
          <Button size="sm" disabled={!isValid || isPending} onClick={handleSave}>
            {isPending ? <Spinner className="mr-2 size-3.5" /> : null}
            {t("common.save")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// =========================================================================
//  ToppingItemPanel — per item expandable row with price override
// =========================================================================

interface ToppingItemPanelProps {
  item: MenuToppingGroupItem;
  itemOverrides: MenuProductToppingItemOverride[];
  isPending: boolean;
  onHiddenToggle: (productSkuId: string | null, next: boolean) => void;
  onPriceSave: (productSkuId: string | null, price: number | null) => void;
}

function ToppingItemPanel({
  item,
  itemOverrides,
  isPending,
  onHiddenToggle,
  onPriceSave,
}: ToppingItemPanelProps) {
  const { t, locale } = useTranslation();
  // Shop-scoped screen: topping prices belong to the shop's currency (#1260).
  const shopCurrency = useShopCurrency();
  const [isExpanded, setIsExpanded] = useState(false);
  const [editingKey, setEditingKey] = useState<string | null>(null);

  // Drop HQ-inactive variants (customer menu already hides them; the shop
  // Show/Hide toggle only flips is_hidden). Wildcard rows kept.
  const skus = (item.skus ?? []).filter(
    (sku) => sku.product_sku_id === null || sku.is_active !== false
  );
  const hasSkus = skus.length > 0;

  type Row = {
    key: string;
    productSkuId: string | null;
    label: string;
    addOnPrice: string | null;
    overridePrice: number | null;
    isHidden: boolean;
    appliesToNothing: boolean;
  };

  const rows: Row[] = useMemo(() => {
    if (!hasSkus) {
      const ov = itemOverrides.find((o) => o.product_sku_id === null);
      return [
        {
          key: "__null__",
          productSkuId: null,
          label: item.name ?? "—",
          addOnPrice: null,
          overridePrice: ov?.override_price != null ? parseFloat(ov.override_price) : null,
          isHidden: ov?.is_hidden === true,
          appliesToNothing: false,
        },
      ];
    }
    // Shared with the menu twin — see describeToppingPriceRow for why a wildcard
    // row is renamed only beside a scoped row, and why "does it apply" has to
    // come from the backend (#1316).
    const hasScopedRow = hasScopedToppingRow(skus);
    return skus.map((sku) => {
      const ov = itemOverrides.find((o) => o.product_sku_id === sku.product_sku_id);
      const { label, appliesToNothing } = describeToppingPriceRow(sku, {
        hasScopedRow,
        itemName: item.name,
        wildcardLabel: t("hq.topping_groups.overrides.wildcard_row_label"),
      });
      return {
        key: sku.id,
        productSkuId: sku.product_sku_id,
        label,
        addOnPrice: sku.base_extra_price ?? sku.extra_price,
        overridePrice: ov?.override_price != null ? parseFloat(ov.override_price) : null,
        isHidden: ov?.is_hidden === true,
        appliesToNothing,
      };
    });
  }, [skus, itemOverrides, item.name, hasSkus, t]);

  const editingRow = rows.find((r) => r.key === editingKey) ?? null;

  // Same two corrections as the menu twin (#1275): a wildcard row is a price,
  // not a variant, so it must not inflate the count; and a hidden row was only
  // discoverable by expanding the panel.
  const variantSkus = skus.filter((sku) => sku.product_sku_id !== null);
  const hiddenRowCount = rows.filter((r) => r.isHidden).length;
  const allRowsHidden = rows.length > 0 && hiddenRowCount === rows.length;

  return (
    <div className="overflow-hidden rounded border">
      <button
        type="button"
        onClick={() => setIsExpanded((v) => !v)}
        className="flex w-full items-center gap-2 bg-muted/20 px-3 py-2 text-left hover:bg-muted/30 focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
      >
        <Package className="size-3.5 shrink-0 text-muted-foreground" />
        <span className="min-w-0 flex-1 truncate text-xs font-medium">{item.name ?? "—"}</span>
        {variantSkus.length > 1 && (
          <Badge variant="outline" className="h-5 shrink-0 px-1.5 text-[10px] tabular-nums">
            {t("hq.topping_groups.overrides.variants_count", { n: variantSkus.length })}
          </Badge>
        )}
        {hiddenRowCount > 0 && (
          <Badge
            variant="outline"
            className="h-5 shrink-0 gap-1 px-1.5 text-[10px] text-muted-foreground tabular-nums"
          >
            <EyeOff className="size-3" />
            {allRowsHidden
              ? t("hq.topping_groups.overrides.hidden_badge")
              : t("hq.topping_groups.overrides.hidden_partial", { n: hiddenRowCount })}
          </Badge>
        )}
        {isExpanded ? (
          <ChevronUp className="size-3.5 shrink-0 text-muted-foreground" />
        ) : (
          <ChevronDown className="size-3.5 shrink-0 text-muted-foreground" />
        )}
      </button>

      {isExpanded && (
        <table className="w-full border-t text-xs">
          <thead>
            <tr className="bg-muted/30">
              <th className="w-9 px-3 py-1.5 text-left font-medium tracking-wide text-muted-foreground uppercase">
                {t("hq.products.col.stt")}
              </th>
              <th className="px-3 py-1.5 text-left font-medium tracking-wide text-muted-foreground uppercase">
                {t("hq.topping_groups.overrides.col_variant")}
              </th>
              <th className="px-3 py-1.5 text-right font-medium tracking-wide text-muted-foreground uppercase">
                {t("hq.topping_groups.overrides.col_default")}
              </th>
              <th className="px-3 py-1.5 text-right font-medium tracking-wide text-muted-foreground uppercase">
                {t("shop.menu.topping.col_override_price")}
              </th>
              <th className="px-3 py-1.5 text-left font-medium tracking-wide text-muted-foreground uppercase">
                {t("common.status")}
              </th>
              <th className="w-10 px-3 py-1.5 text-right font-medium tracking-wide text-muted-foreground uppercase">
                {t("common.action")}
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row, index) => (
              <tr key={row.key} className={`border-t${row.isHidden ? " opacity-60" : ""}`}>
                <td className="px-3 py-1.5 text-muted-foreground">{index + 1}</td>
                <td className="max-w-0 px-3 py-1.5">
                  <span className="block truncate">{row.label}</span>
                  {row.appliesToNothing && (
                    <Badge
                      variant="outline"
                      className="mt-0.5 h-5 gap-1 px-1.5 text-[10px] text-muted-foreground"
                      title={t("hq.topping_groups.overrides.not_applied_hint")}
                    >
                      <TriangleAlert className="size-3" />
                      {t("hq.topping_groups.overrides.not_applied_badge")}
                    </Badge>
                  )}
                </td>
                <td className="px-3 py-1.5 text-right text-muted-foreground tabular-nums">
                  {fmt(row.addOnPrice, locale, shopCurrency)}
                </td>
                <td className="px-3 py-1.5 text-right tabular-nums">
                  {row.overridePrice != null ? (
                    <span className="font-medium text-primary">{fmt(row.overridePrice, locale, shopCurrency)}</span>
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </td>
                <td className="px-3 py-1.5">
                  <StatusBadge status={row.isHidden ? "inactive" : "active"} />
                </td>
                <td className="px-3 py-1.5 text-right">
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-7"
                        disabled={isPending}
                        aria-label={t("common.action")}
                      >
                        <EllipsisVertical className="size-4" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      <DropdownMenuItem onClick={() => setEditingKey(row.key)}>
                        <Pencil className="mr-2 size-3.5" />
                        {t("shop.menu.topping.edit_price_action")}
                      </DropdownMenuItem>
                      <DropdownMenuItem
                        onClick={() => onHiddenToggle(row.productSkuId, !row.isHidden)}
                      >
                        <Power className="mr-2 size-3.5" />
                        {row.isHidden
                          ? t("hq.topping_groups.overrides.show_action")
                          : t("hq.topping_groups.overrides.hide_action")}
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}

      {editingRow && (
        <EditOverrideDialog
          open={editingKey !== null}
          onOpenChange={(o) => {
            if (!o) setEditingKey(null);
          }}
          variantLabel={editingRow.label}
          defaultAddOnPrice={editingRow.addOnPrice}
          currentOverridePrice={editingRow.overridePrice}
          isPending={isPending}
          onSave={(price) => onPriceSave(editingRow.productSkuId, price)}
        />
      )}
    </div>
  );
}

// =========================================================================
//  ShopToppingGroupPanel — per group collapsible panel
// =========================================================================

interface ShopToppingGroupPanelProps {
  group: MenuToppingGroup;
  shopSlug: string;
  sectionId: string;
  sectionProductId: string;
}

function ShopToppingGroupPanel({
  group,
  shopSlug,
  sectionId,
  sectionProductId,
}: ShopToppingGroupPanelProps) {
  const { t, locale } = useTranslation();
  // Shop-scoped screen: topping prices belong to the shop's currency (#1260).
  const shopCurrency = useShopCurrency();
  const [isOpen, setIsOpen] = useState(false);

  const overridesQuery = useShopFloatingSectionToppingOverrides(
    shopSlug,
    sectionId,
    sectionProductId,
    group.id
  );
  const overrides = useMemo(() => overridesQuery.data?.data ?? [], [overridesQuery.data]);
  const syncMutation = useSyncShopFloatingSectionToppingOverrides(
    shopSlug,
    sectionId,
    sectionProductId,
    group.id
  );

  const items = group.items ?? [];
  const overrideCount = overrides.length;

  function makeActions(itemId: string) {
    return {
      onHiddenToggle: (productSkuId: string | null, next: boolean) => {
        void syncMutation.mutateAsync(
          applyMutation(overrides, itemId, productSkuId, { is_hidden: next })
        );
      },
      onPriceSave: (productSkuId: string | null, price: number | null) => {
        void syncMutation.mutateAsync(
          applyMutation(overrides, itemId, productSkuId, { override_price: price })
        );
      },
    };
  }

  return (
    <div className="overflow-hidden rounded-lg border">
      <button
        type="button"
        onClick={() => setIsOpen((v) => !v)}
        className="flex w-full items-center gap-2 bg-muted/40 px-3 py-2.5 text-left hover:bg-muted/60 focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
      >
        <Layers className="size-4 shrink-0 text-muted-foreground" />
        <span className="min-w-0 flex-1 truncate text-sm font-semibold">{group.name}</span>
        {overrideCount > 0 && (
          <Badge variant="secondary" className="h-5 shrink-0 px-1.5 text-[10px]">
            {t("hq.topping_groups.overrides.count", { n: overrideCount })}
          </Badge>
        )}
        <Badge variant="outline" className="h-5 shrink-0 px-1.5 text-[10px]">
          {t("hq.topping_groups.items.products_count", { n: items.length })}
        </Badge>
        {isOpen ? (
          <ChevronUp className="size-4 shrink-0 text-muted-foreground" />
        ) : (
          <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
        )}
      </button>

      {isOpen && (
        <div className="border-t">
          {overridesQuery.isLoading ? (
            <div className="flex items-center gap-1.5 px-3 py-2 text-xs text-muted-foreground">
              <Spinner className="size-3" />
              {t("common.loading")}
            </div>
          ) : items.length === 0 ? (
            <div className="px-3 py-2 text-xs text-muted-foreground">
              {t("hq.topping_groups.product_section.items_empty")}
            </div>
          ) : (
            <div className="flex flex-col divide-y">
              {items.map((item) => {
                const itemOverrides = overrides.filter(
                  (ov) => ov.topping_group_item_id === item.id
                );
                const actions = makeActions(item.id);
                return (
                  <ToppingItemPanel
                    key={item.id}
                    item={item}
                    itemOverrides={itemOverrides}
                    isPending={syncMutation.isPending}
                    onHiddenToggle={actions.onHiddenToggle}
                    onPriceSave={actions.onPriceSave}
                  />
                );
              })}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// =========================================================================
//  ShopFloatingSectionToppingGroupsSection — main export
// =========================================================================

export interface ShopFloatingSectionToppingGroupsSectionProps {
  sectionProduct: FloatingSectionProduct;
  shopSlug: string;
  sectionId: string;
}

export function ShopFloatingSectionToppingGroupsSection({
  sectionProduct,
  shopSlug,
  sectionId,
}: ShopFloatingSectionToppingGroupsSectionProps) {
  const { t, locale } = useTranslation();
  // Shop-scoped screen: topping prices belong to the shop's currency (#1260).
  const shopCurrency = useShopCurrency();

  const sectionProductId = sectionProduct.id;
  const groups = sectionProduct.product?.topping_groups ?? [];

  if (groups.length === 0) return null;

  return (
    <div data-slot="shop-floating-section-topping-groups-section" className="mt-3">
      <div className="mb-3">
        <span className="text-sm font-semibold">
          {t("hq.topping_groups.product_section.title")}
        </span>
        <p className="text-xs text-muted-foreground">{t("shop.menu.topping.section_hint")}</p>
      </div>
      <div className="flex flex-col gap-2">
        {groups.map((group) => (
          <ShopToppingGroupPanel
            key={group.id}
            group={group}
            shopSlug={shopSlug}
            sectionId={sectionId}
            sectionProductId={sectionProductId}
          />
        ))}
      </div>
    </div>
  );
}
