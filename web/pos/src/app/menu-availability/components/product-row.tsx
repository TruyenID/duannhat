/**
 * plan-056 — one dish on the "Tồn món" screen.
 *
 * Collapsed by default: a shop with 300 dishes must not pay to render 900
 * variant rows to answer "is phở on today". Variants load with the menu (one
 * request), so expanding is instant and offline-safe — the cost avoided here is
 * DOM, not network.
 *
 * Expanding a dish reveals two disclosures rather than one flat stack:
 *
 *   dish  →  Biến thể (open)      →  variant rows
 *         →  Topping (closed)     →  group  →  item  →  add-on prices
 *
 * The nesting mirrors admin-web's, so a shop that manages stock on a laptop and
 * then on a tablet reads the same tree. Variants default OPEN because that layer
 * can sell nothing while looking fine (`soldOutByVariants` below); toppings
 * default closed because a dish with six groups of six is 36 rows that would
 * bury the dish underneath it.
 *
 * **The whole row expands, not just a chevron.** A 16px arrow is a poor target
 * for a thumb on a tablet, and every miss is a tap that does nothing — so staff
 * aim, miss, and aim again. The row-wide surface is a full-bleed transparent
 * button BEHIND the content (see the comment at its JSX); the switch keeps its
 * own hit area on top, so the one tap that must stay precise still is.
 */

import { useState } from "react";
import { ChevronDown, ImageIcon } from "lucide-react";
import { Switch } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { cn } from "@/lib/utils";
import type {
  AvailabilityProduct,
  AvailabilitySku,
  AvailabilityToppingItem,
} from "@/services/menu-availability-service";
import { formatCurrency } from "@/app/pos/lib/totals";
import { AvailabilitySkuTable } from "./sku-table";
import { AvailabilityToppingList } from "./topping-list";
import { CollapsibleSection } from "./collapsible-section";
import { OptionValueSwitches, type OptionValueGroup } from "./option-value-switches";

export interface AvailabilityProductRowProps {
  product: AvailabilityProduct;
  isPending: boolean;
  pendingSkuId: string | null;
  pendingToppingId: string | null;
  onToggleProduct: (product: AvailabilityProduct, next: boolean) => void;
  onToggleSku: (
    product: AvailabilityProduct,
    sku: AvailabilitySku,
    next: boolean,
  ) => void;
  onToggleTopping: (
    product: AvailabilityProduct,
    item: AvailabilityToppingItem,
    next: boolean,
  ) => void;
  /** "Hết cỡ Lớn" — one switch that moves every variant carrying the value. */
  onToggleOptionValue: (product: AvailabilityProduct, group: OptionValueGroup, next: boolean) => void;
  pendingOptionValueId: string | null;
}

