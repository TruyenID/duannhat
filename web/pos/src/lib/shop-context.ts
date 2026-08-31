/**
 * Current-shop module state for apiFetch.
 *
 * The /api/v1/pos/* namespace resolves shop context from the X-Shop-Slug
 * request header (see backend ResolvePosShop middleware). pos-web learns
 * the active slug from the URL (`/shop/:shopSlug`); ShopContextSync (a
 * tiny effect mounted under the Router) keeps this module-level value
 * in step with React state so apiFetch — which has no React context —
 * can read it synchronously when building outgoing requests.
 *
 * Module-level state is intentional: every POS terminal is operating on
 * exactly one shop at a time, and routing the slug through React props
 * down to every fetch call would be invasive without buying anything.
 */

let currentShopSlug = "";

export function setCurrentShopSlug(slug: string): void {
  currentShopSlug = slug;
}

export function getCurrentShopSlug(): string {
  return currentShopSlug;
}
