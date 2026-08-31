/**
 * Product Option Service — pure TypeScript, no React dependency.
 *
 * URL convention:
 *   GET    /api/v1/hq/{brandSlug}/products/{productId}/options
 *   POST   /api/v1/hq/{brandSlug}/products/{productId}/options
 *   GET    /api/v1/hq/{brandSlug}/product-options/{optionId}
 *   PUT    /api/v1/hq/{brandSlug}/product-options/{optionId}
 *   DELETE /api/v1/hq/{brandSlug}/product-options/{optionId}
 *
 * Hooks live in src/hooks/api/use-product-options.ts.
 */

import { apiFetch } from "@/lib/api";
import type { ProductOption } from "./product-service";

export type { ProductOption };

export interface CreateProductOptionInput {
  key: string;
  name: string;
  position: 1 | 2 | 3;
  is_active?: boolean;
}

export interface UpdateProductOptionInput {
  key?: string;
  name?: string;
  position?: 1 | 2 | 3;
  is_active?: boolean;
}

export interface ExpandProductOptionInput {
  key: string;
  name: string;
  position: 1 | 2 | 3;
  is_active?: boolean;
  values: { value: string; label: string }[];
  default_value_index: number;
  generate_combinations?: boolean;
}

export interface ExpandProductOptionResult {
  data: {
    option: ProductOption;
    updated_skus: number;
    created_skus: Array<import("./product-service").ProductSku>;
  };
}

/**
 * Batch payload for `PUT /product-options/{optionId}/sync-values`.
 * - Items with `id` rename the existing value's label (slug never changes).
 * - Items without `id` insert a new value (slug derived if omitted).
 * - Any existing value whose id is not in `values` gets soft-deleted.
 */
export interface SyncOptionValuesInput {
  name?: string;
  values: Array<{ id?: string; value?: string; label: string }>;
  /**
   * #2488 — optimistic concurrency: id các giá trị mà bản nháp hydrate từ đó.
   * Lệch với tập đang sống trên server → 409 `OPTION_VALUES_CHANGED` thay vì
   * ghi đè im lặng (values là danh sách toàn quyền — thiếu id nào là xoá id đó).
   */
  known_value_ids?: string[];
}

export interface SyncOptionValuesResult {
  data: {
    option: ProductOption;
    created_skus: Array<import("./product-service").ProductSku>;
  };
}

function nestedUrl(brandSlug: string, productId: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/products/${productId}/options${path}`;
}

function flatUrl(brandSlug: string, optionId: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/product-options/${optionId}${path}`;
}

export const productOptionService = {
  list: (brandSlug: string, productId: string) =>
    apiFetch<{ data: ProductOption[] }>(nestedUrl(brandSlug, productId)),

  getById: (brandSlug: string, optionId: string) =>
    apiFetch<{ data: ProductOption }>(flatUrl(brandSlug, optionId)),

  create: (brandSlug: string, productId: string, data: CreateProductOptionInput) =>
    apiFetch<{ data: ProductOption }>(nestedUrl(brandSlug, productId), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, optionId: string, data: UpdateProductOptionInput) =>
    apiFetch<{ data: ProductOption }>(flatUrl(brandSlug, optionId), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, optionId: string) =>
    apiFetch<null>(flatUrl(brandSlug, optionId), { method: "DELETE" }),

  expand: (brandSlug: string, productId: string, data: ExpandProductOptionInput) =>
    apiFetch<ExpandProductOptionResult>(nestedUrl(brandSlug, productId, "/expand"), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  syncValues: (brandSlug: string, optionId: string, data: SyncOptionValuesInput) =>
    apiFetch<SyncOptionValuesResult>(flatUrl(brandSlug, optionId, "/sync-values"), {
      method: "PUT",
      body: JSON.stringify(data),
    }),
};
