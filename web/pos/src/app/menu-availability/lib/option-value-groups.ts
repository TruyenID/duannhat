/**
 * plan-056 — grouping a dish's variants by option value.
 *
 * Its own module, not a second export from the component file: the Fast-Refresh
 * rule (`react-refresh/only-export-components`) refuses a mixed module, and the
 * grouping IS the feature — worth testing without a DOM, which a component file
 * makes awkward.
 *
 * ## There is no such row as "Lớn is off at this shop"
 *
 * `product_options` / `product_option_values` hang off `product_id` with no
 * branch column, so writing the off state there would turn size Lớn off at
 * EVERY branch of the brand — a cashier in Hongo silencing Ningyocho.
 * `menu_product_skus` is already per-menu and menus are per-branch, so the
 * shop-scoped address already exists: an option value is a NAME FOR A SET of
 * those rows, and this function resolves the set.
 */

import type { AvailabilitySku } from "@/services/menu-availability-service";

/** One option value, with the variants that carry it. */
export interface OptionValueGroup {
  optionId: string | null;
  optionName: string;
  valueId: string;
  valueLabel: string;
  /** Every variant of the dish carrying this value — including untogglable. */
  skus: AvailabilitySku[];
  /** Those with a write address. Empty ⇒ the switch is disabled. */
  writableIds: string[];
  /** ON when ANY carrier is on: the value is still sellable in some form. */
  isActive: boolean;
}

/**
 * Group a dish's variants by option value, dropping axes that cannot produce a
 * meaningful switch. Exported for tests — the grouping IS the feature, and it
 * is worth pinning without a DOM.
 */
export function groupByOptionValue(skus: AvailabilitySku[]): OptionValueGroup[] {
  if (skus.length < 2) return [];

  // Keyed on `value_id`, never the label: two option groups can carry values
  // spelled the same ("Không" under Cay and under Hành), and merging those
  // would build one switch that turns off variants nobody selected.
  const byValue = new Map<string, OptionValueGroup>();
  const axisValues = new Map<string, Set<string>>();
  const axisOrder = new Map<string, number>();

  for (const sku of skus) {
    for (const opt of sku.options ?? []) {
      const axisKey = opt.option_id ?? opt.option_name ?? "";
      if (!axisValues.has(axisKey)) {
        axisValues.set(axisKey, new Set());
        axisOrder.set(axisKey, opt.position ?? 0);
      }
      axisValues.get(axisKey)!.add(opt.value_id);

      let group = byValue.get(opt.value_id);
      if (!group) {
        group = {
          optionId: opt.option_id,
          optionName: opt.option_name ?? "",
          valueId: opt.value_id,
          valueLabel: opt.value_label ?? "",
          skus: [],
          writableIds: [],
          isActive: false,
        };
        byValue.set(opt.value_id, group);
      }
      group.skus.push(sku);
      if (sku.menu_product_sku_id != null) group.writableIds.push(sku.menu_product_sku_id);
      if (sku.is_active) group.isActive = true;
    }
  }

  return [...byValue.values()]
    .filter((g) => {
      const axisKey = g.optionId ?? g.optionName;
      // A one-value axis says nothing the dish switch does not already say.
      return (axisValues.get(axisKey)?.size ?? 0) > 1;
    })
    .sort((a, b) => {
      const oa = axisOrder.get(a.optionId ?? a.optionName) ?? 0;
      const ob = axisOrder.get(b.optionId ?? b.optionName) ?? 0;
      return oa - ob || a.valueLabel.localeCompare(b.valueLabel);
    });
}
