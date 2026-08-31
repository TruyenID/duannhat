/**
 * React-Query key factories — shape mirrors
 * godx-tempo-frontend/src/hooks/api/query-keys.ts so invalidation semantics
 * stay identical across both apps.
 *
 * Convention:
 *   - `all(slug)` is the root key — invalidating it busts every query in the
 *     domain.
 *   - `list(slug, filters)` and `detail(slug, id)` include filter/id so
 *     caches are scoped correctly.
 */

export const shopKeys = {
  detail: (shopSlug: string) => ["shop", shopSlug] as const,
};

export const shopMenuKeys = {
  all: (shopSlug: string) => ["shop-menus", shopSlug] as const,
  // `locale` is part of every menu key on purpose: the backend localizes
  // product names by the `Accept-Language` header (getLocale() in lib/api.ts),
  // so the SAME menu returns different names per language. Without locale in
  // the key, switching language served the cached (old-language) names until a
  // hard refresh. Keying by locale makes React Query refetch on the switch.
  list: (shopSlug: string, locale: string, filters?: object) =>
    ["shop-menus", shopSlug, "list", locale, filters] as const,
  detail: (shopSlug: string, menuId: string, locale: string) =>
    ["shop-menus", shopSlug, "detail", menuId, locale] as const,
  products: (shopSlug: string, menuId: string, locale: string, filters?: object) =>
    ["shop-menus", shopSlug, "products", menuId, locale, filters] as const,
  // #3163 — thanh pill. KHÔNG dùng chung khoá với `products`: hai đường có nhịp
  // làm mới khác nhau (danh sách section gần như đứng yên, còn giá và Happy
  // Hour của món đổi theo phút), nên gộp khoá sẽ kéo cả thanh pill đi refetch
  // mỗi 60 giây mà không có gì đổi.
  sections: (shopSlug: string, menuId: string, locale: string) =>
    ["shop-menus", shopSlug, "sections", menuId, locale] as const,
  byDay: (shopSlug: string, dayOfWeek: number, locale: string, filters?: object) =>
    ["shop-menus", shopSlug, "by-day", dayOfWeek, locale, filters] as const,
};

/**
 * plan-056 — the "Tồn món" management screen.
 *
 * A ROOT OF ITS OWN ("menu-availability"), deliberately not a branch of
 * `shopMenuKeys`. The two domains fetch the same rows and disagree about which
 * ones to include: this one carries turned-off dishes, the ordering screen must
 * never see them. Sharing a root would let one invalidation write the other's
 * cache — and a management response answering an ordering render puts a
 * sold-out dish in front of a customer.
 *
 * `src/hooks/api/query-keys.test.ts` asserts the two roots stay disjoint.
 */
export const menuAvailabilityKeys = {
  all: (shopSlug: string) => ["menu-availability", shopSlug] as const,
  menus: (shopSlug: string, locale: string) =>
    ["menu-availability", shopSlug, "menus", locale] as const,
  detail: (shopSlug: string, menuId: string, locale: string) =>
    ["menu-availability", shopSlug, "detail", menuId, locale] as const,
};

export const orderKeys = {
  all: (shopSlug: string) => ["orders", shopSlug] as const,
  // Prefix of every `list(slug, filters)` key — invalidating this busts the
  // open-orders list (chip summaries + reconcile) WITHOUT touching
  // `detail(slug, id)`. Item/order mutations already write the authoritative
  // full order into the detail cache via setQueryData, so refetching detail
  // here only reintroduces the out-of-order clobber race the setQueryData
  // design exists to avoid (#563 — visible on high-latency tunnels).
  lists: (shopSlug: string) => ["orders", shopSlug, "list"] as const,
  list: (shopSlug: string, filters?: object) =>
    ["orders", shopSlug, "list", filters] as const,
  detail: (shopSlug: string, orderId: string) =>
    ["orders", shopSlug, "detail", orderId] as const,
  splitBill: (shopSlug: string, orderId: string, splitCount: number) =>
    ["orders", shopSlug, "split-bill", orderId, splitCount] as const,
  // All-tables history feed (its own namespace, NOT under `list`, so the
  // per-mutation `orderKeys.lists` invalidation doesn't refetch every loaded
  // page of the infinite history query).
  history: (shopSlug: string, filters?: object) =>
    ["orders", shopSlug, "history", filters] as const,
};

