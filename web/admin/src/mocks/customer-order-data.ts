/**
 * Dev-only mock data for Customer & Order module.
 *
 * Used as fallback when the backend API returns an error (404/network) in
 * development mode. Remove this file and its references in the service
 * files once the backend ships the real endpoints.
 */

import type { Customer } from "@/services/customer-service";
import type { CustomerOrder, CustomerOrderItem } from "@/services/order-service";
import type { PaginatedResponse } from "@/lib/api";

// =========================================================================
//  Customers
// =========================================================================

const BRAND_ID = "brand-001";
const BRANCH_ID = "branch-001";
const ORG_ID = "org-001";

export const MOCK_CUSTOMERS: Customer[] = [
  {
    id: "cust-001",
    full_name: "Tanaka Yuki",
    first_name: "Yuki",
    last_name: "Tanaka",
    phone: "09012345678",
    email: "tanaka@example.com",
    address: "Tokyo, Shibuya-ku, 1-2-3",
    tax_code: null,
    note: "VIP regular",
    brand_id: BRAND_ID,
    branch_id: BRANCH_ID,
    organization_id: ORG_ID,
    created_at: "2026-03-01T09:00:00Z",
    updated_at: "2026-03-15T14:30:00Z",
    deleted_at: null,
  },
  {
    id: "cust-002",
    full_name: "Suzuki Hana",
    first_name: "Hana",
    last_name: "Suzuki",
    phone: "08098765432",
    email: "suzuki.hana@example.com",
    address: "Osaka, Namba 4-5-6",
    tax_code: "T1234567890",
    note: null,
    brand_id: BRAND_ID,
    branch_id: BRANCH_ID,
    organization_id: ORG_ID,
    created_at: "2026-03-05T11:00:00Z",
    updated_at: "2026-03-05T11:00:00Z",
    deleted_at: null,
  },
  {
    id: "cust-003",
    full_name: "Nguyễn Minh Tuấn",
    first_name: "Tuấn",
    last_name: "Nguyễn Minh",
    phone: "09011112222",
    email: null,
    address: null,
    tax_code: null,
    note: "Prefers takeaway",
    brand_id: BRAND_ID,
    branch_id: BRANCH_ID,
    organization_id: ORG_ID,
    created_at: "2026-03-10T08:30:00Z",
    updated_at: "2026-03-10T08:30:00Z",
    deleted_at: null,
  },
  {
    id: "cust-004",
    full_name: "Yamamoto Kenji",
    first_name: "Kenji",
    last_name: "Yamamoto",
    phone: "07033334444",
    email: "yamamoto.k@example.com",
    address: "Kyoto, Gion 7-8",
    tax_code: null,
    note: null,
    brand_id: BRAND_ID,
    branch_id: BRANCH_ID,
    organization_id: ORG_ID,
    created_at: "2026-03-20T16:00:00Z",
    updated_at: "2026-04-01T10:00:00Z",
    deleted_at: null,
  },
  {
    id: "cust-005",
    full_name: "Lê Thị Mai",
    first_name: "Mai",
    last_name: "Lê Thị",
    phone: "09055556666",
    email: "le.mai@example.com",
    address: "Tokyo, Shinjuku 9-10",
    tax_code: "T9876543210",
    note: "Corporate account — invoice monthly",
    brand_id: BRAND_ID,
    branch_id: BRANCH_ID,
    organization_id: ORG_ID,
    created_at: "2026-04-01T12:00:00Z",
    updated_at: "2026-04-10T09:00:00Z",
    deleted_at: null,
  },
];

// =========================================================================
//  Orders
// =========================================================================

