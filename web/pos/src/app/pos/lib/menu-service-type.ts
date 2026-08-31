/**
 * #481 — map a POS order_type onto the menu `service_type` gate.
 *
 * Mirrors the customer-web split (#463): a DineIn order should only see
 * DineIn (or Both / master-inherited) menus, and likewise for Takeaway.
 * The backend (`Shop\MenuController::validatedServiceType`) applies the
 * actual filtering; the FE just forwards the resolved value.
 *
 *   dine_in  → "DineIn"
 *   takeaway → "Takeaway"
 *   spot     → undefined (both lists — see below)
 *   undefined/no order yet → undefined (no gate — there is no order to gate on)
 *
 * #1745 — `spot` used to fall through to "no gate, show every menu", and that
 * was a money bug, not a cosmetic one. Consumption context (店内 vs 持ち帰り) is
 * a MENU concern here: `TaxResolver` takes no order_type, so 8% vs 10% rides
 * whichever menu line the item was ordered from (plan-043 / #1099). An
 * ungated `spot` order could therefore be rung up against a Takeaway menu and
 * take 軽減 8% on food eaten in — and the rate snapshots onto the order line
 * immutably, so changing the order type afterwards cannot repair it.
 *
 * It was not even a mis-click away: `spot` is the dialog's default AND the
 * column default, and `MenuCatalog.pickActiveMenu` auto-selects by time window,
 * falling back to `menus[0]` — which in an ungated list can be a Takeaway menu
 * nobody chose.
 *
 * #1765 — the fix above answered that by taking the choice away, and the cost
 * landed on the counter: a `spot` shift sells both eat-in and takeaway, so the
 * cashier had to switch the whole order type just to reach a Takeaway menu.
 * `spot` is ungated again — but only for what the cashier may CHOOSE. What the
 * app may choose FOR them is a separate question now, answered by
 * `isAutoSelectableMenu` below, and its answer is still never Takeaway. So the
 * hole #1745 closed stays closed: reaching 8% on a `spot` order takes a
 * deliberate pick from a dropdown that labels every menu with its service type
 * (#1756).
 *
 * `dine_in` and `takeaway` keep their gate — those order types already state
 * the intent, so listing the other side would be noise.
 */

import type { CustomerOrderType, MenuServiceType } from "@/app/pos/types";

export function orderTypeToServiceType(
  orderType: CustomerOrderType | null | undefined,
): MenuServiceType | undefined {
  switch (orderType) {
    case "dine_in":
      return "DineIn";
    case "takeaway":
      return "Takeaway";
    // "spot" (Nhanh) rings up at the counter with no table and can be either
    // eaten in or carried out, so the cashier sees both lists (#1765). The
    // auto-pick guard below is what keeps that from costing 2% by accident.
    case "spot":
    default:
      // No active order — nothing to gate on, so show every menu.
      return undefined;
  }
}

/**
 * #1756 — the other direction: what service type IS this menu?
 *
 * The gate above only ever *filtered*; it never told the cashier what they were
 * looking at. Two cases make that a real gap rather than a nicety: with no
 * active order there is nothing to gate on, so the dropdown lists DineIn and
 * Takeaway menus side by side (and `pickActiveMenu` auto-selects one by time
 * window); and a `Both` menu shows under every order type by definition.
 * Because the tax rate rides the menu line and snapshots immutably, "which menu
 * am I on" is a money question.
 *
 * Two feeds answer it differently, so read them in this order:
 *
 *   - Cloud sends `effective_service_type` (own ?? master ?? "Both") alongside
 *     a raw `service_type` whose `null` means "inherit" — unrenderable alone.
 *   - The workstation LAN feed sends only `service_type`, already resolved
 *     upstream by MenuCatalogReplicaBuilder.
 *
 * `undefined` when neither is present — a backend or workstation older than
 * #1756. The caller must then show NO badge: inventing "Both" would state a
 * service type the server never asserted, on the one screen where being wrong
 * costs 2% tax.
 */
export function resolveMenuServiceType(menu: {
  service_type?: MenuServiceType | null;
  effective_service_type?: MenuServiceType | null;
}): MenuServiceType | undefined {
  return menu.effective_service_type ?? menu.service_type ?? undefined;
}

/**
 * #1765 — may the app auto-select this menu when the cashier hasn't picked one?
 *
 * Only `spot` needs an answer other than "yes": its list now spans both service
 * types, and `MenuCatalog.pickActiveMenu` fires on mount by time window with a
 * `menus[0]` fallback. Letting that land on a Takeaway menu is the exact #1745
 * money bug — 8% snapshotted onto an eat-in line, unrepairable afterwards —
 * except now nobody even chose the order type wrongly.
 *
 * So: the cashier may pick Takeaway for a `spot` order; the app may not pick it
 * for them. Mis-classifying toward the STANDARD rate stays the safe direction.
 *
 * A menu whose service type the server never stated (backend/workstation older
 * than #1756) counts as auto-selectable. Refusing it would be the stricter
 * read, but on an old feed NO menu carries the field — the grid would then
 * never unlock at all, turning a tax guard into an outage. `pickActiveMenu`
 * falls back to the full list when this predicate excludes everything, which
 * covers the same ground without the cliff.
 */
export function isAutoSelectableMenu(
  orderType: CustomerOrderType | null | undefined,
  menu: { service_type?: MenuServiceType | null; effective_service_type?: MenuServiceType | null },
): boolean {
  if (orderType !== "spot") return true;

  return resolveMenuServiceType(menu) !== "Takeaway";
}

/**
 * i18n keys, reusing the ORDER-TYPE wording the cashier already reads in the
 * create-order dialog (イートイン / テイクアウト, Tại chỗ / Mang đi). Two
 * vocabularies for one distinction would be its own bug.
 */
export const MENU_SERVICE_TYPE_LABEL_KEY: Record<MenuServiceType, string> = {
  DineIn: "pos.order_type.dine_in",
  Takeaway: "pos.order_type.takeaway",
  Both: "pos.menu.service_type_both",
};

/**
 * Badge colours, matching admin-web's HQ menu table (emerald / amber / slate)
 * so the same menu reads the same in both apps — but expressed in the
 * dark-mode-aware token style pos-web uses, since POS runs a dark theme.
 *
 * `Both` is deliberately muted: it is the "no answer" value, not a third
 * category worth competing for attention.
 */
export const MENU_SERVICE_TYPE_BADGE_CLASS: Record<MenuServiceType, string> = {
  DineIn: "bg-emerald-500/10 text-emerald-600 dark:text-emerald-400",
  Takeaway: "bg-amber-500/10 text-amber-600 dark:text-amber-400",
  Both: "bg-muted text-muted-foreground",
};