export const orderPaymentKeys = {
  list: (shopSlug: string, orderId: string) =>
    ["order-payments", shopSlug, orderId] as const,
};

export const tableKeys = {
  all: (shopSlug: string) => ["tables", shopSlug] as const,
  list: (shopSlug: string, filters?: object) =>
    ["tables", shopSlug, "list", filters] as const,
};

export const paymentMethodKeys = {
  all: (shopSlug: string) => ["payment-methods", shopSlug] as const,
  list: (shopSlug: string, filters?: object) =>
    ["payment-methods", shopSlug, "list", filters] as const,
};

// `locale` is part of the list key for the same reason as shopMenuKeys: an
// option's `display_name` is localized server-side by Accept-Language (the
// workstation resolves it per request from its mirror, Cloud through
// SetLocale), so the same request returns different text per language. Without
// it the payment dialog kept rendering the previous language's method buttons
// after a locale switch — the key never changed, so nothing refetched.
export const effectivePaymentOptionKeys = {
  all: (shopSlug: string) => ["effective-payment-options", shopSlug] as const,
  list: (shopSlug: string, locale: string) =>
    ["effective-payment-options", shopSlug, "list", locale] as const,
};

export const customerKeys = {
  outstanding: (shopSlug: string, customerId: string) =>
    ["customer-outstanding", shopSlug, customerId] as const,
};

// Open on-account balances. NOT locale-keyed: every field is a name, a phone,
// an order code or a number — the server localizes nothing here.
//
// The root `debts` sits on the never-cache money list (offline-cache-policy):
// an outstanding balance read from a 40-minute-old cache is a figure a cashier
// would say out loud to a customer before taking their money.
export const debtKeys = {
  all: (shopSlug: string) => ["debts", shopSlug] as const,
  list: (shopSlug: string) => ["debts", shopSlug, "list"] as const,
  forCustomer: (shopSlug: string, customerId: string) =>
    ["debts", shopSlug, "customer", customerId] as const,
  partPaid: (shopSlug: string) => ["debts", shopSlug, "part-paid"] as const,
};

export const shopOrderSettingsKeys = {
  get: (shopSlug: string) => ["shop", shopSlug, "settings", "order"] as const,
};

// plan-051 — void-reason picker list. `locale` is part of the key because the
// backend localizes `label` by Accept-Language (same rationale as shopMenuKeys).
export const voidReasonKeys = {
  all: (shopSlug: string) => ["void-reasons", shopSlug] as const,
  list: (shopSlug: string, locale: string) =>
    ["void-reasons", shopSlug, "list", locale] as const,
};

// The `locale` is part of every key because the server localizes product / SKU
// names by Accept-Language: without it, switching the pos-web language would
// keep serving the previously-cached response in the old language (an open
// report never refetches on a language flip since the key wouldn't change).
export const revenueKeys = {
  all: (shopSlug: string) => ["revenue", shopSlug] as const,
  summary: (shopSlug: string, filters?: object, locale?: string) =>
    ["revenue", shopSlug, "summary", filters, locale] as const,
  byProduct: (shopSlug: string, filters?: object, locale?: string) =>
    ["revenue", shopSlug, "by-product", filters, locale] as const,
  voids: (shopSlug: string, filters?: object, locale?: string) =>
    ["revenue", shopSlug, "voids", filters, locale] as const,
  voidEvents: (shopSlug: string, filters?: object, locale?: string) =>
    ["revenue", shopSlug, "void-events", filters, locale] as const,
};

// #1320 — spotlight ("Khung giờ ưu đãi"). Locale-keyed for the same reason as
// shopMenuKeys: the workstation localizes section and product names by
// Accept-Language, so the same request returns different text per language.
export const floatingSectionKeys = {
  all: (shopSlug: string) => ["floating-sections", shopSlug] as const,
  open: (shopSlug: string, locale: string) =>
    ["floating-sections", shopSlug, "open", locale] as const,
};