const MOCK_ITEMS: CustomerOrderItem[] = [
  {
    id: "item-001",
    customer_order_id: "ord-001",
    product_sku_id: "sku-001",
    quantity: 2,
    unit_price: 1200,
    subtotal: 2400,
    status: "preparing",
    note: null,
    product_sku: { id: "sku-001", name: "Matcha Latte (M)", sku: "ML-M" },
  },
  {
    id: "item-002",
    customer_order_id: "ord-001",
    product_sku_id: "sku-002",
    quantity: 1,
    unit_price: 850,
    subtotal: 850,
    status: "pending",
    note: "Extra ice",
    product_sku: { id: "sku-002", name: "Iced Coffee (L)", sku: "IC-L" },
  },
  {
    id: "item-003",
    customer_order_id: "ord-001",
    product_sku_id: "sku-003",
    quantity: 3,
    unit_price: 500,
    subtotal: 1500,
    status: "served",
    note: null,
    product_sku: { id: "sku-003", name: "Croissant", sku: "CR-01" },
  },
];

export const MOCK_ORDERS: CustomerOrder[] = [
  {
    id: "ord-001",
    order_code: "ORD-2026-0001",
    currency: "JPY",
    order_type: "dine_in",
    status: "pending",
    subtotal: 4750,
    discount_amount: 0,
    total_amount: 4750,
    paid_amount: 0,
    payment_method: null,
    paid_at: null,
    stock_out_transaction_id: null,
    note: null,
    created_by_id: "user-001",
    customer_id: "cust-001",
    customer_takeaway_name: null,
    customer_takeaway_phone: null,
    customer_takeaway_email: null,
    table_id: "table-001",
    branch_id: BRANCH_ID,
    brand_id: BRAND_ID,
    organization_id: ORG_ID,
    customer: { id: "cust-001", first_name: "Yuki", last_name: "Tanaka", phone: "09012345678" },
    items: MOCK_ITEMS,
    created_at: "2026-04-12T10:30:00Z",
    updated_at: "2026-04-12T10:30:00Z",
    deleted_at: null,
  },
  {
    id: "ord-002",
    order_code: "ORD-2026-0002",
    currency: "JPY",
    order_type: "takeaway",
    status: "open",
    subtotal: 2400,
    discount_amount: 0,
    total_amount: 2400,
    paid_amount: 1000,
    payment_method: null,
    paid_at: null,
    stock_out_transaction_id: null,
    note: "Rush order",
    created_by_id: "user-001",
    customer_id: "cust-003",
    customer_takeaway_name: null,
    customer_takeaway_phone: null,
    customer_takeaway_email: null,
    table_id: null,
    branch_id: BRANCH_ID,
    brand_id: BRAND_ID,
    organization_id: ORG_ID,
    customer: {
      id: "cust-003",
      first_name: "Tuấn",
      last_name: "Nguyễn Minh",
      phone: "09011112222",
    },
    items: [MOCK_ITEMS[0]],
    created_at: "2026-04-12T11:00:00Z",
    updated_at: "2026-04-12T11:15:00Z",
    deleted_at: null,
  },
  {
    id: "ord-003",
    order_code: "ORD-2026-0003",
    currency: "JPY",
    order_type: "dine_in",
    status: "closed",
    subtotal: 3600,
    discount_amount: 200,
    total_amount: 3400,
    paid_amount: 3400,
    payment_method: "cash",
    paid_at: "2026-04-12T09:45:00Z",
    stock_out_transaction_id: "stx-001",
    note: null,
    created_by_id: "user-001",
    customer_id: "cust-002",
    customer_takeaway_name: null,
    customer_takeaway_phone: null,
    customer_takeaway_email: null,
    table_id: "table-003",
    branch_id: BRANCH_ID,
    brand_id: BRAND_ID,
    organization_id: ORG_ID,
    customer: { id: "cust-002", first_name: "Hana", last_name: "Suzuki", phone: "08098765432" },
    items: [MOCK_ITEMS[1], MOCK_ITEMS[2]],
    created_at: "2026-04-12T09:00:00Z",
    updated_at: "2026-04-12T09:45:00Z",
    deleted_at: null,
  },
  {
    id: "ord-004",
    order_code: "ORD-2026-0004",
    currency: "JPY",
    order_type: "takeaway",
    status: "voided",
    subtotal: 1200,
    discount_amount: 0,
    total_amount: 1200,
    paid_amount: 0,
    payment_method: null,
    paid_at: null,
    stock_out_transaction_id: null,
    note: "Customer changed mind",
    created_by_id: "user-001",
    customer_id: null,
    customer_takeaway_name: "Khách vãng lai",
    customer_takeaway_phone: "09000000001",
    customer_takeaway_email: "khach@example.com",
    table_id: null,
    branch_id: BRANCH_ID,
    brand_id: BRAND_ID,
    organization_id: ORG_ID,
    customer: null,
    items: [MOCK_ITEMS[0]],
    created_at: "2026-04-11T14:00:00Z",
    updated_at: "2026-04-11T14:05:00Z",
    deleted_at: null,
  },
  {
    id: "ord-005",
    order_code: "ORD-2026-0005",
    currency: "JPY",
    order_type: "dine_in",
    status: "closed",
    subtotal: 8500,
    discount_amount: 500,
    total_amount: 8000,
    paid_amount: 8000,
    payment_method: "card",
    paid_at: "2026-04-11T20:30:00Z",
    stock_out_transaction_id: "stx-002",
    note: "Birthday dinner",
    created_by_id: "user-001",
    customer_id: "cust-005",
    customer_takeaway_name: null,
    customer_takeaway_phone: null,
    customer_takeaway_email: null,
    table_id: "table-005",
    branch_id: BRANCH_ID,
    brand_id: BRAND_ID,
    organization_id: ORG_ID,
    customer: { id: "cust-005", first_name: "Mai", last_name: "Lê Thị", phone: "09055556666" },
    items: MOCK_ITEMS,
    created_at: "2026-04-11T19:00:00Z",
    updated_at: "2026-04-11T20:30:00Z",
    deleted_at: null,
  },
];

