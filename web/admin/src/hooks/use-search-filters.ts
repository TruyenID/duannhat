"use client";

import { useCallback, useMemo } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";

/**
 * Generic hook that syncs filter state with URL search params.
 *
 * - Each filter key maps to a search param (?search=foo&status=active&page=2)
 * - Changing any filter (except page) resets page to 1
 * - Values equal to their default are omitted from the URL (keeps it clean)
 * - Debounced search is NOT handled here — the page still owns `useDebounce`
 *   and passes the debounced value into the filters object for the API call.
 *
 * @example
 * ```tsx
 * const { filters, setFilter, setFilters, page, setPage } = useSearchFilters({
 *   search: "",
 *   status: "all",
 *   is_hidden: "all",
 * });
 * ```
 */
export function useSearchFilters<T extends Record<string, string>>(defaults: T) {
  const searchParams = useSearchParams();
  const pathname = usePathname();
  const router = useRouter();

  // Read current filters from URL, falling back to defaults
  const filters = useMemo(() => {
    const result = { ...defaults };
    for (const key of Object.keys(defaults)) {
      const value = searchParams.get(key);
      if (value !== null) {
        (result as Record<string, string>)[key] = value;
      }
    }
    return result;
  }, [searchParams, defaults]);

  const page = Number(searchParams.get("page") || "1");

  // Build URL from params, omitting defaults
  const buildUrl = useCallback(
    (params: Record<string, string>, newPage: number) => {
      const sp = new URLSearchParams();
      for (const [key, value] of Object.entries(params)) {
        if (value !== defaults[key] && value !== "") {
          sp.set(key, value);
        }
      }
      if (newPage > 1) {
        sp.set("page", String(newPage));
      }
      const qs = sp.toString();
      return qs ? `${pathname}?${qs}` : pathname;
    },
    [defaults, pathname]
  );

  // Set a single filter — resets page to 1
  const setFilter = useCallback(
    (key: keyof T, value: string) => {
      const next = { ...filters, [key]: value };
      router.replace(buildUrl(next, 1), { scroll: false });
    },
    [filters, buildUrl, router]
  );

  // Set multiple filters at once — resets page to 1
  const setFilters = useCallback(
    (updates: Partial<T>) => {
      const next = { ...filters, ...updates };
      router.replace(buildUrl(next, 1), { scroll: false });
    },
    [filters, buildUrl, router]
  );

  // Set page without touching filters
  const setPage = useCallback(
    (newPage: number | ((prev: number) => number)) => {
      const resolved = typeof newPage === "function" ? newPage(page) : newPage;
      router.replace(buildUrl(filters, resolved), { scroll: false });
    },
    [filters, page, buildUrl, router]
  );

  // Reset all filters to defaults
  const resetFilters = useCallback(() => {
    router.replace(pathname, { scroll: false });
  }, [pathname, router]);

  return {
    filters,
    page,
    setFilter,
    setFilters,
    setPage,
    resetFilters,
  } as const;
}
