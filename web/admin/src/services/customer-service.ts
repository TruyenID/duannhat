/**
 * Customer Service — pure TypeScript, no React dependency.
 *
 * All API calls for the Customer domain, both shop-scoped (branch staff) and
 * hq-scoped (brand admins). URL convention:
 *
 *   Shop: /api/v1/shops/{shopSlug}/customers
 *   HQ:   /api/v1/hq/{brandSlug}/customers
 *
 * Types are defined inline here rather than imported from `@/types/models/`
 * because backend has not yet shipped the Omnify schema for Customer / the
 * generated TS base types do not exist. When backend ships the schema, a
 * follow-up plan replaces these inline definitions with generated imports.
 * See plan-001-customer-order-ui/DESIGN.md §"Key decisions" #1.
 *
 * The React-Query layer lives in src/hooks/api/use-customers.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import type { CustomerOrder } from "./order-service";

// =========================================================================
//  Types
// =========================================================================

export interface Customer {
  id: string;
  first_name: string;
  last_name: string | null;
  full_name: string;
  phone: string | null;
  email: string | null;
  address: string | null;
  tax_code: string | null;
  note: string | null;
  brand_id: string;
  /** Nullable ở backend — khách tự đăng ký online có thể chưa gắn chi nhánh. */
  branch_id: string | null;
  organization_id: string;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  /**
   * #1700 — số dư điểm. CHỈ có ở danh sách khách của HQ (backend gắn `withSum`
   * cho đúng màn đó); các màn khác không trả trường này, nên nó optional.
   * Đã là 0 chứ không null khi khách chưa có bút toán nào.
   */
  point_balance?: number;
  /**
   * #1712 — chi nhánh của khách, cho cột "Cửa hàng" ở danh sách khách HQ.
   * Backend chỉ eager-load ở đúng màn đó (`with_branch`), nên trường này
   * `undefined` ở các màn khác và `null` khi khách chưa gắn chi nhánh nào.
   * Chi nhánh đã xoá mềm vẫn trả về (backend dùng `withTrashed`).
   */
  branch?: CustomerBranchSummary | null;
}

/** Phần của Branch mà màn danh sách khách hàng cần — không phải cả BranchResource. */
export interface CustomerBranchSummary {
  id: string;
  name: string;
  slug: string;
}

/** Returns the display name for a customer: "First Last" or just "First". */
export function customerFullName(c: Pick<Customer, "first_name" | "last_name">): string {
  return [c.first_name, c.last_name].filter(Boolean).join(" ");
}

/**
 * Customer with its (optional) nested order history. Returned by the HQ
 * detail endpoint so the "cross-branch order history" table can render in a
 * single round trip.
 */
export interface CustomerWithOrders extends Customer {
  orders?: CustomerOrder[];
}

export interface CustomerFilters {
  page?: number;
  per_page?: number;
  search?: string;
  /** Exact-prefix phone lookup used by the POS autocomplete. */
  phone?: string;
  with_trashed?: boolean;
  /** HQ only — filter customers across branches. */
  branch_id?: string;
}

export interface CreateCustomerInput {
  first_name: string;
  last_name?: string | null;
  phone?: string | null;
  email?: string | null;
  address?: string | null;
  tax_code?: string | null;
  note?: string | null;
}

export type UpdateCustomerInput = Partial<CreateCustomerInput>;

// =========================================================================
//  URL / param helpers
// =========================================================================

function shopUrl(shopSlug: string, path: string = ""): string {
  return `/api/v1/shops/${shopSlug}/customers${path}`;
}

function hqUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/customers${path}`;
}

function toParams(filters: CustomerFilters): string {
  const params = new URLSearchParams();
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.search) params.set("search", filters.search);
  if (filters.phone) params.set("phone", filters.phone);
  if (filters.branch_id) params.set("branch_id", filters.branch_id);
  if (filters.with_trashed) params.set("with_trashed", "1");
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const customerService = {
  // --- Shop scope ---

  list: (shopSlug: string, filters: CustomerFilters = {}) =>
    apiFetch<PaginatedResponse<Customer>>(
      `${shopUrl(shopSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
    ),

  getById: (shopSlug: string, id: string) =>
    apiFetch<{ data: Customer }>(shopUrl(shopSlug, `/${id}`)),

  lookup: (shopSlug: string, phone: string) =>
    apiFetch<PaginatedResponse<Customer>>(
      `${shopUrl(shopSlug)}?${toParams({ phone, per_page: 5 })}`
    ),

  create: (shopSlug: string, data: CreateCustomerInput) =>
    apiFetch<{ data: Customer }>(shopUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (shopSlug: string, id: string, data: UpdateCustomerInput) =>
    apiFetch<{ data: Customer }>(shopUrl(shopSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (shopSlug: string, id: string) =>
    apiFetch<null>(shopUrl(shopSlug, `/${id}`), { method: "DELETE" }),

  restore: (shopSlug: string, id: string) =>
    apiFetch<{ data: Customer }>(shopUrl(shopSlug, `/${id}/restore`), {
      method: "POST",
    }),

  // --- HQ scope ---

  hqList: (brandSlug: string, filters: CustomerFilters = {}) =>
    apiFetch<PaginatedResponse<Customer>>(
      `${hqUrl(brandSlug)}?${toParams({ ...filters, per_page: filters.per_page ?? 25 })}`
    ),

  hqGetById: (brandSlug: string, id: string) =>
    apiFetch<{ data: CustomerWithOrders }>(hqUrl(brandSlug, `/${id}`)),
};
