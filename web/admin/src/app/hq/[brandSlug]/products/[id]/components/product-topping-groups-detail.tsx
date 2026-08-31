"use client";

import { useEffect, useMemo, useState } from "react";
import {
  ChevronDown,
  ChevronUp,
  EllipsisVertical,
  EyeOff,
  GripVertical,
  Layers,
  Package,
  Power,
} from "lucide-react";
import {
  DndContext,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
  type DraggableAttributes,
} from "@dnd-kit/core";
import type { SyntheticListenerMap } from "@dnd-kit/core/dist/hooks/utilities";
import {
  SortableContext,
  arrayMove,
  useSortable,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import {
  Badge,
  Button,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  Spinner,
  StatusBadge,
} from "@godxjp/ui";
import {
  useToppingGroupItems,
  useToppingGroupOverrides,
  useSyncToppingGroupOverrides,
  useSyncProductToppingGroups,
} from "@/hooks/api/use-topping-groups";
import { useTranslation } from "@/providers/app-provider";
import { formatPriceAmount } from "@/lib/currency";
import type { LocaleCode } from "@/i18n";
import type {
  ToppingGroupItem,
  ProductToppingGroupItemOverride,
  OverrideSyncRow,
  ProductSkuRef,
} from "@/services/topping-group-service";

// =========================================================================
//  Helpers
// =========================================================================

type VariantSlot = 1 | 2 | 3;
const VARIANT_SLOTS: readonly VariantSlot[] = [1, 2, 3] as const;

function deriveActiveSlots(skus: ProductSkuRef[]): { slot: VariantSlot }[] {
  const active: { slot: VariantSlot }[] = [];
  for (const slot of VARIANT_SLOTS) {
    if (skus.some((sku) => sku[`option_value${slot}` as const] != null)) {
      active.push({ slot });
    }
  }
  return active;
}

function getVariantLabel(sku: ProductSkuRef, slots: { slot: VariantSlot }[]): string {
  if (slots.length > 0) {
    const parts = slots
      .map(({ slot }) => sku[`option_value${slot}` as const]?.label)
      .filter(Boolean) as string[];
    if (parts.length > 0) return parts.join(" / ");
  }
  return sku.name ?? sku.sku ?? "—";
}

/**
 * Interpolated `¥` and grouped digits the Japanese way regardless of locale.
 * Overrides can be negative (a discount), and `formatCurrency` renders the
 * sign itself — no hand-built "-¥" needed.
 */
function formatPrice(
  price: string | number | null | undefined,
  locale: LocaleCode
): string {
  if (price == null) return "—";
  const n = typeof price === "number" ? price : parseFloat(price);
  if (Number.isNaN(n)) return "—";
  return formatPriceAmount(n, locale);
}

function applyHiddenMutation(
  current: ProductToppingGroupItemOverride[],
  itemId: string,
  productSkuId: string | null,
  value: boolean
): OverrideSyncRow[] {
  const passthrough = (ov: ProductToppingGroupItemOverride): OverrideSyncRow => ({
    topping_group_item_id: ov.topping_group_item_id,
    product_sku_id: ov.product_sku_id,
    is_hidden: ov.is_hidden,
    override_price: ov.override_price != null ? parseFloat(ov.override_price) : null,
  });

  const filtered = current
    .filter((ov) => !(ov.topping_group_item_id === itemId && ov.product_sku_id === productSkuId))
    .map(passthrough);

  if (value) {
    filtered.push({
      topping_group_item_id: itemId,
      product_sku_id: productSkuId,
      is_hidden: true,
      override_price: null,
    });
  }
  return filtered;
}

// =========================================================================
//  ToppingItemRow — header + readonly price table + Action column
// =========================================================================

interface ToppingItemRowProps {
  item: ToppingGroupItem;
  productSkus: ProductSkuRef[];
  groupSkuPriceMap: Record<string, string>;
  groupNullSkuPrice: string | null;
  /** Subset of group overrides that target this item. */
  itemOverrides: ProductToppingGroupItemOverride[];
  isPending: boolean;
  onHiddenToggle: (productSkuId: string | null, next: boolean) => void;
}

function ToppingItemRow({
  item,
  productSkus,
  groupSkuPriceMap,
  groupNullSkuPrice,
  itemOverrides,
  isPending,
  onHiddenToggle,
}: ToppingItemRowProps) {
  const { t, locale } = useTranslation();
  const name = item.product?.name ?? item.product_id;
  const hasVariants = (item.variants_count ?? 0) > 1;

  const slots = useMemo(() => deriveActiveSlots(productSkus), [productSkus]);

  // Build readonly rows. Each row knows its current hidden state.
  type Row = {
    key: string;
    productSkuId: string | null;
    label: string;
    sellingPrice: string | null;
    addOnPrice: string | null;
    isHidden: boolean;
  };

  const rows: Row[] = hasVariants
    ? productSkus.map((sku) => {
        const ov = itemOverrides.find((o) => o.product_sku_id === sku.id);
        return {
          key: sku.id,
          productSkuId: sku.id,
          label: getVariantLabel(sku, slots),
          sellingPrice: sku.selling_price,
          addOnPrice: groupSkuPriceMap[sku.id] ?? null,
          isHidden: ov?.is_hidden === true,
        };
      })
    : [
        (() => {
          const ov = itemOverrides.find((o) => o.product_sku_id === null);
          return {
            key: "__null__",
            productSkuId: null as string | null,
            label: productSkus[0]?.sku ?? productSkus[0]?.name ?? "—",
            sellingPrice: productSkus[0]?.selling_price ?? null,
            addOnPrice: groupNullSkuPrice,
            isHidden: ov?.is_hidden === true,
          };
        })(),
      ];

  const [isExpanded, setIsExpanded] = useState(false);

  // Hidden state lived only inside the collapsed table, so a topping hidden by
  // an override looked identical to a live one in the list while the customer
  // menu dropped it entirely (#1275 part A).
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
        <span className="min-w-0 flex-1 truncate text-xs font-medium">{name}</span>
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
        {hasVariants && (
          <Badge variant="outline" className="h-5 shrink-0 px-1.5 text-[10px] tabular-nums">
            {t("hq.topping_groups.overrides.variants_count", {
              n: item.variants_count ?? productSkus.length,
            })}
          </Badge>
        )}
        {isExpanded ? (
          <ChevronUp className="size-3.5 shrink-0 text-muted-foreground" />
        ) : (
          <ChevronDown className="size-3.5 shrink-0 text-muted-foreground" />
        )}
      </button>

      {isExpanded && (
        <>
          {productSkus.length === 0 && hasVariants ? (
            <div className="border-t px-3 py-2 text-xs text-muted-foreground">
              <Spinner className="mr-1.5 inline size-3" />
              {t("common.loading")}
            </div>
          ) : (
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
                    {t("hq.topping_groups.overrides.col_selling_price")}
                  </th>
                  <th className="px-3 py-1.5 text-right font-medium tracking-wide text-muted-foreground uppercase">
                    {t("hq.topping_groups.overrides.col_default")}
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
                  <tr key={row.key} className={`border-t${row.isHidden ? "opacity-60" : ""}`}>
                    <td className="px-3 py-1.5 text-muted-foreground">{index + 1}</td>
                    <td className="max-w-0 px-3 py-1.5">
                      <span className="block truncate">{row.label}</span>
                    </td>
                    <td className="px-3 py-1.5 text-right text-muted-foreground tabular-nums">
                      {formatPrice(row.sellingPrice, locale)}
                    </td>
                    <td className="px-3 py-1.5 text-right text-muted-foreground tabular-nums">
                      {row.addOnPrice != null
                        ? formatPrice(row.addOnPrice, locale)
                        : t("hq.topping_groups.overrides.no_default")}
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
        </>
      )}
    </div>
  );
}

// =========================================================================
//  ToppingGroupPanel — fetches data, mutates atomically per action
// =========================================================================

interface ToppingGroupPanelProps {
  group: { id: string; name: string };
  brandSlug: string;
  productId: string;
  dragHandleListeners?: SyntheticListenerMap;
  dragHandleAttributes?: DraggableAttributes;
  isDragging?: boolean;
}

function ToppingGroupPanel({
  group,
  brandSlug,
  productId,
  dragHandleListeners,
  dragHandleAttributes,
  isDragging,
}: ToppingGroupPanelProps) {
  const { t } = useTranslation();
  const [isOpen, setIsOpen] = useState(false);

  const itemsQuery = useToppingGroupItems(brandSlug, group.id);
  const items = useMemo(() => itemsQuery.data?.data ?? [], [itemsQuery.data]);

  const overridesQuery = useToppingGroupOverrides(brandSlug, productId, group.id);
  const overrides = useMemo(() => overridesQuery.data?.data ?? [], [overridesQuery.data]);
  const syncMutation = useSyncToppingGroupOverrides(brandSlug, productId, group.id);

  // Active ProductSku rows for each item come inline from the items response
  // (backend eager-loads product.skus with option values), so no extra requests needed.
  const skusByItemId = useMemo(() => {
    const map: Record<string, ProductSkuRef[]> = {};
    for (const it of items) {
      map[it.id] = it.product_skus ?? [];
    }
    return map;
  }, [items]);

  // Group's per-SKU + NULL-SKU default prices, derived from item.skus.
  const groupPricesByItemId = useMemo<
    Record<string, { perSku: Record<string, string>; nullPrice: string | null }>
  >(() => {
    const out: Record<string, { perSku: Record<string, string>; nullPrice: string | null }> = {};
    for (const it of items) {
      const perSku: Record<string, string> = {};
      let nullPrice: string | null = null;
      for (const s of it.skus ?? []) {
        if (s.product_sku_id) perSku[s.product_sku_id] = s.extra_price;
        else nullPrice = s.extra_price;
      }
      out[it.id] = { perSku, nullPrice };
    }
    return out;
  }, [items]);

  const overrideCount = overrides.length;

  function makeHiddenToggle(itemId: string) {
    return (productSkuId: string | null, next: boolean) => {
      void syncMutation.mutateAsync(applyHiddenMutation(overrides, itemId, productSkuId, next));
    };
  }

  return (
    <div className={`overflow-hidden rounded-lg border${isDragging ? "opacity-50" : ""}`}>
      <div className="flex w-full items-center bg-muted/40 hover:bg-muted/60">
        <span
          {...dragHandleListeners}
          {...dragHandleAttributes}
          onClick={(e) => e.stopPropagation()}
          className="flex cursor-grab touch-none items-center px-2 py-2.5 text-muted-foreground active:cursor-grabbing"
          aria-label="Drag to reorder"
        >
          <GripVertical className="size-4 shrink-0" />
        </span>
        <button
          type="button"
          onClick={() => setIsOpen((v) => !v)}
          className="flex flex-1 cursor-pointer items-center gap-2 py-2.5 pr-4 text-left focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
        >
          <Layers className="size-4 shrink-0 text-muted-foreground" />
          <span className="min-w-0 flex-1 truncate text-sm font-semibold">{group.name}</span>
          {itemsQuery.isLoading ? (
            <Spinner className="size-3.5 text-muted-foreground" />
          ) : (
            <Badge variant="outline" className="h-5 shrink-0 px-1.5 text-[10px]">
              {t("hq.topping_groups.items.products_count", { n: items.length })}
            </Badge>
          )}
          {overrideCount > 0 && (
            <Badge variant="secondary" className="h-5 shrink-0 px-1.5 text-[10px]">
              {t("hq.topping_groups.overrides.count", { n: overrideCount })}
            </Badge>
          )}
          {isOpen ? (
            <ChevronUp className="size-4 shrink-0 text-muted-foreground" />
          ) : (
            <ChevronDown className="size-4 shrink-0 text-muted-foreground" />
          )}
        </button>
      </div>

      {isOpen && (
        <div className="border-t">
          {itemsQuery.isLoading || overridesQuery.isLoading ? (
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
                const prices = groupPricesByItemId[item.id] ?? {
                  perSku: {},
                  nullPrice: null,
                };
                const itemOverrides = overrides.filter(
                  (ov) => ov.topping_group_item_id === item.id
                );
                return (
                  <ToppingItemRow
                    key={item.id}
                    item={item}
                    productSkus={skusByItemId[item.id] ?? []}
                    groupSkuPriceMap={prices.perSku}
                    groupNullSkuPrice={prices.nullPrice}
                    itemOverrides={itemOverrides}
                    isPending={syncMutation.isPending}
                    onHiddenToggle={makeHiddenToggle(item.id)}
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
//  ProductToppingGroupsDetail — main export
// =========================================================================

export interface ProductToppingGroupsDetailProps {
  brandSlug: string;
  productId: string;
  groups: { id: string; name: string }[];
}

// =========================================================================
//  SortableToppingGroupPanel — dnd-kit wrapper
// =========================================================================

function SortableToppingGroupPanel(
  props: Omit<ToppingGroupPanelProps, "dragHandleListeners" | "dragHandleAttributes" | "isDragging">
) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: props.group.id,
  });

  return (
    <div ref={setNodeRef} style={{ transform: CSS.Transform.toString(transform), transition }}>
      <ToppingGroupPanel
        {...props}
        dragHandleListeners={listeners}
        dragHandleAttributes={attributes}
        isDragging={isDragging}
      />
    </div>
  );
}

// =========================================================================
//  ProductToppingGroupsDetail — main export
// =========================================================================

export function ProductToppingGroupsDetail({
  brandSlug,
  productId,
  groups,
}: ProductToppingGroupsDetailProps) {
  const { t } = useTranslation();
  const syncMutation = useSyncProductToppingGroups(brandSlug, productId);

  const [orderedGroups, setOrderedGroups] = useState(groups);
  useEffect(() => {
    setOrderedGroups(groups);
  }, [groups]);

  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }));

  function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event;
    if (!over || active.id === over.id) return;

    const oldIndex = orderedGroups.findIndex((g) => g.id === active.id);
    const newIndex = orderedGroups.findIndex((g) => g.id === over.id);
    const newOrder = arrayMove(orderedGroups, oldIndex, newIndex);

    setOrderedGroups(newOrder);
    syncMutation.mutate({
      topping_group_ids: newOrder.map((g) => g.id),
      sort_orders: Object.fromEntries(newOrder.map((g, i) => [g.id, i])),
    });
  }

  if (orderedGroups.length === 0) return null;

  return (
    <div data-slot="product-topping-groups-detail">
      <div className="mb-3">
        <span className="text-sm font-semibold">
          {t("hq.topping_groups.product_section.title")}
        </span>
        <p className="text-xs text-muted-foreground">{t("hq.topping_groups.overrides.hint")}</p>
      </div>

      <DndContext sensors={sensors} onDragEnd={handleDragEnd}>
        <SortableContext
          items={orderedGroups.map((g) => g.id)}
          strategy={verticalListSortingStrategy}
        >
          <div className="flex flex-col gap-2">
            {orderedGroups.map((g) => (
              <SortableToppingGroupPanel
                key={g.id}
                group={g}
                brandSlug={brandSlug}
                productId={productId}
              />
            ))}
          </div>
        </SortableContext>
      </DndContext>
    </div>
  );
}
