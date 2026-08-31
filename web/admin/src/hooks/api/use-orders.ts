/**
 * Customer Order Hooks — React Query wrappers around orderService.
 *
 * Every workflow mutation (create / confirm / complete / cancel / item
 * add / item remove) toasts via sonner and invalidates
 * `orderShopKeys.all(shopSlug)` so both the list and any cached detail
 * picks up the new state on next read.
 *
 * `useOrders` accepts an optional `refetchInterval`. The kitchen view on
 * the orders list page passes `15_000` when the active tab is `pending` or
 * `confirmed`; everywhere else it is omitted (manual refresh only). See
 * DESIGN.md §"Key decisions" #4.
 *
 * HQ hooks live at the bottom of the file — read-only, no mutations.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  orderService,
  type AddItemsInput,
  type CompleteOrderInput,
  type CreateOrderInput,
  type OrderFilters,
} from "@/services/order-service";
import { orderHqKeys, orderShopKeys } from "./query-keys";

// =========================================================================
//  Queries — Shop scope
// =========================================================================

export interface UseOrdersOptions {
  /**
   * Poll interval in ms. Pass `15_000` from the kitchen list page only
   * when the active tab is `pending` or `confirmed`. Pass `undefined` on
   * any other tab so React Query stops polling.
   */
  refetchInterval?: number;
}

export function useOrders(
  shopSlug: string,
  filters: OrderFilters = {},
  options: UseOrdersOptions = {}
) {
  return useQuery({
    queryKey: orderShopKeys.list(shopSlug, filters),
    queryFn: () => orderService.list(shopSlug, filters),
    enabled: !!shopSlug,
    staleTime: 0,
    refetchOnMount: true,
    refetchInterval: options.refetchInterval,
  });
}

export function useOrder(shopSlug: string, id: string, options: { refetchInterval?: number } = {}) {
  return useQuery({
    queryKey: orderShopKeys.detail(shopSlug, id),
    queryFn: () => orderService.getById(shopSlug, id),
    enabled: !!shopSlug && !!id,
    // Opt-in polling: a dine-in order is closed by the kiosk/customer-web when
    // fully paid, so admin-web's cache never invalidates on its own. The detail
    // page passes an interval while the order is non-terminal so the status
    // flips to "Hoàn thành" without a manual refresh.
    refetchInterval: options.refetchInterval,
    refetchOnWindowFocus: options.refetchInterval != null,
  });
}

// =========================================================================
//  Mutations — Shop scope
// =========================================================================

export function useCreateOrder(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateOrderInput) => orderService.create(shopSlug, data),
    onSuccess: () => {
      toast.success("Order created.");
      qc.invalidateQueries({ queryKey: orderShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create order."),
  });
}

export function useConfirmOrder(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => orderService.confirm(shopSlug, id),
    onSuccess: () => {
      toast.success("Order confirmed.");
      qc.invalidateQueries({ queryKey: orderShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to confirm order."),
  });
}

export function useCompleteOrder(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: CompleteOrderInput }) =>
      orderService.complete(shopSlug, id, data),
    onSuccess: () => {
      toast.success("Order completed.");
      qc.invalidateQueries({ queryKey: orderShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to complete order."),
  });
}

export function useVoidOrder(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, voidReason }: { id: string; voidReason: string }) =>
      orderService.voidOrder(shopSlug, id, voidReason),
    onSuccess: () => {
      toast.success("Order cancelled.");
      qc.invalidateQueries({ queryKey: orderShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to cancel order."),
  });
}

export function useAddOrderItems(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ orderId, data }: { orderId: string; data: AddItemsInput }) =>
      orderService.addItems(shopSlug, orderId, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: orderShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to add items."),
  });
}

export function useRemoveOrderItem(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ orderId, itemId }: { orderId: string; itemId: string }) =>
      orderService.removeItem(shopSlug, orderId, itemId),
    onSuccess: () => {
      toast.success("Item removed.");
      qc.invalidateQueries({ queryKey: orderShopKeys.all(shopSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to remove item."),
  });
}

// =========================================================================
//  Queries — HQ scope
// =========================================================================

export function useHqOrders(brandSlug: string, filters: OrderFilters = {}) {
  return useQuery({
    queryKey: orderHqKeys.list(brandSlug, filters),
    queryFn: () => orderService.hqList(brandSlug, filters),
    enabled: !!brandSlug,
  });
}

export function useHqOrder(brandSlug: string, id: string) {
  return useQuery({
    queryKey: orderHqKeys.detail(brandSlug, id),
    queryFn: () => orderService.hqGetById(brandSlug, id),
    enabled: !!brandSlug && !!id,
  });
}

export function useHqVoidOrder(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, voidReason }: { id: string; voidReason: string }) =>
      orderService.hqVoidOrder(brandSlug, id, voidReason),
    onSuccess: () => {
      toast.success("Order cancelled.");
      qc.invalidateQueries({ queryKey: orderHqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to cancel order."),
  });
}
