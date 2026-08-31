/**
 * Menu Section Service — pure TypeScript, no React dependency.
 *
 * Brand-scoped menu sections are reusable groupings that can be attached to
 * many menus (N:N pivot with display_order). The React-Query layer lives in
 * src/hooks/api/use-menu-sections.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";

// =========================================================================
//  Types
// =========================================================================

export interface MenuSection {
  /**
   * #1218 — present only when the section was loaded THROUGH a menu. These are
   * per-menu values held on the menu↔section pivot, not on the section itself:
   * the same section shows in many menus and keeps a separate tax type in each.
   */
  pivot?: {
    display_order: number | null;
    tax_type_id: string | null;
  };
  id: string;
  name: string;
  translations?: Record<string, { name?: string | null }>;
  /**
   * #1187 — drives the customer-web "featured" carousel. Before this flag the
   * two customer surfaces decided by scanning the section's DISPLAY NAME for a
   * handful of hard-coded words and star/fire glyphs, so a rename silently
   * emptied the carousel and a shop outside those three languages could never
   * fill it.
   */
  is_featured?: boolean;
  menus_count?: number;
  menu_products_count?: number;
  created_at: string;
  updated_at: string;
}

export interface MenuSectionFilters {
  search?: string;
  sort?: string;
  per_page?: number;
}

export interface CreateMenuSectionInput {
  name: string;
  is_featured?: boolean;
  ja?: Record<string, string>;
  en?: Record<string, string>;
  vi?: Record<string, string>;
}

export interface UpdateMenuSectionInput {
  updated_at?: string;
  name?: string;
  is_featured?: boolean;
  ja?: Record<string, string>;
  en?: Record<string, string>;
  vi?: Record<string, string>;
}

export interface SyncMenuSectionEntry {
  id: string;
  display_order?: number;
}

export interface SyncMenuSectionsInput {
  sections: SyncMenuSectionEntry[];
}

// =========================================================================
//  Helpers
// =========================================================================

function brandUrl(brandSlug: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/menu-sections${path}`;
}

function toParams(filters: MenuSectionFilters): string {
  const params = new URLSearchParams();
  if (filters.search) params.set("search", filters.search);
  if (filters.sort) params.set("sort", filters.sort);
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  return params.toString();
}

// =========================================================================
//  Service
// =========================================================================

export const menuSectionService = {
  list: (brandSlug: string, filters: MenuSectionFilters = {}) =>
    apiFetch<PaginatedResponse<MenuSection>>(
      `${brandUrl(brandSlug)}?${toParams({ per_page: 100, sort: "name", ...filters })}`
    ),

  getById: (brandSlug: string, id: string) =>
    apiFetch<{ data: MenuSection }>(brandUrl(brandSlug, `/${id}`)),

  create: (brandSlug: string, data: CreateMenuSectionInput) =>
    apiFetch<{ data: MenuSection }>(brandUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, id: string, data: UpdateMenuSectionInput) =>
    apiFetch<{ data: MenuSection }>(brandUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<null>(brandUrl(brandSlug, `/${id}`), { method: "DELETE" }),

  syncForMenu: (brandSlug: string, menuId: string, data: SyncMenuSectionsInput) =>
    apiFetch<{ data: unknown }>(`/api/v1/hq/${brandSlug}/menus/${menuId}/sections`, {
      method: "PUT",
      body: JSON.stringify(data),
    }),
};
