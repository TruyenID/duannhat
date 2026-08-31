/**
 * Centralized query key factory.
 *
 * Convention: [entity, scope, variant, ...identifiers, locale]
 * - entity: plural noun (products, categories, stock-levels)
 * - scope: brandSlug or shopSlug
 * - variant: "list" | "detail" | "dropdown" | ...
 * - identifiers: id, filters, etc.
 * - locale: placed LAST so invalidating by entity/variant/id still matches
 *   every cached locale (prefix match).
 *
 * Usage:
 *   queryKey: productKeys.list(brandSlug, locale, { page: 1 })
 *   invalidate one product across locales:
 *     queryClient.invalidateQueries({ queryKey: ["products", brandSlug, "detail", id] })
 *   invalidate everything for a brand:
 *     queryClient.invalidateQueries({ queryKey: productKeys.all(brandSlug) })
 */

export const productKeys = {
  all: (brandSlug: string) => ["products", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["products", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, id: string) =>
    ["products", brandSlug, "detail", id, locale] as const,
  detailAll: (brandSlug: string, id: string) => ["products", brandSlug, "detail", id] as const,
  dropdown: (brandSlug: string, locale: string) =>
    ["products", brandSlug, "dropdown", locale] as const,
  lookup: (brandSlug: string, locale: string) => ["products", brandSlug, "lookup", locale] as const,
};

export const productOptionKeys = {
  all: (brandSlug: string) => ["product-options", brandSlug] as const,
  listForProduct: (brandSlug: string, locale: string, productId: string) =>
    ["product-options", brandSlug, "for-product", productId, locale] as const,
  detail: (brandSlug: string, locale: string, optionId: string) =>
    ["product-options", brandSlug, "detail", optionId, locale] as const,
};

export const productOptionValueKeys = {
  all: (brandSlug: string) => ["product-option-values", brandSlug] as const,
  listForOption: (brandSlug: string, locale: string, optionId: string) =>
    ["product-option-values", brandSlug, "for-option", optionId, locale] as const,
  detail: (brandSlug: string, locale: string, valueId: string) =>
    ["product-option-values", brandSlug, "detail", valueId, locale] as const,
};

export const productSkuKeys = {
  all: (brandSlug: string) => ["product-skus", brandSlug] as const,
  listForProduct: (brandSlug: string, locale: string, productId: string, filters?: object) =>
    ["product-skus", brandSlug, "for-product", productId, filters, locale] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["product-skus", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, skuId: string) =>
    ["product-skus", brandSlug, "detail", skuId, locale] as const,
  usage: (brandSlug: string, locale: string, skuId: string) =>
    ["product-skus", brandSlug, "usage", skuId, locale] as const,
};

export const categoryKeys = {
  all: (brandSlug: string) => ["categories", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["categories", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, id: string) =>
    ["categories", brandSlug, "detail", id, locale] as const,
  dropdown: (brandSlug: string, locale: string) =>
    ["categories", brandSlug, "dropdown", locale] as const,
};

export const productTypeKeys = {
  all: (brandSlug: string) => ["product-types", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["product-types", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, id: string) =>
    ["product-types", brandSlug, "detail", id, locale] as const,
  dropdown: (brandSlug: string, locale: string) =>
    ["product-types", brandSlug, "dropdown", locale] as const,
  lookup: (brandSlug: string, locale: string) =>
    ["product-types", brandSlug, "lookup", locale] as const,
};

// plan-043 — Tax Types (税区分), HQ brand scope
export const taxTypeKeys = {
  all: (brandSlug: string) => ["tax-types", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["tax-types", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, id: string) =>
    ["tax-types", brandSlug, "detail", id, locale] as const,
  lookup: (brandSlug: string, locale: string) =>
    ["tax-types", brandSlug, "lookup", locale] as const,
};

export const materialKeys = {
  all: (brandSlug: string) => ["materials", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["materials", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, id: string) =>
    ["materials", brandSlug, "detail", id, locale] as const,
  dropdown: (brandSlug: string, locale: string) =>
    ["materials", brandSlug, "dropdown", locale] as const,
};

export const allergenKeys = {
  all: (brandSlug: string) => ["allergens", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["allergens", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, id: string) =>
    ["allergens", brandSlug, "detail", id, locale] as const,
};

// plan-051 (#1149) — brand-scoped void-reason master. Locale in the key
// because the backend localizes `label` by Accept-Language.
export const voidReasonKeys = {
  all: (brandSlug: string) => ["void-reasons", brandSlug] as const,
  list: (brandSlug: string, locale: string) =>
    ["void-reasons", brandSlug, "list", locale] as const,
};

export const faqKeys = {
  all: (brandSlug: string) => ["faqs", brandSlug] as const,
  list: (brandSlug: string, locale: string) => ["faqs", brandSlug, "list", locale] as const,
};

export const shopFaqKeys = {
  all: (shopSlug: string) => ["shop-faqs", shopSlug] as const,
  list: (shopSlug: string, locale: string) => ["shop-faqs", shopSlug, "list", locale] as const,
};

// #1514 — catalog đổi điểm. Locale nằm trong khoá vì backend trả `name` /
// `description` theo Accept-Language.
export const pointRewardKeys = {
  all: (brandSlug: string) => ["point-rewards", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["point-rewards", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, id: string) =>
    ["point-rewards", brandSlug, "detail", id, locale] as const,
  // Nhánh riêng cho màn cửa hàng: cùng một phần thưởng nhưng câu trả lời khác
  // (`is_available_at_branch`), nên dùng chung khoá với HQ là hai màn hình
  // ghi đè cache của nhau.
  shopAll: (shopSlug: string) => ["point-rewards-shop", shopSlug] as const,
  shopList: (shopSlug: string, locale: string, filters?: object) =>
    ["point-rewards-shop", shopSlug, "list", filters, locale] as const,
};

// #1700 — hai đường đọc về ĐIỂM, tách khỏi `pointRewardKeys` (catalog). Sửa
// một phần thưởng không được làm nhật ký đổi thưởng phải tải lại, và ngược
// lại: chúng đổi theo hai nhịp hoàn toàn khác nhau.
//
// #1718 — khoá mang theo PHẠM VI (`hq:<brand>` / `shop:<slug>`), không phải chỉ
// brandSlug. Cùng một khách mở từ HQ và từ cửa hàng đi qua hai endpoint khác
// nhau; dùng chung khoá thì hai màn ghi đè cache của nhau và cái mở sau hiển
// thị dữ liệu lấy bằng quyền của cái mở trước.
export const customerPointKeys = {
  all: (scope: string) => ["customer-points", scope] as const,
  // Locale nằm trong khoá vì `reward_name` trả theo Accept-Language.
  forCustomer: (scope: string, locale: string, customerId: string, page: number) =>
    ["customer-points", scope, "customer", customerId, page, locale] as const,
  redemptions: (scope: string, locale: string, filters?: object) =>
    ["customer-points", scope, "redemptions", filters, locale] as const,
};

export const recipeKeys = {
  all: (brandSlug: string) => ["recipes", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["recipes", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, id: string) =>
    ["recipes", brandSlug, "detail", id, locale] as const,
};

export const menuKeys = {
  all: (brandSlug: string) => ["menus", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["menus", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, id: string) =>
    ["menus", brandSlug, "detail", id, locale] as const,
  current: (brandSlug: string, locale: string, branchId: string) =>
    ["menus", brandSlug, "current", branchId, locale] as const,
  checkSync: (brandSlug: string, id: string) => ["menus", brandSlug, "check-sync", id] as const,
};

export const menuScheduleKeys = {
  all: (brandSlug: string, menuId: string) => ["hq-menu-schedules", brandSlug, menuId] as const,
  list: (brandSlug: string, menuId: string) =>
    ["hq-menu-schedules", brandSlug, menuId, "list"] as const,
};

export const shopMenuScheduleKeys = {
  all: (shopSlug: string, menuId: string) => ["shop-menu-schedules", shopSlug, menuId] as const,
  list: (shopSlug: string, menuId: string) =>
    ["shop-menu-schedules", shopSlug, menuId, "list"] as const,
};

export const menuSectionKeys = {
  all: (brandSlug: string) => ["menu-sections", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["menu-sections", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, id: string) =>
    ["menu-sections", brandSlug, "detail", id, locale] as const,
};

export const floatingSectionKeys = {
  all: (brandSlug: string) => ["floating-sections", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["floating-sections", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, id: string) =>
    ["floating-sections", brandSlug, "detail", id, locale] as const,
};

export const floatingSectionScheduleKeys = {
  all: (brandSlug: string, sectionId: string) =>
    ["hq-floating-section-schedules", brandSlug, sectionId] as const,
  list: (brandSlug: string, sectionId: string) =>
    ["hq-floating-section-schedules", brandSlug, sectionId, "list"] as const,
};

export const shopFloatingSectionKeys = {
  all: (shopSlug: string) => ["shop-floating-sections", shopSlug] as const,
  list: (shopSlug: string, locale: string, filters?: object) =>
    ["shop-floating-sections", shopSlug, "list", filters, locale] as const,
  detail: (shopSlug: string, locale: string, id: string) =>
    ["shop-floating-sections", shopSlug, "detail", id, locale] as const,
};

export const shopFloatingSectionScheduleKeys = {
  all: (shopSlug: string, sectionId: string) =>
    ["shop-floating-section-schedules", shopSlug, sectionId] as const,
  list: (shopSlug: string, sectionId: string) =>
    ["shop-floating-section-schedules", shopSlug, sectionId, "list"] as const,
};

export const masterMenuKeys = {
  all: (brandSlug: string) => ["master-menus", brandSlug] as const,
  list: (brandSlug: string, locale: string) => ["master-menus", brandSlug, "list", locale] as const,
  lookup: (brandSlug: string, locale: string) =>
    ["master-menus", brandSlug, "lookup", locale] as const,
};

export const shopMenuKeys = {
  all: (shopSlug: string) => ["shop-menus", shopSlug] as const,
  list: (shopSlug: string, locale: string, filters?: object) =>
    ["shop-menus", shopSlug, "list", filters, locale] as const,
  detail: (shopSlug: string, locale: string, menuId: string, options?: object) =>
    ["shop-menus", shopSlug, "detail", menuId, locale, options] as const,
  detailAll: (shopSlug: string, menuId: string) =>
    ["shop-menus", shopSlug, "detail", menuId] as const,
  // Backend exposes /menus/{menu}/products as a paginated, searchable list.
  // Kept separate from `detail` because the detail endpoint eager-loads the
  // full hierarchy in one shot whereas this is for paginated browsing.
  products: (shopSlug: string, locale: string, menuId: string, filters?: object) =>
    ["shop-menus", shopSlug, "products", menuId, filters, locale] as const,
};

export const stockLevelKeys = {
  all: (shopSlug: string) => ["stock-levels", shopSlug] as const,
  list: (shopSlug: string, locale: string, filters?: object) =>
    ["stock-levels", shopSlug, "list", filters, locale] as const,
};

export const stockTransactionKeys = {
  all: (shopSlug: string) => ["stock-transactions", shopSlug] as const,
  list: (shopSlug: string, locale: string, filters?: object) =>
    ["stock-transactions", shopSlug, "list", filters, locale] as const,
  detail: (shopSlug: string, locale: string, id: string) =>
    ["stock-transactions", shopSlug, "detail", id, locale] as const,
};

export const stockTransferKeys = {
  all: (shopSlug: string) => ["stock-transfers", shopSlug] as const,
  list: (shopSlug: string, locale: string, filters?: object) =>
    ["stock-transfers", shopSlug, "list", filters, locale] as const,
  detail: (shopSlug: string, locale: string, id: string) =>
    ["stock-transfers", shopSlug, "detail", id, locale] as const,
};

export const stockCountKeys = {
  all: (shopSlug: string) => ["stock-counts", shopSlug] as const,
  list: (shopSlug: string, locale: string, filters?: object) =>
    ["stock-counts", shopSlug, "list", filters, locale] as const,
  detail: (shopSlug: string, locale: string, id: string) =>
    ["stock-counts", shopSlug, "detail", id, locale] as const,
};

export const stockAlertKeys = {
  all: (shopSlug: string) => ["stock-alerts", shopSlug] as const,
  list: (shopSlug: string, locale: string, filters?: object) =>
    ["stock-alerts", shopSlug, "list", filters, locale] as const,
  summary: (shopSlug: string, locale: string) =>
    ["stock-alerts", shopSlug, "summary", locale] as const,
};

export const disposalKeys = {
  all: (shopSlug: string) => ["disposals", shopSlug] as const,
  list: (shopSlug: string, locale: string, filters?: object) =>
    ["disposals", shopSlug, "list", filters, locale] as const,
  detail: (shopSlug: string, locale: string, id: string) =>
    ["disposals", shopSlug, "detail", id, locale] as const,
};

export const materialBatchKeys = {
  all: (shopSlug: string) => ["material-batches", shopSlug] as const,
  list: (shopSlug: string, locale: string, filters?: object) =>
    ["material-batches", shopSlug, "list", filters, locale] as const,
  detail: (shopSlug: string, locale: string, id: string) =>
    ["material-batches", shopSlug, "detail", id, locale] as const,
};

export const productionOrderKeys = {
  all: (shopSlug: string) => ["production-orders", shopSlug] as const,
  list: (shopSlug: string, locale: string, filters?: object) =>
    ["production-orders", shopSlug, "list", filters, locale] as const,
  detail: (shopSlug: string, locale: string, id: string) =>
    ["production-orders", shopSlug, "detail", id, locale] as const,
};

export const warehouseKeys = {
  all: (shopSlug: string) => ["warehouses", shopSlug] as const,
  list: (shopSlug: string, locale: string, filters?: object) =>
    ["warehouses", shopSlug, "list", filters, locale] as const,
  detail: (shopSlug: string, locale: string, id: string) =>
    ["warehouses", shopSlug, "detail", id, locale] as const,
};

export const zoneKeys = {
  all: (shopSlug: string) => ["zones", shopSlug] as const,
  list: (shopSlug: string, locale: string, filters?: object) =>
    ["zones", shopSlug, "list", filters, locale] as const,
  detail: (shopSlug: string, locale: string, id: string) =>
    ["zones", shopSlug, "detail", id, locale] as const,
  lookup: (shopSlug: string, locale: string) => ["zones", shopSlug, "lookup", locale] as const,
};

export const shopKeys = {
  all: (brandSlug: string) => ["shops", brandSlug] as const,
  list: (brandSlug: string, filters?: object) => ["shops", brandSlug, "list", filters] as const,
  detail: (shopSlug: string) => ["shops", "detail", shopSlug] as const,
};

export const tableKeys = {
  all: (shopSlug: string) => ["tables", shopSlug] as const,
  list: (shopSlug: string, locale: string, filters?: object) =>
    ["tables", shopSlug, "list", filters, locale] as const,
  detail: (shopSlug: string, locale: string, id: string) =>
    ["tables", shopSlug, "detail", id, locale] as const,
  statusHistory: (shopSlug: string, id: string) =>
    ["tables", shopSlug, "status-history", id] as const,
};

export const deviceHqKeys = {
  all: (brandSlug: string) => ["devices-hq", brandSlug] as const,
  list: (brandSlug: string, filters?: object) =>
    ["devices-hq", brandSlug, "list", filters] as const,
  detail: (brandSlug: string, id: string) => ["devices-hq", brandSlug, "detail", id] as const,
};

export const deviceShopKeys = {
  all: (shopSlug: string) => ["devices-shop", shopSlug] as const,
  list: (shopSlug: string, filters?: object) =>
    ["devices-shop", shopSlug, "list", filters] as const,
  detail: (shopSlug: string, id: string) => ["devices-shop", shopSlug, "detail", id] as const,
};

export const peripheralDeviceShopKeys = {
  all: (shopSlug: string) => ["peripheral-devices-shop", shopSlug] as const,
  list: (shopSlug: string, filters?: object) =>
    ["peripheral-devices-shop", shopSlug, "list", filters] as const,
  detail: (shopSlug: string, id: string) =>
    ["peripheral-devices-shop", shopSlug, "detail", id] as const,
};

// =========================================================================
//  Printers (physical ESC/POS hardware, shop scope)
// =========================================================================

export const printerShopKeys = {
  all: (shopSlug: string) => ["printers-shop", shopSlug] as const,
  list: (shopSlug: string, filters?: object) =>
    ["printers-shop", shopSlug, "list", filters] as const,
  detail: (shopSlug: string, id: string) => ["printers-shop", shopSlug, "detail", id] as const,
};

// =========================================================================
//  Customer & Order (plan-001-customer-order-ui)
// =========================================================================

export const customerShopKeys = {
  all: (shopSlug: string) => ["customers", shopSlug] as const,
  list: (shopSlug: string, filters?: object) => ["customers", shopSlug, "list", filters] as const,
  detail: (shopSlug: string, id: string) => ["customers", shopSlug, "detail", id] as const,
  /**
   * Phone-prefix lookup for the POS autocomplete. Separate key so the
   * debounced lookup cache does not collide with the paginated list.
   */
  lookup: (shopSlug: string, phone: string) => ["customers", shopSlug, "lookup", phone] as const,
};

export const customerHqKeys = {
  all: (brandSlug: string) => ["customers-hq", brandSlug] as const,
  list: (brandSlug: string, filters?: object) =>
    ["customers-hq", brandSlug, "list", filters] as const,
  detail: (brandSlug: string, id: string) => ["customers-hq", brandSlug, "detail", id] as const,
};

export const orderShopKeys = {
  all: (shopSlug: string) => ["orders", shopSlug] as const,
  list: (shopSlug: string, filters?: object) => ["orders", shopSlug, "list", filters] as const,
  detail: (shopSlug: string, id: string) => ["orders", shopSlug, "detail", id] as const,
};

export const orderHqKeys = {
  all: (brandSlug: string) => ["orders-hq", brandSlug] as const,
  list: (brandSlug: string, filters?: object) => ["orders-hq", brandSlug, "list", filters] as const,
  detail: (brandSlug: string, id: string) => ["orders-hq", brandSlug, "detail", id] as const,
};

// =========================================================================
//  IAM (plan-007-scoped-rbac-iam-ui)
// =========================================================================

export const paymentGatewayKeys = {
  all: (brandSlug: string) => ["payment-gateways", brandSlug] as const,
  readiness: (brandSlug: string, locale: string) =>
    ["payment-gateways", brandSlug, "readiness", locale] as const,
  connections: (brandSlug: string, locale: string, filters?: object) =>
    ["payment-gateways", brandSlug, "connections", filters, locale] as const,
  connection: (brandSlug: string, locale: string, connectionId: string) =>
    ["payment-gateways", brandSlug, "connection", connectionId, locale] as const,
  disconnectImpact: (brandSlug: string, locale: string, connectionId: string) =>
    ["payment-gateways", brandSlug, "disconnect-impact", connectionId, locale] as const,
  optionPolicies: (brandSlug: string, locale: string) =>
    ["payment-gateways", brandSlug, "option-policies", locale] as const,
  coverage: (brandSlug: string, locale: string, filters?: object) =>
    ["payment-gateways", brandSlug, "coverage", filters, locale] as const,
};


/**
 * #1157 — HQ gateway settlement reads. No locale segment: the payloads are ids,
 * enum codes, ISO timestamps and integers, none of which the backend localizes,
 * so keying by locale would just fetch the same bytes three times.
 */
export const settlementKeys = {
  all: (brandSlug: string) => ["settlements", brandSlug] as const,
  list: (brandSlug: string, filters?: object) =>
    ["settlements", brandSlug, "list", filters] as const,
  batches: (brandSlug: string, filters?: object) =>
    ["settlements", brandSlug, "batches", filters] as const,
  payouts: (brandSlug: string, filters?: object) =>
    ["settlements", brandSlug, "payouts", filters] as const,
  aging: (brandSlug: string, filters?: object) =>
    ["settlements", brandSlug, "aging", filters] as const,
};

/** #2880 — tra cứu giao dịch toàn kênh (T3 của #2876). */
export const transactionKeys = {
  all: (brandSlug: string) => ["transactions", brandSlug] as const,
  list: (brandSlug: string, filters?: object) =>
    ["transactions", brandSlug, "list", filters] as const,
};

export const shopPaymentMethodKeys = {
  all: (shopSlug: string) => ["shop-payment-methods", shopSlug] as const,
  list: (shopSlug: string) => ["shop-payment-methods", shopSlug, "list"] as const,
};

export const shopPaymentSettingsKeys = {
  all: (shopSlug: string) => ["shop-payment-settings", shopSlug] as const,
  configuration: (shopSlug: string) =>
    ["shop-payment-settings", shopSlug, "configuration"] as const,
  devices: (shopSlug: string) => ["shop-payment-settings", shopSlug, "devices"] as const,
  devicePolicy: (shopSlug: string, deviceId: string) =>
    ["shop-payment-settings", shopSlug, "device-policy", deviceId] as const,
  // plan-054 D9 — shop-level PayPay (customer-web QR) switch. Nested under the
  // same namespace so a change through either control invalidates the other.
  paypay: (shopSlug: string) => ["shop-payment-settings", shopSlug, "paypay"] as const,
};

export const iamKeys = {
  all: (brandSlug: string) => ["iam", brandSlug] as const,
  members: (brandSlug: string) => ["iam", brandSlug, "members"] as const,
  member: (brandSlug: string, userId: string) => ["iam", brandSlug, "member", userId] as const,
  memberPermissions: (brandSlug: string, userId: string) =>
    ["iam", brandSlug, "member", userId, "permissions"] as const,
  memberAudit: (brandSlug: string, userId: string) =>
    ["iam", brandSlug, "member", userId, "audit"] as const,
  roles: (brandSlug: string) => ["iam", brandSlug, "roles"] as const,
  permissions: (brandSlug: string) => ["iam", brandSlug, "permissions"] as const,
  branches: (brandSlug: string) => ["iam", brandSlug, "branches"] as const,
};

export const toppingGroupKeys = {
  all: (brandSlug: string) => ["topping-groups", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["topping-groups", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, id: string) =>
    ["topping-groups", brandSlug, "detail", id, locale] as const,
  lookup: (brandSlug: string, locale: string) =>
    ["topping-groups", brandSlug, "lookup", locale] as const,
  items: (brandSlug: string, locale: string, groupId: string) =>
    ["topping-groups", brandSlug, "items", groupId, locale] as const,
  itemSkus: (brandSlug: string, groupId: string, itemId: string) =>
    ["topping-groups", brandSlug, "item-skus", groupId, itemId] as const,
  forProduct: (brandSlug: string, locale: string, productId: string) =>
    ["topping-groups", brandSlug, "for-product", productId, locale] as const,
  overrides: (brandSlug: string, productId: string, groupId: string) =>
    ["topping-groups", brandSlug, "overrides", productId, groupId] as const,
};

// HQ Dashboard — kpis/revenueChart/shopPerformance are locale-independent (numbers only).
// categorySales and topProducts return translated names → locale in key so TanStack
// Query refetches automatically on locale switch without a page reload.
export const dashboardKeys = {
  all: (brandSlug: string) => ["dashboard", brandSlug] as const,
  kpis: (brandSlug: string, period: string) => ["dashboard", brandSlug, "kpis", period] as const,
  revenueChart: (brandSlug: string, dateFrom: string, dateTo: string, groupBy: string) =>
    ["dashboard", brandSlug, "revenue-chart", dateFrom, dateTo, groupBy] as const,
  categorySales: (brandSlug: string, period: string, locale: string) =>
    ["dashboard", brandSlug, "category-sales", period, locale] as const,
  shopPerformance: (brandSlug: string, period: string) =>
    ["dashboard", brandSlug, "shop-performance", period] as const,
  topProducts: (brandSlug: string, period: string, locale: string) =>
    ["dashboard", brandSlug, "top-products", period, locale] as const,
  recentOrders: (brandSlug: string) => ["dashboard", brandSlug, "recent-orders"] as const,
};

// Plan-018 — Substitution Rules (nested under a primary material, HQ scope)
export const substitutionRuleKeys = {
  all: (brandSlug: string) => ["substitution-rules", brandSlug] as const,
  list: (brandSlug: string, locale: string, materialId: string) =>
    ["substitution-rules", brandSlug, "list", materialId, locale] as const,
};

// Plan-017 — Material Lots (HQ + Shop scope; key is the slug)
//
// plan-040 M21: the `scope` segment MUST be discriminated with `hq:` / `shop:`
// via `materialLotScope` below. An HQ brand and a Shop can share the same slug,
// and without the discriminator their material-lot caches collide (a shop list
// could read an HQ brand's cached lots and vice versa).
export const materialLotScope = {
  hq: (slug: string) => `hq:${slug}` as const,
  shop: (slug: string) => `shop:${slug}` as const,
};

export const materialLotKeys = {
  all: (scope: string) => ["material-lots", scope] as const,
  list: (scope: string, locale: string, filters?: object) =>
    ["material-lots", scope, "list", filters, locale] as const,
  detail: (scope: string, locale: string, id: string) =>
    ["material-lots", scope, "detail", id, locale] as const,
  detailAll: (scope: string, id: string) => ["material-lots", scope, "detail", id] as const,
  expiring: (shopSlug: string, locale: string, days: number, warehouseId?: string) =>
    ["material-lots", shopSlug, "expiring", days, warehouseId, locale] as const,
};

// #3112 — Material Lot Reservations (shop scope only; see the service file for
// why the HQ surface is deliberately absent from admin-web's shop screens).
export const materialLotReservationKeys = {
  all: (shopSlug: string) => ["material-lot-reservations", shopSlug] as const,
  // `status` NẰM TRONG khoá, không phải tham số bên lề: nó lọc phía server, nên
  // hai lượt hỏi khác `status` là hai tập kết quả khác nhau. Bỏ nó ra khỏi khoá
  // thì React-Query coi chúng là một, và màn hình đọc lại cache của bộ lọc CŨ —
  // với danh sách hold thì đó là hiện ra một lô "đang giữ" mà thật ra đã nhả.
  byBatch: (shopSlug: string, materialBatchId: string, status?: string) =>
    ["material-lot-reservations", shopSlug, "batch", materialBatchId, status ?? "all"] as const,
};

// Plan-017 — Material Units (nested under a parent material)
export const materialUnitKeys = {
  all: (brandSlug: string, materialId: string) =>
    ["material-units", brandSlug, materialId] as const,
  list: (brandSlug: string, materialId: string, locale: string) =>
    ["material-units", brandSlug, materialId, "list", locale] as const,
};

// Plan-017 Tier 1.A — Recalls
export const recallKeys = {
  all: (brandSlug: string) => ["recalls", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["recalls", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, id: string) =>
    ["recalls", brandSlug, "detail", id, locale] as const,
  report: (brandSlug: string, id: string) => ["recalls", brandSlug, "report", id] as const,
};

// Plan-018 TC2.4 — Recall Drills
export const recallDrillKeys = {
  all: (brandSlug: string) => ["recall-drills", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["recall-drills", brandSlug, "list", filters, locale] as const,
};

// Plan-017 — Trace
export const traceKeys = {
  all: (brandSlug: string) => ["trace", brandSlug] as const,
  lot: (brandSlug: string, lotId: string, direction: string, maxDepth: number) =>
    ["trace", brandSlug, "lot", lotId, direction, maxDepth] as const,
  customerOrder: (brandSlug: string, orderId: string, maxDepth: number) =>
    ["trace", brandSlug, "customer-order", orderId, maxDepth] as const,
};

// =========================================================================
//  Promotion (plan-019 — Coupon + Menu Promotion / Happy Hour)
// =========================================================================

export const couponHqKeys = {
  all: (brandSlug: string) => ["coupons", brandSlug] as const,
  list: (brandSlug: string, filters?: object) => ["coupons", brandSlug, "list", filters] as const,
  detail: (brandSlug: string, id: string) => ["coupons", brandSlug, "detail", id] as const,
  redemptions: (brandSlug: string, id: string, page?: number) =>
    ["coupons", brandSlug, "redemptions", id, page] as const,
};

export const menuPromotionShopKeys = {
  all: (shopSlug: string) => ["menu-promotions", shopSlug] as const,
  list: (shopSlug: string, filters?: object) =>
    ["menu-promotions", shopSlug, "list", filters] as const,
  detail: (shopSlug: string, id: string) => ["menu-promotions", shopSlug, "detail", id] as const,
  recentItems: (shopSlug: string, id: string) =>
    ["menu-promotions", shopSlug, "recent-items", id] as const,
};

export const menuPromotionHqKeys = {
  all: (brandSlug: string) => ["menu-promotions-hq", brandSlug] as const,
  list: (brandSlug: string, filters?: object) =>
    ["menu-promotions-hq", brandSlug, "list", filters] as const,
};

// Shop Dashboard — all endpoints are daily/real-time, no period param.
// topItems returns translated names → locale in key.
export const shopDashboardKeys = {
  all: (shopSlug: string) => ["shop-dashboard", shopSlug] as const,
  kpis: (shopSlug: string) => ["shop-dashboard", shopSlug, "kpis"] as const,
  revenueTrend: (shopSlug: string) => ["shop-dashboard", shopSlug, "revenue-trend"] as const,
  tableStatus: (shopSlug: string) => ["shop-dashboard", shopSlug, "table-status"] as const,
  topItems: (shopSlug: string, locale: string) =>
    ["shop-dashboard", shopSlug, "top-items", locale] as const,
  productionQueue: (shopSlug: string) => ["shop-dashboard", shopSlug, "production-queue"] as const,
  recentOrders: (shopSlug: string) => ["shop-dashboard", shopSlug, "recent-orders"] as const,
  branchReviews: (shopSlug: string) => ["shop-dashboard", shopSlug, "branch-reviews"] as const,
};

// issue #890 — HQ zone/table templates (default tables), brand scope
export const zoneTemplateKeys = {
  all: (brandSlug: string) => ["zone-templates", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["zone-templates", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, id: string) =>
    ["zone-templates", brandSlug, "detail", id, locale] as const,
  lookup: (brandSlug: string, locale: string) =>
    ["zone-templates", brandSlug, "lookup", locale] as const,
};

export const tableTemplateKeys = {
  all: (brandSlug: string) => ["table-templates", brandSlug] as const,
  list: (brandSlug: string, locale: string, filters?: object) =>
    ["table-templates", brandSlug, "list", filters, locale] as const,
  detail: (brandSlug: string, locale: string, id: string) =>
    ["table-templates", brandSlug, "detail", id, locale] as const,
};

// issue #890 — shop-side "apply HQ defaults" preview
export const tableDefaultsKeys = {
  all: (shopSlug: string) => ["table-defaults", shopSlug] as const,
  preview: (shopSlug: string) => ["table-defaults", shopSlug, "preview"] as const,
};

// Plan-018 TD3.3 — Reports
export const reportKeys = {
  all: (brandSlug: string) => ["reports", brandSlug] as const,
  supplierYield: (brandSlug: string, filters?: object) =>
    ["reports", brandSlug, "supplier-yield", filters] as const,
};
