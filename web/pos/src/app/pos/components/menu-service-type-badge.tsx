import {
  MENU_SERVICE_TYPE_BADGE_CLASS,
  MENU_SERVICE_TYPE_LABEL_KEY,
  resolveMenuServiceType,
} from "../lib/menu-service-type";
import type { ShopMenuByDayResource } from "../types";

/**
 * #1756 — 店内 / 持ち帰り marker for a menu, so the cashier can tell a dine-in
 * menu from a takeaway one without leaving the POS to check HQ.
 *
 * Renders nothing when the server didn't state a service type (a backend or
 * workstation mirror older than #1756). Silence is the correct fallback, not a
 * defaulted "Both": the consumption-tax rate rides whichever menu line the item
 * is added from and snapshots onto the order line immutably, so a guessed badge
 * would be a confident 8%-vs-10% claim with nothing behind it.
 *
 * `t` is a prop rather than a `useTranslation()` call so the badge stays a pure
 * function of its inputs — see menu-service-type-badge.test.tsx.
 */
export function MenuServiceTypeBadge({
  menu,
  t,
}: {
  menu: Pick<ShopMenuByDayResource, "service_type" | "effective_service_type">;
  t: (key: string) => string;
}) {
  const serviceType = resolveMenuServiceType(menu);
  if (!serviceType) return null;

  return (
    <span
      data-slot="menu-service-type-badge"
      data-service-type={serviceType}
      className={`inline-flex shrink-0 items-center rounded-sm px-1.5 py-0.5 text-[10px] font-medium ${MENU_SERVICE_TYPE_BADGE_CLASS[serviceType]}`}
    >
      {t(MENU_SERVICE_TYPE_LABEL_KEY[serviceType])}
    </span>
  );
}
