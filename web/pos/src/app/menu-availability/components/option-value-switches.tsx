/**
 * plan-056 — one switch per option VALUE, e.g. "hết cỡ Lớn".
 *
 * ## There is no such row as "Lớn is off at this shop"
 *
 * `product_options` / `product_option_values` hang off `product_id` with no
 * branch column, so writing the off state there would turn size Lớn off at
 * EVERY branch of the brand — a cashier in Hongo silencing Ningyocho.
 *
 * `menu_product_skus` is already per-menu and menus are per-branch, so the
 * shop-scoped address for "this variant is not sellable here" already exists.
 * An option value is therefore a NAME FOR A SET of those rows, and this strip
 * is a shortcut over the variant table below it — not a second gate.
 *
 * That matters beyond tidiness: a real override tier on the option value would
 * allow "dish on, variant on, option off", and nothing in the read path would
 * know which of the two answers wins. Here the variant rows ARE the answer, and
 * the strip just moves several of them at once.
 *
 * ## What is deliberately not rendered
 *
 *   · An axis with a single value. Turning it off equals turning every variant
 *     off, which the dish switch above already does in one tap — a second
 *     control for the same effect is a control staff have to think about.
 *   · A dish with one variant. Same reason.
 *   · Values whose variants have no pivot row yet (HQ added them after the
 *     branch cloned its menu). They have no write address, so they are counted
 *     but never sent; a value with NO writable variant is disabled outright
 *     rather than offering a switch that would report "0 changed".
 */

import { Switch } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { cn } from "@/lib/utils";
import type { AvailabilitySku } from "@/services/menu-availability-service";
import { groupByOptionValue, type OptionValueGroup } from "../lib/option-value-groups";

export type { OptionValueGroup };

export interface OptionValueSwitchesProps {
  skus: AvailabilitySku[];
  /** A dish that is off greys the strip and locks it. */
  parentActive: boolean;
  /** Value id currently being written, if any. */
  pendingValueId: string | null;
  onToggle: (group: OptionValueGroup, next: boolean) => void;
}

export function OptionValueSwitches({
  skus,
  parentActive,
  pendingValueId,
  onToggle,
}: OptionValueSwitchesProps) {
  const { t } = useTranslation();

  const groups = groupByOptionValue(skus);
  if (groups.length === 0) return null;

  // Render axis by axis so "Size" and "Cay" read as two questions, not one row
  // of six unrelated chips.
  const axes: Array<{ key: string; name: string; values: OptionValueGroup[] }> = [];
  for (const g of groups) {
    const key = g.optionId ?? g.optionName;
    let axis = axes.find((a) => a.key === key);
    if (!axis) {
      axis = { key, name: g.optionName, values: [] };
      axes.push(axis);
    }
    axis.values.push(g);
  }

  return (
    <div
      className="mb-2 flex flex-col gap-1.5 rounded-md border border-dashed bg-background p-2"
      data-slot="option-value-switches"
    >
      <p className="text-[11px] font-medium text-muted-foreground">
        {t("menu_availability.option_values_hint")}
      </p>
      {axes.map((axis) => (
        <div key={axis.key} className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <span className="min-w-16 shrink-0 text-xs font-medium">{axis.name}</span>
          {axis.values.map((g) => {
            // No write address on any carrier ⇒ nothing to send. Disabled with
            // the same reason the variant row gives, rather than a switch that
            // would report "0 changed" and look broken.
            const notYetTogglable = g.writableIds.length === 0;
            const disabled = !parentActive || notYetTogglable || pendingValueId === g.valueId;

            return (
              <label
                key={g.valueId}
                className={cn(
                  "flex h-9 items-center gap-1.5 text-xs",
                  (!g.isActive || !parentActive) && "opacity-55",
                )}
              >
                <Switch
                  checked={g.isActive && parentActive}
                  disabled={disabled}
                  onCheckedChange={(next: boolean) => onToggle(g, next)}
                  aria-label={t("menu_availability.toggle_option_aria", {
                    option: axis.name,
                    value: g.valueLabel,
                  })}
                  title={
                    notYetTogglable ? t("menu_availability.variant_not_togglable") : undefined
                  }
                  data-testid={`availability-option-switch-${g.valueId}`}
                />
                <span className="whitespace-nowrap">{g.valueLabel}</span>
              </label>
            );
          })}
        </div>
      ))}
    </div>
  );
}
