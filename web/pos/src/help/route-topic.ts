/**
 * Which guide the POS header's `?` opens, derived from the route.
 *
 * `PosHeader` is rendered by six screens, one of which is `src/app/pos/page.tsx`
 * — a file under a hard line budget (`page-size-budget.arch.test.ts`) that is
 * currently sitting exactly ON the budget. Threading a `helpTopic` prop through
 * it would cost a line it does not have, so the header resolves the topic from
 * the path instead and every screen gets its guide for free. Screens that CAN
 * spare the line still pass the topic explicitly; this is the default.
 *
 * `useLocation().pathname` already has the router basename stripped, so these
 * patterns hold for both the Amplify build (base `/`) and the workstation build
 * (base `/pos/`).
 */

import type { HelpTopicId } from "./types";

export function helpTopicForPath(pathname: string): HelpTopicId {
  // Strip a trailing slash so "/shop/x/settings/" matches "/settings".
  const path = pathname.length > 1 ? pathname.replace(/\/+$/, "") : pathname;

  if (path === "/pairing" || path.endsWith("/pairing")) return "pairing";
  if (path.endsWith("/shift/open")) return "shift-open";
  if (path.endsWith("/shift/close")) return "shift-close";
  if (path.endsWith("/settings")) return "settings";
  // plan-056 — "Tồn món" is its own page, reached from the header menu.
  if (path.endsWith("/menu-availability")) return "menu-availability";
  if (path.endsWith("/reports/revenue")) return "revenue";
  if (path.endsWith("/reports/history")) return "order-history";
  // `/shop/:slug` and anything unrecognised: the sales screen is the only place
  // the header appears without a more specific route, and it is also the right
  // answer for an unknown path — that is where an unknown path lands.
  return "pos-main";
}
