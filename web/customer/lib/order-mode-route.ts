/**
 * godx-tempo#1697 — which order mode does the URL itself declare?
 *
 * `CartProvider` restores the persisted mode on mount, but the persisted mode
 * can disagree with where the customer actually is. `betoya-dine-in-table`
 * lives in sessionStorage, so a tab that once scanned a table QR keeps that
 * table for the rest of the tab's life — a reload, a bookmark, or a walk back
 * to `/takeaway/[shop]` all still find it. Hydration used to read that as
 * "this is a dine-in session", flip `orderType` to `dine_in` on the takeaway
 * route, and quietly file every item the customer added into that TABLE's
 * cart. Checkout then dead-ended on "Chưa xác định bàn".
 *
 * The route is the customer's stated intent and outranks anything left in
 * storage, so hydration asks this module first.
 *
 * Deliberately dependency-free (no next-intl, no DOM) so `node --test` can
 * load it directly — same reason `lib/shop-routes.ts` stays plain.
 */

/** Mirrors `OrderType` in `context/cart-context.tsx`. Redeclared rather than
 *  imported: cart-context imports THIS module, and pulling the type back the
 *  other way would make the cycle real for `node --test`. */
export type RouteOrderMode = "takeaway" | "dine_in";

/**
 * The order mode a pathname declares, or `null` when it declares none.
 *
 * `null` is a real answer, not a failure: `/checkout`, `/orders/[id]`, and
 * `/order-confirm/[id]` serve BOTH modes, and for those the persisted mode is
 * the only thing that can tell them apart. Callers must keep their existing
 * fallback for it.
 *
 *   /takeaway/ningyocho                  → "takeaway"
 *   /vi/takeaway/ningyocho               → "takeaway"
 *   /ja/dine-in/ningyocho/table/abc123   → "dine_in"
 *   /vi/checkout                         → null
 */
export function routeOrderMode(pathname: string): RouteOrderMode | null {
  const segments = pathname.split("/").filter(Boolean);

  // The marker is at segment 0 (`/takeaway/…`) or segment 1 when the URL
  // carries a locale prefix (`/vi/takeaway/…`). Scanning both means this never
  // has to be kept in sync with `routing.locales` — and a future locale can't
  // silently reintroduce the bug by failing a hardcoded membership test.
  for (const segment of segments.slice(0, 2)) {
    if (segment === "takeaway") return "takeaway";
    if (segment === "dine-in") return "dine_in";
  }

  return null;
}

/**
 * Which mode should `CartProvider` hydrate into?
 *
 * Extracted from the provider so the decision itself is testable — the bug in
 * godx-tempo#1697 lived in a conditional expression buried inside an effect,
 * where no test could reach it.
 *
 * Precedence, highest first:
 *
 *  1. **The route.** It is the customer's stated intent. `/takeaway/[shop]`
 *     means takeaway even when the tab still holds a table from an earlier QR
 *     scan — that mismatch IS the bug: hydration used to answer `dine_in`,
 *     race past the takeaway page's own `setOrderType`, and file the
 *     customer's items into the table's cart.
 *  2. **The persisted mode**, for routes that serve both (`/checkout`,
 *     `/orders/[id]`, `/order-confirm/[id]`). Only meaningful with a table
 *     still in sessionStorage.
 *  3. **Takeaway**, the safe default.
 *
 * A dine-in answer always requires a live table. Without one the mode is
 * unusable — checkout would bounce to "Chưa xác định bàn" — and, worse, the
 * save effect would key the cart off `undefined` and overwrite the customer's
 * real takeaway cart with `[]`.
 */
export function resolveHydrationOrderMode(input: {
  /** From `routeOrderMode(window.location.pathname)`. */
  routeMode: RouteOrderMode | null;
  /** `betoya-order-type` in localStorage; anything unrecognised is null. */
  savedOrderType: string | null;
  /** Did `betoya-dine-in-table` survive in sessionStorage? */
  hasRestoredTable: boolean;
}): RouteOrderMode {
  const { routeMode, savedOrderType, hasRestoredTable } = input;

  if (routeMode === "takeaway") return "takeaway";
  if (routeMode === "dine_in" && hasRestoredTable) return "dine_in";
  if (routeMode === "dine_in") return "takeaway";

  return savedOrderType === "dine_in" && hasRestoredTable ? "dine_in" : "takeaway";
}
