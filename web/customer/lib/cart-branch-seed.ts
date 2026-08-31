/**
 * Cold-load seed for the cross-branch cart guard (plan-002 audit — finding
 * "guard cold-load seed depends on cartMetadata.branch_slug").
 *
 * The cross-branch invariant in `CartProvider` clears a stale takeaway cart
 * when the active branch changes to a DIFFERENT branch (see
 * `shouldClearCartOnBranchChange`). On a live navigation the "previous" branch
 * is held in a ref. On a COLD load (hard reload / deep-link) that ref starts
 * `null`, so the guard needs to be seeded with the branch the *persisted*
 * takeaway cart belongs to — otherwise a reload followed by a deep-link into a
 * different branch smuggles a cross-branch / cross-currency cart into checkout.
 *
 * Previously the seed read `cartMetadata.branch_slug`. But that metadata is
 * only persisted when the active menu carries a cart deadline
 * (`cart_deadline_iso`) — menus without a timeout call `setCartMetadata(null)`,
 * so the metadata key is absent and the seed was `null`. The takeaway cart
 * items, however, persist independently (their own storage key) and survive the
 * reload regardless of any deadline. The guard therefore failed to fire for any
 * branch whose menu has no cart deadline.
 *
 * Fix: persist the takeaway cart's branch slug to a dedicated durable key that
 * lives and dies with the persisted cart (independent of cart metadata), and
 * seed from it first — falling back to the metadata slug for backward
 * compatibility with carts persisted before this key existed.
 *
 * Pure and side-effect free so it can be unit tested without a DOM.
 */
export function resolveSeededBranchSlug(params: {
  /** Durable branch slug written alongside the persisted takeaway cart. */
  persistedTakeawayBranchSlug: string | null;
  /** Legacy source: only present when the active menu had a cart deadline. */
  cartMetadataBranchSlug: string | null;
}): string | null {
  const { persistedTakeawayBranchSlug, cartMetadataBranchSlug } = params;
  return persistedTakeawayBranchSlug || cartMetadataBranchSlug || null;
}
