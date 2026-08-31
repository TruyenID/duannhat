/**
 * plan-056 — the topping options of one dish.
 *
 * ## Shape mirrors admin-web, because the data does
 *
 *   topping group  →  topping item  →  per-variant add-on price
 *
 * A shop that manages this on a laptop and then on a tablet should be reading
 * the same tree, in the same order, with the same words.
 *
 * ## Collapsed by default, at every level
 *
 * A phở with six topping groups of six items is thirty-six rows — as a flat
 * list it buries the one dish above it that actually ran out. Each level opens
 * on demand and says in its header how much is inside and how much of it is on
 * sale, so a cashier can scan without opening anything.
 *
 * ## The add-on prices are READ-ONLY
 *
 * They are here because they are how staff tell one topping variant from
 * another ("Ít bánh +0 ¥" vs "Nhiều bánh +120 ¥"), and for no other reason.
 * Editing them means the admin sync endpoint, which replaces every override of
 * the group — this screen writes exactly one row and must keep it that way.
 *
 * ## And the switch stays at ITEM level
 *
 * The data model can scope a hide to one topping SKU, but both read paths
 * resolve "ANY shop row hidden ⇒ the whole item is hidden". Offering a per-SKU
 * switch here would render a control whose two states are indistinguishable in
 * the result — so the price rows carry no switch, deliberately.
 */

import { Switch } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { cn } from "@/lib/utils";
import { formatCurrency } from "@/app/pos/lib/totals";
import type {
  AvailabilityToppingGroup,
  AvailabilityToppingItem,
} from "@/services/menu-availability-service";
import { CollapsibleSection } from "./collapsible-section";

export interface AvailabilityToppingListProps {
  /**
   * The dish these toppings hang off. Every id and test hook below is scoped by
   * it because a topping GROUP is shared between dishes — "Topping phở" hangs
   * off both sizes of phở — so two dishes expanded at once would otherwise mint
   * the same `aria-controls` target twice, and a screen reader following the
   * second one would land in the first dish's panel.
   */
  productId: string;
  groups: AvailabilityToppingGroup[];
  /** A dish that is off greys its toppings and locks their switches. */
  parentActive: boolean;
  pendingToppingId: string | null;
  onToggle: (item: AvailabilityToppingItem, next: boolean) => void;
}

export function AvailabilityToppingList({
  productId,
  groups,
  parentActive,
  pendingToppingId,
  onToggle,
}: AvailabilityToppingListProps) {
  const { t } = useTranslation();

  // A dish with no topping groups renders nothing at all — an empty "Toppings"
  // heading on every simple dish is the kind of noise that makes staff stop
  // reading the section that matters.
  const withItems = groups.filter((g) => g.items.length > 0);
  if (withItems.length === 0) return null;

  const totalItems = withItems.reduce((n, g) => n + g.items.length, 0);
  const activeItems = withItems.reduce(
    (n, g) => n + g.items.filter((i) => i.is_active).length,
    0,
  );

  return (
    <CollapsibleSection
      id={`toppings-${productId}`}
      tone="section"
      title={t("menu_availability.toppings_heading")}
      summary={t("menu_availability.toppings_summary", {
        groups: withItems.length,
        active: activeItems,
        total: totalItems,
      })}
      data-testid={`availability-toppings-toggle-${productId}`}
    >
      <div className="flex flex-col gap-2">
        {withItems.map((group) => {
          const groupActive = group.items.filter((i) => i.is_active).length;

          return (
            <CollapsibleSection
              key={group.id}
              id={`topping-group-${productId}-${group.id}`}
              tone="group"
              title={group.name}
              summary={t("menu_availability.topping_group_summary", {
                active: groupActive,
                total: group.items.length,
              })}
              data-testid={`availability-topping-group-${productId}-${group.id}`}
            >
              <ul className="flex flex-col gap-1">
                {group.items.map((item) => (
                  <ToppingItemRow
                    key={item.id}
                    productId={productId}
                    item={item}
                    parentActive={parentActive}
                    isPending={pendingToppingId === item.id}
                    onToggle={onToggle}
                  />
                ))}
              </ul>
            </CollapsibleSection>
          );
        })}
      </div>
    </CollapsibleSection>
  );
}

function ToppingItemRow({
  productId,
  item,
  parentActive,
  isPending,
  onToggle,
}: {
  productId: string;
  item: AvailabilityToppingItem;
  parentActive: boolean;
  isPending: boolean;
  onToggle: (item: AvailabilityToppingItem, next: boolean) => void;
}) {
  const { t } = useTranslation();
  const skus = item.skus ?? [];

  const toggle = (
    <Switch
      checked={item.is_active && parentActive}
      disabled={!parentActive || isPending}
      onCheckedChange={(next: boolean) => onToggle(item, next)}
      aria-label={t("menu_availability.toggle_topping_aria", { name: item.name })}
      data-testid={`availability-topping-switch-${productId}-${item.id}`}
    />
  );

  const label = item.name || t("menu_availability.topping_unnamed");
  const dimmed = !item.is_active || !parentActive;

  // Nothing to expand: render a plain row rather than a disclosure whose panel
  // would open onto an empty box. A chevron that reveals nothing is worse than
  // no chevron — it teaches staff the chevrons are not worth pressing.
  if (skus.length === 0) {
    return (
      <li
        className={cn(
          "flex min-h-11 items-center gap-2 rounded-md border border-dashed px-3",
          dimmed && "opacity-55",
        )}
        data-testid={`availability-topping-${productId}-${item.id}`}
      >
        <span className="min-w-0 flex-1 truncate text-sm">{label}</span>
        {toggle}
      </li>
    );
  }

  return (
    <li
      className={cn(dimmed && "opacity-55")}
      data-testid={`availability-topping-${productId}-${item.id}`}
    >
      <CollapsibleSection
        id={`topping-item-${productId}-${item.id}`}
        tone="row"
        title={<span className="text-sm">{label}</span>}
        summary={t("menu_availability.topping_variants_count", { count: skus.length })}
        trailing={toggle}
        data-testid={`availability-topping-expand-${productId}-${item.id}`}
      >
        <table className="w-full text-xs">
          <thead>
            <tr className="text-muted-foreground">
              <th className="px-2 py-1 text-left font-medium">
                {t("menu_availability.col.variant")}
              </th>
              <th className="px-2 py-1 text-left font-medium">
                {t("menu_availability.col.sku_code")}
              </th>
              <th className="px-2 py-1 text-right font-medium">
                {t("menu_availability.col.addon_price")}
              </th>
            </tr>
          </thead>
          <tbody>
            {skus.map((sku) => (
              <tr key={sku.id} className="border-t">
                <td className="px-2 py-1">
                  {sku.sku_label || t("menu_availability.topping_all_variants")}
                </td>
                <td className="px-2 py-1">
                  <code className="rounded bg-muted px-1 py-0.5 text-[10px] text-muted-foreground">
                    {sku.sku_code || "—"}
                  </code>
                </td>
                <td className="px-2 py-1 text-right tabular-nums">
                  {/* `extra_price` arrives as a STRING from both servers (money
                      stays a string until it is formatted). Number() once here
                      rather than trusting whatever each transport encoded. */}
                  {formatCurrency(Number(sku.extra_price ?? 0))}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </CollapsibleSection>
    </li>
  );
}
