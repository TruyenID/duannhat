/**
 * Cross-branch cart invariant (plan-002 audit — finding "cart-clear-on-switch").
 *
 * A takeaway cart belongs to exactly ONE branch. Its items are priced in that
 * branch's currency and validated against that branch's menu / tax / service
 * charge. If the active branch changes to a *different* branch while a takeaway
 * cart still holds items, those items become stale — carrying them into the new
 * branch produces a cross-branch / cross-currency cart (wrong money math at
 * checkout, mismatched currency formatting, wrong tax/service rates).
 *
 * Previously this invariant was enforced only inside `BrandSwitcherSheet`
 * (the confirmation dialog). Every other switch path bypassed it:
 *   - `/select-branch` `handlePick` → `switchBranch(slug)`
 *   - `/takeaway/[shop]` mount effect → `switchBranch(shop)`
 *   - `/stores/[slug]` mount effect → `switchBranch(branch.slug)`
 *   - deep links / back-forward navigation that resolve a different branch
 *
 * This predicate lets `CartProvider` enforce the invariant centrally, so no
 * navigation path can smuggle a cross-branch cart into checkout. Dine-in carts
 * are excluded — they are already isolated per table (per-`qr_token` storage
 * key + clear-on-table-switch), so a branch-slug change never mixes them.
 *
 * Pure and side-effect free so it can be unit tested without a DOM.
 */
export function shouldClearCartOnBranchChange(params: {
  orderType: string;
  previousBranchSlug: string | null;
  nextBranchSlug: string | null;
  itemCount: number;
}): boolean {
  const { orderType, previousBranchSlug, nextBranchSlug, itemCount } = params;

  // Only takeaway carts share a single, branch-agnostic storage key and can
  // therefore leak across branches. Dine-in is per-table isolated already.
  if (orderType !== "takeaway") return false;

  // Nothing to protect if the cart is empty.
  if (itemCount <= 0) return false;

  // Ignore the initial resolve (FALLBACK "" → first branch) and any transient
  // empty slug — we only clear on a real branch-to-branch change.
  if (!previousBranchSlug || !nextBranchSlug) return false;

  return previousBranchSlug !== nextBranchSlug;
}
