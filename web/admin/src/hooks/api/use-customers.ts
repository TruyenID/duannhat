/**
 * Customer Hooks — React Query wrappers around customerService.
 *
 * Follows the canonical pattern of use-products.ts / use-tables.ts: every
 * mutation toasts (sonner) on success/failure and invalidates the matching
 * `customerShopKeys.all(shopSlug)` (or HQ equivalent).
 *
 * `useCustomerLookup` is the POS autocomplete hook — it debounces 300ms and
 * only fires when the phone length is >= 8 so the API is not hit on every
 * keystroke. The cache key includes the phone so multiple concurrent
 * lookups (rare but possible) don't overwrite each other.
 */

import { useEffect, useState } from "react";
import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  customerService,
  type CreateCustomerInput,
  type CustomerFilters,
  type UpdateCustomerInput,
} from "@/services/customer-service";
import { customerHqKeys, customerShopKeys } from "./query-keys";

// =========================================================================
//  Queries — Shop scope
// =========================================================================

export function useCustomers(shopSlug: string, filters: CustomerFilters = {}) {
  return useQuery({
    queryKey: customerShopKeys.list(shopSlug, filters),
    queryFn: () => customerService.list(shopSlug, filters),
    enabled: !!shopSlug,
  });
}

export function useCustomer(shopSlug: string, id: string) {
  return useQuery({
    queryKey: customerShopKeys.detail(shopSlug, id),
    queryFn: () => customerService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
  });
}

/**
 * Minimum phone length before the lookup hook is allowed to fire. Chosen so
 * the POS autocomplete does not pepper the API on every single-digit
 * keystroke. Vietnamese mobile numbers are 10 digits; 8 leaves enough room
 * for the cashier to be specific without blocking partial matches.
 */
const LOOKUP_MIN_LENGTH = 8;

/**
 * Debounced phone-prefix lookup for the POS customer autocomplete.
 *
 * The hook keeps a local `debouncedPhone` state that trails the live
 * `phone` input by 300ms. Only the debounced value participates in the
 * query key / `enabled` flag, so two rapid keystrokes fire a single
 * network request.
 */
export function useCustomerLookup(shopSlug: string, phone: string) {
  const [debouncedPhone, setDebouncedPhone] = useState(phone);

  useEffect(() => {
    const t = setTimeout(() => setDebouncedPhone(phone), 300);
    return () => clearTimeout(t);
  }, [phone]);

  return useQuery({
    queryKey: customerShopKeys.lookup(shopSlug, debouncedPhone),
    queryFn: () => customerService.lookup(shopSlug, debouncedPhone),
    enabled: !!shopSlug && debouncedPhone.length >= LOOKUP_MIN_LENGTH,
  });
}

// =========================================================================
//  Mutations — Shop scope
// =========================================================================

export function useCreateCustomer(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateCustomerInput) => customerService.create(shopSlug, data),
    onSuccess: () => {
      toast.success("Customer created.");
      qc.invalidateQueries({ queryKey: customerShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create customer."),
  });
}

export function useUpdateCustomer(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateCustomerInput }) =>
      customerService.update(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Customer updated.");
      qc.invalidateQueries({ queryKey: customerShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update customer."),
  });
}

export function useDeleteCustomer(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => customerService.delete(shopSlug, id),
    onSuccess: () => {
      toast.success("Customer deleted.");
      qc.invalidateQueries({ queryKey: customerShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete customer."),
  });
}

export function useRestoreCustomer(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => customerService.restore(shopSlug, id),
    onSuccess: () => {
      toast.success("Customer restored.");
      qc.invalidateQueries({ queryKey: customerShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to restore customer."),
  });
}

// =========================================================================
//  Queries — HQ scope
// =========================================================================

export function useHqCustomers(brandSlug: string, filters: CustomerFilters = {}) {
  return useQuery({
    queryKey: customerHqKeys.list(brandSlug, filters),
    queryFn: () => customerService.hqList(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useHqCustomer(brandSlug: string, id: string) {
  return useQuery({
    queryKey: customerHqKeys.detail(brandSlug, id),
    queryFn: () => customerService.hqGetById(brandSlug, id),
    enabled: !!brandSlug && !!id,
  });
}
