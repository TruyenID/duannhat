/**
 * ProductOptionsDialog — Plan 015.
 *
 * Combined picker for variant SKU + topping selections. THE picker for any
 * product that needs a staff choice — multiple SKUs OR at least one topping
 * group. Single-SKU products with no toppings keep the instant-add path in
 * `MenuCatalog`.
 *
 * #1126 — a SKU-only popover used to serve the multi-variant/no-topping case,
 * which silently dropped the topping, option and kitchen-note UI for those
 * products. It was removed rather than left in the tree: one surface for every
 * configurable product is what keeps the note field from going missing again.
 *
 * Layout: header → variant section (radio if multi-SKU) → topping group
 * sections (radio for single, checkbox for multiple) → sticky footer
 * with running total + Add button.
 *
 * Pricing math is driven by `topping-pricing.ts` (TS port of the PHP
 * `ToppingPricingService`). The number it shows is preview-only — the
 * server re-prices on every `addItems` call, so any drift is caught at
 * write time, not silently accepted.
 */

import { useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Checkbox,
  Dialog,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
  RadioGroup,
  RadioGroupItem,
  Textarea,
} from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import {
  AlertCircleIcon,
  ChevronDownIcon,
  ImageIcon,
  MinusIcon,
  PlusIcon,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { Spinner } from "@/components/ui/spinner";
import { useTranslation } from "@/providers/app-provider";
import type {
  ShopMenuProduct,
  ShopMenuProductSku,
  ShopMenuToppingGroup,
  ShopMenuToppingGroupItem,
  ToppingSelection,
} from "../types";
import { formatCurrency } from "../lib/totals";
import { effectiveSkuPrice } from "../lib/menu-price";
import {
  priceLineAcrossGroups,
  type PricedSelection,
} from "../lib/topping-pricing";
import { PromotionBadge, StrikethroughPrice } from "./promotion-badge";

export interface ProductOptionsDialogProps {
  product: ShopMenuProduct;
  /** Already filtered to active SKUs by the caller. Length ≥ 1. */
  skus: ShopMenuProductSku[];
  /** Already filtered to active groups (server-side schedule filter applied). */
  toppingGroups: ShopMenuToppingGroup[];
  /**
   * Plan 016 — `add` (default) opens the dialog with `is_default=true`
   * items pre-ticked and an "Add to cart" button. `edit` opens with
   * `initialSelections` pre-ticked and a "Save" button. The handler
   * shape is unchanged either way.
   */
  mode?: "add" | "edit";
  /**
   * Plan 016 — only used when `mode="edit"`. Maps to the cart line's
   * existing `OrderItemTopping[]`. Each entry's `topping_group_item_id`
   * + `product_sku_id` + `quantity` are hydrated into `selections`.
   * Ignored in add mode.
   */
  initialSelections?: ToppingSelection[];
  /**
   * Plan 016 — only used when `mode="edit"`. Pre-selects the variant
   * SKU the cart line was originally added with so staff doesn't have
   * to remember which SKU they picked.
   */
  initialSelectedSkuId?: string;
  /**
   * Pre-populate the kitchen note when reopening the dialog in edit
   * mode. In add mode this is `undefined` and the textarea starts empty.
   */
  initialNote?: string;
  /**
   * Resolves on success; dialog closes and toast shows from caller.
   * `note` is the customer's free-text request for the kitchen
   * (e.g. "no onion", "ít cay", "extra crispy") — undefined when blank.
   */
  onSubmit: (
    sku: ShopMenuProductSku,
    toppings: ToppingSelection[],
    note: string | undefined,
  ) => Promise<void> | void;
  /**
   * Trigger element — typically the menu card. Required in uncontrolled
   * mode, ignored when controlled via `open`/`onOpenChange` (Plan 016
   * edit flow opens the dialog programmatically from `OrderCart`).
   */
  children?: ReactNode;
  /**
   * Plan 016 — controlled-open mode. When provided, dialog state is
   * driven by the parent (no `DialogTrigger` rendered). Use for the
   * "Edit toppings" button on a cart line.
   */
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  /**
   * plan-043 — maps a raw menu/topping price to its display value under the
   * shop's prices_include_tax toggle (excluded → net; included → net + tax),
   * so the dialog's SKU + topping + running-total previews match the menu card.
   * Defaults to identity (raw) when the caller has no tax context — e.g. the
   * edit-toppings flow reopened from the cart.
   */
  priceForDisplay?: (base: number) => number;
}

/**
 * Per-item state inside one group: selected? + quantity (always ≥ 1
 * when selected). Modeled as Map keyed by item id so toggling is O(1).
 */
type GroupSelectionMap = Map<string, { skuId: string; quantity: number }>;

function pickDefaultItemSku(item: ShopMenuToppingGroupItem): string | null {
  const skus = item.skus ?? [];
  if (skus.length === 0) return null;
  // Prefer the row with a non-null product_sku_id; fall back to NULL row
  // when only the simple-topping fallback exists. Resolved server-side
  // anyway, but FE prefers explicit SKU when available.
  const concrete = skus.find((s) => s.product_sku_id !== null);
  return (concrete ?? skus[0])?.product_sku_id ?? null;
}

function extraPriceFor(
  item: ShopMenuToppingGroupItem,
  productSkuId: string | null,
): number {
  const skus = item.skus ?? [];
  // Match per-SKU first, then NULL fallback.
  const match =
    skus.find((s) => s.product_sku_id === productSkuId) ??
    skus.find((s) => s.product_sku_id === null);
  return Number(match?.extra_price ?? 0);
}

function effectivePriceForVariant(
  item: ShopMenuToppingGroupItem,
  variantSkuId: string,
): number {
  // For "remove" modifiers extra_price is always 0 by convention; but
  // we still go through the same lookup for symmetry.
  const item_sku =
    (item.skus ?? []).find((s) => s.product_sku_id === variantSkuId) ??
    (item.skus ?? []).find((s) => s.product_sku_id === null);
  return Number(item_sku?.extra_price ?? 0);
}

export function ProductOptionsDialog({
  product,
  skus,
  toppingGroups,
  mode = "add",
  initialSelections,
  initialSelectedSkuId,
  initialNote,
  onSubmit,
  children,
  open: controlledOpen,
  onOpenChange: controlledOnOpenChange,
  priceForDisplay = (v) => v,
}: ProductOptionsDialogProps) {
  const { t } = useTranslation();
  const [internalOpen, setInternalOpen] = useState(false);
  // Controlled / uncontrolled toggle: when caller passes `open`, defer to
  // it; otherwise drive open state internally via the DialogTrigger child.
  const isControlled = controlledOpen !== undefined;
  const open = isControlled ? controlledOpen : internalOpen;
  const setOpen = (next: boolean) => {
    if (isControlled) {
      controlledOnOpenChange?.(next);
    } else {
      setInternalOpen(next);
    }
  };
  const [submitting, setSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  // Mobile-only collapsible — the preview sidebar is always expanded on
  // lg+ (sticky right column) but collapsed by default on mobile to keep
  // the form compact. Tapping the "Tóm tắt" header toggles details.
  const [previewExpanded, setPreviewExpanded] = useState(false);

  // Default to cheapest active SKU on first open. Reset on re-open so
  // staff doesn't carry stale state across orders.
  const cheapestSku = useMemo(
    () =>
      [...skus].sort((a, b) => effectiveSkuPrice(a) - effectiveSkuPrice(b))[0] ?? null,
    [skus],
  );
  const [selectedSkuId, setSelectedSkuId] = useState<string>(
    () =>
      initialSelectedSkuId ?? cheapestSku?.id ?? skus[0]?.id ?? "",
  );

  // Per-group selection state: Map<groupId, Map<itemId, {skuId, qty}>>.
  const [selections, setSelections] = useState<
    Record<string, GroupSelectionMap>
  >({});

  // Kitchen note — free-text customer request ("no onion", "ít cay", …).
  // Stored on customer_order_items.note; rendered on cart line + KDS ticket.
  const [note, setNote] = useState<string>("");

  // Hydrate state ONCE per open. Add mode: pre-tick `is_default=true` items.
  // Edit mode: pre-tick whatever the cart line already had (initialSelections).
  //
  // The once-per-open guard is load-bearing, not a micro-optimisation.
  // `skus`, `toppingGroups` and `initialSelections` are rebuilt by the caller
  // on every render — `MenuCatalog` computes `(mp.skus ?? []).filter(...)`
  // inline inside its map, and `page.tsx` does the same for the edit path — so
  // they are new array identities each time even when the menu has not changed
  // at all. Listing them as deps therefore re-ran this effect on ANY parent
  // re-render, and each re-run called `setSelections(initial)`, silently
  // throwing away every topping, option and note the cashier had picked.
  //
  // Parent re-renders are routine and clock-driven, which is what made it look
  // like the page reloaded by itself: the workstation health probe (30s,
  // `WorkstationProvider` — and it stamps a fresh `lastCheckedAt` Date every
  // tick, so the context value changes even when nothing else did) re-renders
  // the whole POS page through `useWorkstation()`, and the kitchen print-status
  // probe (30s) does the same for the cart side. A cashier who spent more than
  // half a minute on a topping sheet watched it reset with no action of theirs.
  //
  // Hydration belongs to the open transition. Nothing that happens WHILE the
  // sheet is open may overwrite the operator's choices — a stale preview price
  // is recoverable (the server re-prices on addItems), a wiped selection is
  // just lost work. Reopening re-reads the props, and the edit-mode dialog is
  // mounted fresh per cart line (`{editingLine && …}`), so both paths still
  // hydrate exactly when they should.
  const hydratedRef = useRef(false);
  useEffect(() => {
    if (!open) {
      hydratedRef.current = false;

      return;
    }
    if (hydratedRef.current) return;
    hydratedRef.current = true;

    setSelectedSkuId(
      initialSelectedSkuId ?? cheapestSku?.id ?? skus[0]?.id ?? "",
    );
    const initial: Record<string, GroupSelectionMap> = {};

    if (mode === "edit" && initialSelections) {
      // Group initialSelections by topping_group_id by walking the topping
      // tree. Each topping_group_item_id is unique per group, so we look
      // it up to figure out which group bucket the selection belongs to.
      const itemToGroup = new Map<string, string>();
      for (const group of toppingGroups) {
        for (const item of group.items ?? []) {
          itemToGroup.set(item.id, group.id);
        }
        initial[group.id] = new Map(); // ensure every group has an entry
      }
      for (const sel of initialSelections) {
        const groupId = itemToGroup.get(sel.topping_group_item_id);
        if (!groupId) continue; // selection refers to a soft-deleted item; drop it
        const map = initial[groupId] ?? new Map<string, { skuId: string; quantity: number }>();
        map.set(sel.topping_group_item_id, {
          skuId: sel.product_sku_id,
          quantity: sel.quantity,
        });
        initial[groupId] = map;
      }
    } else {
      for (const group of toppingGroups) {
        const map: GroupSelectionMap = new Map();
        for (const item of group.items ?? []) {
          if (item.is_default) {
            const skuId = pickDefaultItemSku(item);
            if (skuId !== null) {
              map.set(item.id, { skuId, quantity: 1 });
            }
          }
        }
        initial[group.id] = map;
      }
    }

    setSelections(initial);
    setNote(mode === "edit" && initialNote ? initialNote : "");
    setErrorMessage(null);
  }, [
    open,
    cheapestSku?.id,
    skus,
    toppingGroups,
    mode,
    initialSelections,
    initialSelectedSkuId,
    initialNote,
  ]);

  const selectedSku = skus.find((s) => s.id === selectedSkuId) ?? null;

  // Per-group counts + flattened selections for pricing.
  const flattened: ToppingSelection[] = useMemo(() => {
    const out: ToppingSelection[] = [];
    for (const groupId of Object.keys(selections)) {
      const map = selections[groupId];
      for (const [itemId, picked] of map.entries()) {
        out.push({
          topping_group_item_id: itemId,
          product_sku_id: picked.skuId,
          quantity: picked.quantity,
        });
      }
    }
    return out;
  }, [selections]);

  // Live pricing preview (per unit). The line subtotal in the cart
  // updates from server response after submit — this is just for the
  // dialog footer running total.
  const toppingPerUnit = useMemo(() => {
    const buckets: Record<
      string,
      {
        group: { price_strategy: typeof toppingGroups[number]["price_strategy"]; free_quantity: number | null };
        selections: PricedSelection[];
      }
    > = {};
    for (const group of toppingGroups) {
      const map = selections[group.id];
      if (!map || map.size === 0) continue;
      const items = group.items ?? [];
      const selectionsList: PricedSelection[] = [];
      for (const [itemId, picked] of map.entries()) {
        const item = items.find((i) => i.id === itemId);
        if (!item) continue;
        selectionsList.push({
          toppingGroupItemId: itemId,
          productSkuId: picked.skuId,
          quantity: picked.quantity,
          unitPrice: extraPriceFor(item, picked.skuId),
        });
      }
      buckets[group.id] = {
        group: {
          price_strategy: group.price_strategy,
          free_quantity: group.free_quantity,
        },
        selections: selectionsList,
      };
    }
    return priceLineAcrossGroups(buckets).toppingSubtotal;
  }, [selections, toppingGroups]);

  // Flat list of (groupName, itemName, qty, extraPrice) tuples used by the
  // right-side preview sidebar. Line totals here are raw extra×qty — they
  // do NOT account for `free_quantity` group discounts (those are folded
  // into `toppingPerUnit`/`runningTotal` which the sidebar's Total row
  // displays). So the sidebar item lines reflect "list price" while the
  // footer's running total is the actual price after free-tier waivers.
  const selectedToppingsForSummary = useMemo(() => {
    const rows: Array<{
      id: string;
      groupName: string;
      itemName: string;
      quantity: number;
      extraPrice: number;
      lineTotal: number;
    }> = [];
    for (const group of toppingGroups) {
      const map = selections[group.id];
      if (!map || map.size === 0) continue;
      for (const [itemId, picked] of map.entries()) {
        const item = (group.items ?? []).find((i) => i.id === itemId);
        if (!item) continue;
        const extra = extraPriceFor(item, picked.skuId);
        rows.push({
          id: `${group.id}-${itemId}`,
          groupName: group.name ?? "",
          itemName: item.name ?? "",
          quantity: picked.quantity,
          extraPrice: extra,
          lineTotal: extra * picked.quantity,
        });
      }
    }
    return rows;
  }, [selections, toppingGroups]);

  // Per-group validation messages — shown inline + disable Add button.
  const groupViolations = useMemo(() => {
    const out: Record<string, string | null> = {};
    for (const group of toppingGroups) {
      const map = selections[group.id];
      const count = map
        ? Array.from(map.values()).reduce((s, p) => s + p.quantity, 0)
        : 0;
      if (count < group.effective_min_select) {
        out[group.id] = t("pos.dialog.product_options.validation_min", {
          min: group.effective_min_select,
        });
        continue;
      }
      if (
        group.effective_max_select !== null &&
        count > group.effective_max_select
      ) {
        out[group.id] = t("pos.dialog.product_options.validation_max", {
          max: group.effective_max_select,
        });
        continue;
      }
      out[group.id] = null;
    }
    return out;
  }, [selections, toppingGroups, t]);

  const hasViolations = Object.values(groupViolations).some(Boolean);
  const canSubmit = !!selectedSku && !hasViolations && !submitting;

  const skuOriginalPrice = selectedSku ? effectiveSkuPrice(selectedSku) : 0;

  // Plan-019 — when the product has an active Happy Hour promotion, the
  // SKU price displayed (and folded into runningTotal) is the discounted
  // value. Toppings keep their full extra_price — backend matches this
  // (see CustomerOrderService::addItems lines 581-586: only $rawUnitPrice
  // is adjusted, not topping extra_price). This way the dialog preview
  // matches the cart line and the order summary after submit.
  const activePromotion = product.active_promotion ?? null;
  const promotionMultiplier = activePromotion
    ? (100 - Number(activePromotion.discount_percent)) / 100
    : 1;
  const skuPrice = skuOriginalPrice * promotionMultiplier;
  const runningTotal = skuPrice + toppingPerUnit;
  const runningTotalOriginal = activePromotion
    ? skuOriginalPrice + toppingPerUnit
    : null;

  /** Compute promotion-discounted price for any SKU in the radio list. */
  function discountedSkuPrice(sku: ShopMenuProductSku): number {
    return effectiveSkuPrice(sku) * promotionMultiplier;
  }

  function toggleSingle(group: ShopMenuToppingGroup, itemId: string) {
    const item = (group.items ?? []).find((i) => i.id === itemId);
    if (!item) return;
    const skuId = pickDefaultItemSku(item);
    if (skuId === null) return;
    setSelections((prev) => {
      const next = { ...prev };
      const map: GroupSelectionMap = new Map();
      map.set(itemId, { skuId, quantity: 1 });
      next[group.id] = map;
      return next;
    });
  }

  function toggleMultiple(group: ShopMenuToppingGroup, itemId: string) {
    const item = (group.items ?? []).find((i) => i.id === itemId);
    if (!item) return;
    const skuId = pickDefaultItemSku(item);
    if (skuId === null) return;
    setSelections((prev) => {
      const next = { ...prev };
      const map = new Map(next[group.id] ?? new Map());
      if (map.has(itemId)) {
        map.delete(itemId);
      } else {
        map.set(itemId, { skuId, quantity: 1 });
      }
      next[group.id] = map;
      return next;
    });
  }

  function changeQty(group: ShopMenuToppingGroup, itemId: string, delta: number) {
    setSelections((prev) => {
      const next = { ...prev };
      const map = new Map(next[group.id] ?? new Map());
      const current = map.get(itemId);
      if (!current) return prev;
      const nextQty = Math.max(
        1,
        Math.min(group.max_qty_per_item, current.quantity + delta),
      );
      map.set(itemId, { ...current, quantity: nextQty });
      next[group.id] = map;
      return next;
    });
  }

  async function handleSubmit() {
    if (!selectedSku) return;
    if (hasViolations) return;
    setSubmitting(true);
    setErrorMessage(null);
    try {
      // Trim before forwarding so trailing whitespace doesn't bust the
      // BR-OI06 merge key — `"no onion "` should still match `"no onion"`.
      const trimmed = note.trim();
      await onSubmit(selectedSku, flattened, trimmed === "" ? undefined : trimmed);
      setOpen(false);
    } catch (e) {
      const msg = e instanceof Error ? e.message : "Unknown error";
      setErrorMessage(msg);
    } finally {
      setSubmitting(false);
    }
  }

  const productDescription = product.product?.description?.trim() || null;
  const variantCount = skus.length;
  const submitLabel = t(
    mode === "edit"
      ? "pos.dialog.product_options.save"
      : "pos.dialog.product_options.add_to_cart",
  );

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      {!isControlled && children && <DialogTrigger asChild>{children}</DialogTrigger>}
      {/*
       * REDESIGNED dialog layout — pure block flow + sticky header/footer.
       *
       * Previous attempts (flex flex-col + max-h, grid grid-rows, h-fit
       * with flex-1 body…) all hit some browser quirk where the dialog
       * stretched to max-h-[90vh] regardless of content size, leaving
       * dead space at the bottom of the modal.
       *
       * This design eliminates the whitespace bug structurally:
       *   - DialogContent is `block` display, NOT flex. The browser sizes
       *     it to its block-flow content height. There's no flex algorithm
       *     that can "claim available space" and stretch it.
       *   - `max-h-[90vh] overflow-y-auto` caps height and triggers
       *     scrolling only when content overflows.
       *   - Header / footer use `position: sticky` so they stick to the
       *     viewport edges of the scroll container while body content
       *     scrolls in between. No flex-1, no min-h-0, no shrink chains.
       *
       * Net result: modal is ALWAYS exactly its content height (capped at
       * 90vh with internal scroll). No whitespace below footer is
       * physically possible. */}
      <DialogContent className="block max-h-[90vh] w-[95vw] !max-w-2xl gap-0 overflow-y-auto rounded-2xl p-0 lg:!max-w-4xl">
        {/* Header — sticky at the top of the scroll container. Background
            opaque so body content scrolling underneath doesn't bleed
            through. z-20 (above the lg sidebar's z-10) so its border-b
            covers the sidebar's sticky top edge cleanly. The default
            top-right close X is provided by DialogContent. */}
        <DialogHeader className="sticky top-0 z-20 border-b bg-background px-4 pb-4 pt-5 text-left sm:px-6 sm:pb-5 sm:pt-6">
          <div className="flex items-center gap-2">
            <DialogTitle className="text-lg font-bold tracking-tight sm:text-xl">
              {product.product?.name ?? t("pos.menu.product_fallback")}
            </DialogTitle>
            <HelpButton topic="product-options" className="size-7" />
            {activePromotion && (
              <PromotionBadge
                discountPercent={activePromotion.discount_percent}
                endsAt={activePromotion.ends_at}
              />
            )}
          </div>
          {variantCount > 1 && (
            <DialogDescription className="text-sm text-muted-foreground">
              {variantCount} {t("pos.variant.count")}
            </DialogDescription>
          )}
        </DialogHeader>

        {/*
         * Body wrapper — grid 2-col on lg+ (form on left, live preview
         * sidebar on right), single column on mobile.
         *
         * `items-start` keeps each cell at its natural content height so
         * the row doesn't stretch to max-content (no whitespace inside
         * either column). The right column uses `lg:sticky` so the
         * preview stays visible while the user scrolls through long
         * topping lists in the left column.
         */}
        <div className="grid grid-cols-1 gap-x-6 px-4 py-4 sm:px-6 sm:py-5 lg:grid-cols-[minmax(0,1fr)_280px] lg:items-start">
          {/* Left column — the form (description, SKU, toppings, note). */}
          <div className="space-y-5 lg:min-w-0">
          {productDescription && (
            <p className="-mt-1 text-sm leading-relaxed text-muted-foreground">
              {productDescription}
            </p>
          )}

          {/* SKU section — variants/options remain selectable in edit mode.
              The save mutation replaces SKU + toppings + note atomically. */}
          {skus.length > 1 && (
            <section className="space-y-3">
              <h3 className="text-base font-bold text-foreground">
                {t("pos.dialog.product_options.variant_section")}
              </h3>
              <RadioGroup
                value={selectedSkuId}
                onValueChange={setSelectedSkuId}
                className="grid grid-cols-1 gap-3 sm:grid-cols-2"
              >
                {skus.map((sku) => {
                  const isSelected = selectedSkuId === sku.id;
                  return (
                    <label
                      key={sku.id}
                      htmlFor={`opt-sku-${sku.id}`}
                      className={cn(
                        "group flex cursor-pointer items-center gap-3 rounded-xl border-2 bg-card p-3 transition-all",
                        isSelected
                          ? "border-primary shadow-sm"
                          : "border-border/60 hover:border-primary/40",
                      )}
                    >
                      <RadioGroupItem
                        id={`opt-sku-${sku.id}`}
                        value={sku.id}
                        className="sr-only"
                      />
                      <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span className="truncate text-sm font-bold text-foreground">
                          {sku.product_sku?.name?.trim() ||
                            t("pos.variant.default")}
                        </span>
                        {sku.product_sku?.sku && (
                          <span className="truncate text-xs text-muted-foreground">
                            {sku.product_sku.sku}
                          </span>
                        )}
                        {activePromotion ? (
                          <StrikethroughPrice
                            current={priceForDisplay(discountedSkuPrice(sku))}
                            original={priceForDisplay(effectiveSkuPrice(sku))}
                            formatCurrency={formatCurrency}
                            className="mt-1 text-base font-bold tabular-nums"
                          />
                        ) : (
                          <span className="mt-1 text-base font-bold tabular-nums text-primary">
                            {formatCurrency(priceForDisplay(effectiveSkuPrice(sku)))}
                          </span>
                        )}
                      </div>
                    </label>
                  );
                })}
              </RadioGroup>
            </section>
          )}

          {/* Topping groups. */}
          {toppingGroups.map((group) => {
            const map = selections[group.id] ?? new Map();
            const violation = groupViolations[group.id];
            const isSingle = group.selection_type === "single";
            const constraintLabel = isSingle
              ? t("pos.topping.pick_one")
              : group.effective_max_select !== null
                ? t("pos.topping.pick_up_to", {
                    max: group.effective_max_select,
                  })
                : t("pos.topping.pick_optional");
            const isMandatory = group.effective_min_select >= 1;

            return (
              <section key={group.id} className="space-y-3">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <h3 className="text-base font-bold text-foreground">
                    {group.name}
                  </h3>
                  <span className="text-sm text-muted-foreground">
                    ({constraintLabel})
                  </span>
                  {isMandatory && (
                    <Badge
                      variant="destructive"
                      className="h-5 px-1.5 text-[10px]"
                    >
                      {t("pos.topping.mandatory")}
                    </Badge>
                  )}
                </div>

                {/* Image-thumb only on the *real* "Topping thêm" group —
                    identified by `max_qty_per_item > 1` (you can stack the
                    same topping multiple times). Modifier-style groups
                    (Mức cay / Gia vị / Tuỳ chỉnh đồ uống) all have
                    `max_qty_per_item = 1` and stay text-only so the picker
                    doesn't read like a photo gallery for level pickers. */}
                {isSingle ? (
                  <RadioGroup
                    value={Array.from(map.keys())[0] ?? ""}
                    onValueChange={(v) => toggleSingle(group, v)}
                    className="grid grid-cols-1 gap-3 sm:grid-cols-2"
                  >
                    {(group.items ?? []).map((item) => {
                      const checked = map.has(item.id);
                      const skuId = checked
                        ? map.get(item.id)!.skuId
                        : pickDefaultItemSku(item);
                      // An item with no resolvable product_sku_id can't be
                      // ordered (the order-write path requires it), so the
                      // toggle handlers silently no-op. Surface that as a
                      // visibly-disabled option instead of a dead click (#1708).
                      const selectable = skuId !== null;
                      const extra =
                        skuId !== null
                          ? effectivePriceForVariant(item, skuId)
                          : 0;
                      return (
                        <label
                          key={item.id}
                          htmlFor={`opt-${group.id}-${item.id}`}
                          aria-disabled={!selectable}
                          className={cn(
                            "flex items-center gap-3 rounded-xl border-2 bg-card px-4 py-3 transition-all",
                            !selectable
                              ? "cursor-not-allowed border-border/40 opacity-50"
                              : checked
                                ? "cursor-pointer border-primary shadow-sm"
                                : "cursor-pointer border-border/60 hover:border-primary/40",
                          )}
                        >
                          <RadioGroupItem
                            id={`opt-${group.id}-${item.id}`}
                            value={item.id}
                            disabled={!selectable}
                            className="sr-only"
                          />
                          <div className="flex min-w-0 flex-1 flex-col gap-1">
                            <span className="truncate text-sm font-bold text-foreground">
                              {item.name}
                            </span>
                            {!selectable ? (
                              <span className="text-xs font-medium text-muted-foreground">
                                {t("pos.dialog.product_options.unavailable")}
                              </span>
                            ) : (
                              extra !== 0 && (
                                <span
                                  className={cn(
                                    "text-sm font-semibold tabular-nums",
                                    extra > 0
                                      ? "text-emerald-600 dark:text-emerald-400"
                                      : "text-destructive",
                                  )}
                                >
                                  {extra > 0 ? "+" : ""}
                                  {formatCurrency(priceForDisplay(extra))}
                                </span>
                              )
                            )}
                          </div>
                        </label>
                      );
                    })}
                  </RadioGroup>
                ) : (
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    {(group.items ?? []).map((item) => {
                      const checked = map.has(item.id);
                      const picked = map.get(item.id);
                      const skuId =
                        picked?.skuId ?? pickDefaultItemSku(item);
                      // See the single-select branch: an unorderable item
                      // (no product_sku_id) is shown visibly disabled rather
                      // than as a silent dead click (#1708).
                      const selectable = skuId !== null;
                      const extra =
                        skuId !== null
                          ? effectivePriceForVariant(item, skuId)
                          : 0;
                      const showStepper =
                        checked && group.max_qty_per_item > 1;
                      const showThumb = group.max_qty_per_item > 1;
                      return (
                        <label
                          key={item.id}
                          htmlFor={`opt-${group.id}-${item.id}`}
                          aria-disabled={!selectable}
                          className={cn(
                            "flex items-center gap-3 rounded-xl border-2 bg-card transition-all",
                            showThumb ? "p-2.5" : "px-4 py-3",
                            !selectable
                              ? "cursor-not-allowed border-border/40 opacity-50"
                              : checked
                                ? "cursor-pointer border-primary shadow-sm"
                                : "cursor-pointer border-border/60 hover:border-primary/40",
                          )}
                        >
                          <Checkbox
                            id={`opt-${group.id}-${item.id}`}
                            checked={checked}
                            disabled={!selectable}
                            onCheckedChange={() =>
                              toggleMultiple(group, item.id)
                            }
                            className="sr-only"
                          />
                          {showThumb && (
                            <ToppingThumb
                              src={item.image_url ?? null}
                              alt={item.name ?? ""}
                            />
                          )}
                          <div className="flex min-w-0 flex-1 flex-col gap-1">
                            <span className="truncate text-sm font-bold text-foreground">
                              {item.name}
                            </span>
                            {!selectable ? (
                              <span className="text-xs font-medium text-muted-foreground">
                                {t("pos.dialog.product_options.unavailable")}
                              </span>
                            ) : (
                              extra !== 0 && (
                                <span
                                  className={cn(
                                    "text-sm font-semibold tabular-nums",
                                    extra > 0
                                      ? "text-emerald-600 dark:text-emerald-400"
                                      : "text-destructive",
                                  )}
                                >
                                  {extra > 0 ? "+" : ""}
                                  {formatCurrency(priceForDisplay(extra))}
                                </span>
                              )
                            )}
                          </div>
                          {showStepper && picked && (
                            <div
                              className="flex items-center gap-1 rounded-full bg-muted/60 p-0.5"
                              onClick={(e) => e.preventDefault()}
                            >
                              <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="size-7 rounded-full"
                                onClick={(e) => {
                                  e.preventDefault();
                                  changeQty(group, item.id, -1);
                                }}
                                disabled={picked.quantity <= 1}
                                aria-label={t("common.decrease")}
                              >
                                <MinusIcon className="size-3" />
                              </Button>
                              <span className="w-5 text-center text-sm font-semibold tabular-nums">
                                {picked.quantity}
                              </span>
                              <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="size-7 rounded-full"
                                onClick={(e) => {
                                  e.preventDefault();
                                  changeQty(group, item.id, +1);
                                }}
                                disabled={
                                  picked.quantity >= group.max_qty_per_item
                                }
                                aria-label={t("common.increase")}
                              >
                                <PlusIcon className="size-3" />
                              </Button>
                            </div>
                          )}
                        </label>
                      );
                    })}
                  </div>
                )}

                {violation && (
                  <Alert variant="destructive" className="py-2">
                    <AlertCircleIcon className="size-3.5" />
                    <AlertDescription className="text-xs">
                      {violation}
                    </AlertDescription>
                  </Alert>
                )}
              </section>
            );
          })}

          {/* Kitchen note. */}
          <section className="space-y-3">
            <label htmlFor="opt-note" className="text-base font-bold text-foreground">
              {t("pos.dialog.product_options.note_label")}
            </label>
            <Textarea
              id="opt-note"
              value={note}
              onChange={(e) => setNote(e.target.value)}
              placeholder={t("pos.dialog.product_options.note_placeholder")}
              rows={2}
              maxLength={255}
              className="resize-none rounded-xl text-sm"
            />
          </section>
          </div>
          {/* end left column */}

          {/* Right column — live preview sidebar.
              - Mobile: in-flow card after the form, collapsible (default
                collapsed) so it doesn't push the form down too far.
              - Lg+: sticky right column, always expanded (no toggle). */}
          <aside className="mt-5 lg:mt-0">
            <div className="space-y-3 rounded-2xl border bg-muted/30 p-4 lg:sticky lg:top-[var(--pod-header-h,5.5rem)]">
              {/* Header — clickable toggle on mobile, plain heading on lg+. */}
              <button
                type="button"
                onClick={() => setPreviewExpanded((v) => !v)}
                aria-expanded={previewExpanded}
                aria-controls="pod-preview-details"
                className="flex w-full cursor-pointer items-center justify-between gap-2 text-left lg:cursor-default"
              >
                <h3 className="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-muted-foreground">
                  {t("pos.dialog.product_options.summary_title")}
                  {selectedToppingsForSummary.length > 0 && (
                    <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-bold tabular-nums text-primary">
                      {selectedToppingsForSummary.length}
                    </span>
                  )}
                </h3>
                <ChevronDownIcon
                  className={cn(
                    "size-4 text-muted-foreground transition-transform lg:hidden",
                    previewExpanded && "rotate-180",
                  )}
                />
              </button>

              {/* Details — collapsed by default on mobile, always visible
                  at lg+ via `lg:block` overriding `hidden`. */}
              <div
                id="pod-preview-details"
                className={cn(
                  "space-y-3",
                  !previewExpanded && "hidden lg:block",
                )}
              >
                {/* Selected SKU recap */}
                {selectedSku && (
                  <div className="rounded-lg border border-border/60 bg-background p-3">
                    <div className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                      {t("pos.dialog.product_options.summary_base")}
                    </div>
                    <div className="mt-0.5 flex items-baseline justify-between gap-2">
                      <span className="min-w-0 truncate text-sm font-bold text-foreground">
                        {selectedSku.product_sku?.name?.trim() ||
                          t("pos.variant.default")}
                      </span>
                      <span className="shrink-0 text-sm font-bold tabular-nums text-primary">
                        {formatCurrency(priceForDisplay(skuPrice))}
                      </span>
                    </div>
                  </div>
                )}

                {/* Topping lines */}
                <div>
                  <div className="mb-1.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                    {t("pos.dialog.product_options.topping_total")}
                  </div>
                  {selectedToppingsForSummary.length === 0 ? (
                    <p className="text-xs italic text-muted-foreground/70">
                      {t("pos.dialog.product_options.summary_no_toppings")}
                    </p>
                  ) : (
                    <ul className="space-y-1.5">
                      {selectedToppingsForSummary.map((row) => (
                        <li
                          key={row.id}
                          className="flex items-baseline justify-between gap-2 text-xs"
                        >
                          <div className="min-w-0">
                            <div className="truncate text-foreground">
                              {row.itemName}
                              {row.quantity > 1 && (
                                <span className="text-muted-foreground">
                                  {" "}× {row.quantity}
                                </span>
                              )}
                            </div>
                            <div className="truncate text-[10px] text-muted-foreground/70">
                              {row.groupName}
                            </div>
                          </div>
                          {row.extraPrice !== 0 && (
                            <span
                              className={cn(
                                "shrink-0 text-xs font-medium tabular-nums",
                                row.extraPrice > 0
                                  ? "text-emerald-600 dark:text-emerald-400"
                                  : "text-destructive",
                              )}
                            >
                              {row.extraPrice > 0 ? "+" : ""}
                              {formatCurrency(priceForDisplay(row.lineTotal))}
                            </span>
                          )}
                        </li>
                      ))}
                    </ul>
                  )}
                </div>

                {/* Note preview */}
                {note.trim() && (
                  <div>
                    <div className="mb-1 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                      {t("pos.dialog.product_options.note_label")}
                    </div>
                    <p className="whitespace-pre-line break-words text-xs text-foreground">
                      {note.trim()}
                    </p>
                  </div>
                )}

                {/* Running total */}
                <div className="flex items-baseline justify-between gap-3 border-t border-border/60 pt-3">
                  <span className="text-xs font-bold uppercase tracking-wide text-muted-foreground">
                    {t("pos.dialog.product_options.running_total")}
                  </span>
                  {runningTotalOriginal !== null ? (
                    <StrikethroughPrice
                      current={priceForDisplay(runningTotal)}
                      original={priceForDisplay(runningTotalOriginal)}
                      formatCurrency={formatCurrency}
                      className="text-lg font-bold tabular-nums"
                    />
                  ) : (
                    <span className="text-lg font-bold tabular-nums text-primary">
                      {formatCurrency(priceForDisplay(runningTotal))}
                    </span>
                  )}
                </div>
              </div>
            </div>
          </aside>
        </div>
        {/* end scrollable body */}

        {/* Footer — sticky at the bottom of the scroll container. Stays
            visible while body scrolls; sits flush against the dialog's
            bottom edge when content is short, with NO whitespace below. */}
        <div className="sticky bottom-0 z-10 space-y-3 border-t bg-card px-4 py-3 sm:px-6 sm:py-4">
          <div className="flex items-baseline justify-between gap-3">
            <span className="text-base font-bold text-foreground">
              {t("pos.dialog.product_options.running_total")}
            </span>
            {runningTotalOriginal !== null ? (
              <span className="inline-flex items-baseline gap-2">
                <span className="text-sm text-muted-foreground line-through tabular-nums">
                  {formatCurrency(priceForDisplay(runningTotalOriginal))}
                </span>
                <span className="text-2xl font-bold tabular-nums text-amber-600">
                  {formatCurrency(priceForDisplay(runningTotal))}
                </span>
              </span>
            ) : (
              <span className="text-2xl font-bold tabular-nums text-primary">
                {formatCurrency(priceForDisplay(runningTotal))}
              </span>
            )}
          </div>

          {errorMessage && (
            <Alert variant="destructive" className="py-2">
              <AlertCircleIcon className="size-4" />
              <AlertDescription className="text-xs">
                {errorMessage}
              </AlertDescription>
            </Alert>
          )}

          <Button
            type="button"
            disabled={!canSubmit}
            onClick={handleSubmit}
            size="lg"
            className="h-12 w-full rounded-xl text-base font-semibold"
          >
            {submitting ? <Spinner /> : submitLabel}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Square 1:1 thumbnail rendered to the left of every topping option.
 * Falls back to a muted placeholder + ImageIcon when the topping product
 * has no gallery image (typical when the seeder hasn't run yet for older
 * databases). Sized at 56px so it lines up with the option label without
 * dominating the row on narrow mobile widths.
 */
function ToppingThumb({ src, alt }: { src: string | null; alt: string }) {
  return (
    <div className="size-14 shrink-0 overflow-hidden rounded-lg bg-muted">
      {src ? (
        <img
          src={src}
          alt={alt}
          loading="lazy"
          className="size-full object-cover"
        />
      ) : (
        <div className="flex size-full items-center justify-center text-muted-foreground/40">
          <ImageIcon className="size-5" />
        </div>
      )}
    </div>
  );
}
