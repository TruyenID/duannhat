/**
 * Coupon Hooks — React Query wrappers around couponService (plan-019).
 *
 * Mutations toast on success/failure and invalidate `couponHqKeys.all` so
 * lists + details refetch automatically. Action mutations (pause/resume) hit
 * the same invalidation pattern because the computed_status changes.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  couponService,
  type CouponFilters,
  type CreateCouponInput,
  type UpdateCouponInput,
} from "@/services/coupon-service";
import { couponHqKeys } from "./query-keys";
import { useTranslation } from "@/providers/app-provider";

// =========================================================================
//  Queries
// =========================================================================

export function useCoupons(brandSlug: string, filters: CouponFilters = {}) {
  return useQuery({
    queryKey: couponHqKeys.list(brandSlug, filters),
    queryFn: () => couponService.list(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useCoupon(brandSlug: string, id: string) {
  return useQuery({
    queryKey: couponHqKeys.detail(brandSlug, id),
    queryFn: () => couponService.getById(brandSlug, id),
    enabled: !!brandSlug && !!id,
  });
}

export function useCouponRedemptions(brandSlug: string, id: string, page = 1) {
  return useQuery({
    queryKey: couponHqKeys.redemptions(brandSlug, id, page),
    queryFn: () => couponService.redemptions(brandSlug, id, page),
    enabled: !!brandSlug && !!id,
    placeholderData: keepPreviousData,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateCoupon(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateCouponInput) => couponService.create(brandSlug, data),
    onSuccess: () => {
      toast.success("Coupon created.");
      qc.invalidateQueries({ queryKey: couponHqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to create coupon."),
  });
}

export function useUpdateCoupon(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateCouponInput }) =>
      couponService.update(brandSlug, id, data),
    onSuccess: () => {
      toast.success("Coupon updated.");
      qc.invalidateQueries({ queryKey: couponHqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update coupon."),
  });
}

export function useDeleteCoupon(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => couponService.delete(brandSlug, id),
    onSuccess: () => {
      toast.success("Coupon deleted.");
      qc.invalidateQueries({ queryKey: couponHqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to delete coupon."),
  });
}

export function useRestoreCoupon(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (id: string) => couponService.restore(brandSlug, id),
    onSuccess: () => {
      toast.success(t("toast.coupon.restored"));
      qc.invalidateQueries({ queryKey: couponHqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("toast.coupon.restore_failed")),
  });
}

export function useBulkDeleteCoupons(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (ids: string[]) => couponService.bulkDelete(brandSlug, ids),
    onSuccess: (data) => {
      const skipped = data.errors.length;
      if (skipped > 0) {
        const names = data.errors.map((e) => e.name ?? e.id).join(", ");
        if (data.deleted === 0) {
          toast.error(t("toast.coupon.bulk_skipped", { deleted: 0, skipped, names }));
        } else {
          toast.warning(t("toast.coupon.bulk_skipped", { deleted: data.deleted, skipped, names }));
        }
      } else {
        toast.success(t("toast.coupon.bulk_deleted", { n: data.deleted }));
      }
      qc.invalidateQueries({ queryKey: couponHqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function usePauseCoupon(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => couponService.pause(brandSlug, id),
    onSuccess: () => {
      toast.success("Coupon paused.");
      qc.invalidateQueries({ queryKey: couponHqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to pause coupon."),
  });
}

export function useResumeCoupon(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => couponService.resume(brandSlug, id),
    onSuccess: () => {
      toast.success("Coupon resumed.");
      qc.invalidateQueries({ queryKey: couponHqKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to resume coupon."),
  });
}