export function AvailabilityProductRow({
  product,
  isPending,
  pendingSkuId,
  pendingToppingId,
  pendingOptionValueId,
  onToggleProduct,
  onToggleSku,
  onToggleTopping,
  onToggleOptionValue,
}: AvailabilityProductRowProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);

  const name = product.product?.name || t("menu_availability.untitled");
  const image = product.product?.image_url ?? null;
  const activeSkus = product.skus.filter((s) => s.is_active).length;

  const toppingGroups = product.topping_groups ?? [];
  const hasToppings = toppingGroups.some((g) => g.items.length > 0);
  // The chevron has to appear when there is EITHER layer to manage. A dish with
  // no variants but three topping groups is common (a simple bowl of phở), and
  // hiding its expander would put its toppings out of reach.
  const hasVariants = product.skus.length > 0 || hasToppings;
  const hasVariantAxis = product.skus.length > 1;
  const soleSku = product.skus.length === 1 ? product.skus[0] : null;

  /**
   * A dish that is ON while NONE of its variants are sellable.
   *
   * This state is reachable and it sells nothing: the dish switch reads on, the
   * cart picker shows no line to add, and staff have no reason to suspect the
   * variant layer. It happens most often on a simple product whose single
   * variant was turned off from admin-web.
   *
   * An earlier revision of this screen hid the variant table for
   * single-variant dishes, on the theory that the dish switch was the whole
   * answer. That was wrong: it made this exact state UNFIXABLE from the POS —
   * the dish looked on, nothing sold, and there was no control to undo it. The
   * table stays for every dish that has variants; the confusing state is
   * SPELLED OUT here instead of being hidden behind a removed control.
   */
  const soldOutByVariants = product.is_active && hasVariants && activeSkus === 0;

  return (
    <div
      className={cn(
        "overflow-hidden rounded-lg border bg-card",
        !product.is_active && "opacity-65",
      )}
      data-slot="availability-product-row"
      data-testid={`availability-product-${product.id}`}
    >
      <div className="relative flex items-center gap-3 p-3">
        {/* THE WHOLE ROW IS THE EXPANDER.

            A full-bleed transparent button sitting behind the row's content,
            rather than an onClick on the wrapping <div>. Three reasons, in
            order of how much they cost to get wrong:

              · The row contains a Switch. Nesting one interactive control
                inside another is invalid HTML and breaks both keyboard order
                and screen-reader semantics — so the click surface CANNOT be an
                ancestor of the switch. As a sibling it can cover the same area
                without containing it.
              · A <div onClick> is not reachable by keyboard and announces
                nothing. This is a real <button> with `aria-expanded` /
                `aria-controls`, so it works from a keyboard and a screen reader
                is told what it opens.
              · Hit-testing does the disambiguation, not event bookkeeping: the
                switch sits ON TOP (later sibling, positioned), so a tap on it
                never reaches this button and needs no stopPropagation. Nothing
                to keep in step when a control is added to the row later.

            Content below is `relative` so it paints above this button, and
            `pointer-events-none` so taps fall through to it. Rendered only when
            there is something to expand — a click surface that opens nothing
            teaches staff the row is not worth pressing. */}
        {hasVariants && (
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            aria-expanded={open}
            aria-controls={`availability-detail-${product.id}`}
            aria-label={t("menu_availability.expand_variants_aria", { name })}
            className="absolute inset-0 cursor-pointer rounded-lg transition-colors hover:bg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
            data-testid={`availability-product-expand-${product.id}`}
          />
        )}

        {image ? (
          <img
            src={image}
            alt=""
            className="pointer-events-none relative size-12 shrink-0 rounded object-cover"
            loading="lazy"
          />
        ) : (
          <div className="pointer-events-none relative flex size-12 shrink-0 items-center justify-center rounded border bg-muted">
            <ImageIcon className="size-4 text-muted-foreground" />
          </div>
        )}

        <div className="pointer-events-none relative min-w-0 flex-1">
          <p className="truncate text-base font-medium">{name}</p>
          <p className="mt-0.5 text-xs text-muted-foreground">
            {hasVariantAxis || soleSku == null
              ? t("menu_availability.variants_summary", {
                  active: activeSkus,
                  total: product.skus.length,
                })
              : // For a simple dish, "1/1 variants on sale" says nothing a
                // cashier needs. The price and the SKU code do: they are how
                // you match the row against a package or a printed menu.
                [soleSku?.sku, soleSku ? formatCurrency(soleSku.selling_price) : null]
                  .filter(Boolean)
                  .join(" · ")}
          </p>
          {/* The silent-no-sale state, called out on the row. Without this the
              only clue is a "0/1" buried in the summary above, and the dish
              switch says everything is fine. */}
          {soldOutByVariants && (
            <p className="mt-1 text-xs font-medium text-amber-600 dark:text-amber-400">
              {t("menu_availability.no_sellable_variant")}
            </p>
          )}
          {/* Why it is off, on the row itself. Staff asked "who turned this
              off and when" more often than anything else, and making them
              open a dialog for it turns a glance into a task. */}
          {!product.is_active && product.disabled_reason && (
            <p className="mt-1 text-xs font-medium text-destructive">
              {product.disabled_reason}
              {product.disabled_by_name && (
                <span className="ml-1 font-normal text-muted-foreground">
                  · {product.disabled_by_name}
                </span>
              )}
            </p>
          )}
        </div>

        {/* The switch is the ONLY thing in the row that keeps its own hit area.
            It is a sibling of the expander, not a child, and being later in the
            DOM it paints — and hit-tests — on top: a tap here toggles the dish
            and never expands the row.

            size-11 wrapper: the switch itself is ~18px tall, far under the 44px
            a thumb needs on a tablet held mid-service, and the surrounding pad
            must belong to the SWITCH rather than to the expander behind it. */}
        <div className="relative z-10 flex size-11 shrink-0 items-center justify-center">
          <Switch
            checked={product.is_active}
            disabled={isPending}
            onCheckedChange={(next: boolean) => onToggleProduct(product, next)}
            aria-label={t("menu_availability.toggle_product_aria", { name })}
            data-testid={`availability-product-switch-${product.id}`}
          />
        </div>

        {/* Indicator, not a control — the whole row is the control now. Kept
            because a row that expands with no affordance is a row nobody knows
            to press; `pointer-events-none` lets the tap through to the
            expander behind it, so the corner it occupies stays live. */}
        {hasVariants && (
          <ChevronDown
            className={cn(
              "pointer-events-none relative size-4 shrink-0 text-muted-foreground transition-transform duration-200",
              open && "rotate-180",
            )}
            aria-hidden="true"
          />
        )}
      </div>

      {hasVariants && open && (
        <div
          id={`availability-detail-${product.id}`}
          className="flex flex-col gap-2 border-t bg-muted/20 p-3"
        >
          {product.skus.length > 0 && (
            // Variants open by default, toppings closed: the variant layer is
            // the one that can silently sell nothing (see `soldOutByVariants`),
            // so it must be visible the moment the dish is expanded. Toppings
            // are a deeper question and staff go looking for them on purpose.
            <CollapsibleSection
              id={`variants-${product.id}`}
              tone="section"
              defaultOpen
              title={t("menu_availability.variants_heading")}
              summary={t("menu_availability.variants_summary", {
                active: activeSkus,
                total: product.skus.length,
              })}
              data-testid={`availability-variants-toggle-${product.id}`}
            >
              <OptionValueSwitches
                skus={product.skus}
                parentActive={product.is_active}
                pendingValueId={pendingOptionValueId}
                onToggle={(group, next) => onToggleOptionValue(product, group, next)}
              />
              <AvailabilitySkuTable
                skus={product.skus}
                parentActive={product.is_active}
                pendingSkuId={pendingSkuId}
                onToggle={(sku, next) => onToggleSku(product, sku, next)}
              />
            </CollapsibleSection>
          )}
          <AvailabilityToppingList
            productId={product.id}
            groups={toppingGroups}
            parentActive={product.is_active}
            pendingToppingId={pendingToppingId}
            onToggle={(item, next) => onToggleTopping(product, item, next)}
          />
          {!product.is_active && (
            // Says the rule out loud instead of leaving the greyed switches
            // looking like a bug: the dish gate wins over the variant gate,
            // exactly as MenuProduct.yaml documents.
            <p className="mt-2 text-[11px] text-muted-foreground">
              {t("menu_availability.parent_off_hint")}
            </p>
          )}
        </div>
      )}
    </div>
  );
}