// =========================================================================
//  Paginated response builders
// =========================================================================

function paginate<T>(items: T[], page = 1, perPage = 25): PaginatedResponse<T> {
  const total = items.length;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const from = (page - 1) * perPage;
  const to = Math.min(from + perPage, total);
  const sliced = items.slice(from, to);

  return {
    data: sliced,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: page,
      from: sliced.length > 0 ? from + 1 : null,
      last_page: lastPage,
      per_page: perPage,
      to: sliced.length > 0 ? to : null,
      total,
    },
  };
}

export function mockCustomerList(page?: number): PaginatedResponse<Customer> {
  return paginate(MOCK_CUSTOMERS, page);
}

export function mockCustomerDetail(id: string): { data: Customer } | null {
  const c = MOCK_CUSTOMERS.find((c) => c.id === id);
  return c ? { data: c } : null;
}

export function mockCustomerWithOrders(id: string) {
  const c = MOCK_CUSTOMERS.find((c) => c.id === id);
  if (!c) return null;
  const orders = MOCK_ORDERS.filter((o) => o.customer_id === c.id);
  return { data: { ...c, orders } };
}

export function mockOrderList(page?: number): PaginatedResponse<CustomerOrder> {
  return paginate(MOCK_ORDERS, page);
}

export function mockOrderDetail(id: string): { data: CustomerOrder } | null {
  const o = MOCK_ORDERS.find((o) => o.id === id);
  return o ? { data: o } : null;
}

export function mockHqOrderList(page?: number) {
  return {
    ...paginate(MOCK_ORDERS, page),
    aggregate: {
      total_revenue: MOCK_ORDERS.filter((o) => o.status === "closed").reduce(
        (sum, o) => sum + o.total_amount,
        0
      ),
      order_count: MOCK_ORDERS.length,
      avg_order_value: Math.round(
        MOCK_ORDERS.reduce((sum, o) => sum + o.total_amount, 0) / MOCK_ORDERS.length
      ),
      // #1961 — the real endpoint reports which currencies the two figures
      // above were summed across. The mock must carry it too: `withMock` infers
      // the hook's return type from BOTH branches, so a field missing here is
      // missing from the type the page sees, however correct the service
      // interface is.
      currencies: [...new Set(MOCK_ORDERS.map((o) => o.currency).filter(Boolean))].sort(),
    },
  };
}
