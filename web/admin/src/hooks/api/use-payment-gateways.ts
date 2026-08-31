/**
 * React Query hooks for HQ payment gateway settings (Plan 047 T5.5/T5.6).
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";

import {
  paymentGatewayService,
  type CreateGatewayConnectionInput,
  type PaymentCoverageFilters,
  type PaymentGatewayFilters,
  type RotateGatewaySecretInput,
  type UpdateGatewayConnectionInput,
  type UpdateHqOptionPolicyInput,
} from "@/services/payment-gateway-service";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { paymentGatewayKeys } from "./query-keys";

export function usePaymentReadiness(brandSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: paymentGatewayKeys.readiness(brandSlug, locale),
    queryFn: () => paymentGatewayService.getReadiness(brandSlug),
    enabled: !!brandSlug,
  });
}

export function usePaymentGatewayConnections(
  brandSlug: string,
  filters: PaymentGatewayFilters = {}
) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: paymentGatewayKeys.connections(brandSlug, locale, filters),
    queryFn: () => paymentGatewayService.listConnections(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function usePaymentGatewayConnection(brandSlug: string, connectionId: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: paymentGatewayKeys.connection(brandSlug, locale, connectionId),
    queryFn: () => paymentGatewayService.getConnection(brandSlug, connectionId),
    enabled: !!brandSlug && !!connectionId,
  });
}

export function useCreatePaymentGatewayConnection(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (input: CreateGatewayConnectionInput) =>
      paymentGatewayService.createConnection(brandSlug, input),
    onSuccess: () => {
      toast.success(t("hq.payments.toast.connection_created"));
      qc.invalidateQueries({ queryKey: paymentGatewayKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.payments.toast.connection_create_failed")),
  });
}

export function useUpdatePaymentGatewayConnection(brandSlug: string, connectionId: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (input: UpdateGatewayConnectionInput) =>
      paymentGatewayService.updateConnection(brandSlug, connectionId, input),
    onSuccess: () => {
      toast.success(t("hq.payments.toast.connection_updated"));
      qc.invalidateQueries({ queryKey: paymentGatewayKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("common.error")),
  });
}

export function useValidatePaymentGatewayConnection(brandSlug: string, connectionId: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: () => paymentGatewayService.validateConnection(brandSlug, connectionId),
    onSuccess: () => {
      toast.success(t("hq.payments.toast.validated"));
      qc.invalidateQueries({ queryKey: paymentGatewayKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.payments.toast.validate_failed")),
  });
}

export function useRotatePaymentGatewaySecret(brandSlug: string, connectionId: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (input: RotateGatewaySecretInput) =>
      paymentGatewayService.rotateConnectionSecret(brandSlug, connectionId, input),
    onSuccess: () => {
      toast.success(t("hq.payments.toast.rotated"));
      qc.invalidateQueries({ queryKey: paymentGatewayKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("hq.payments.toast.rotate_failed")),
  });
}

export function usePaymentDisconnectImpact(brandSlug: string, connectionId: string, enabled: boolean) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: paymentGatewayKeys.disconnectImpact(brandSlug, locale, connectionId),
    queryFn: () => paymentGatewayService.getDisconnectImpact(brandSlug, connectionId),
    enabled: !!brandSlug && !!connectionId && enabled,
  });
}

export function useDisconnectPaymentGateway(brandSlug: string, connectionId: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: () => paymentGatewayService.disconnectConnection(brandSlug, connectionId),
    onSuccess: () => {
      toast.success(t("hq.payments.toast.disconnected"));
      qc.invalidateQueries({ queryKey: paymentGatewayKeys.all(brandSlug) });
    },
    onError: (e: Error) => {
      const msg =
        e instanceof ApiError && (e.body as { message?: string })?.message
          ? (e.body as { message: string }).message
          : e.message || t("hq.payments.toast.disconnect_failed");
      toast.error(msg);
    },
  });
}

export function useHqPaymentOptionPolicies(brandSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: paymentGatewayKeys.optionPolicies(brandSlug, locale),
    queryFn: () => paymentGatewayService.listOptionPolicies(brandSlug),
    enabled: !!brandSlug,
  });
}

export function useUpdateHqPaymentOptionPolicy(brandSlug: string) {
  const qc = useQueryClient();
  const { t } = useTranslation();
  return useMutation({
    mutationFn: (input: UpdateHqOptionPolicyInput) =>
      paymentGatewayService.updateOptionPolicy(brandSlug, input),
    onSuccess: () => {
      toast.success(t("hq.payments.toast.policy_updated"));
      qc.invalidateQueries({ queryKey: paymentGatewayKeys.all(brandSlug) });
    },
    onError: (e: Error) => toast.error(e.message || t("common.error")),
  });
}

export function usePaymentCoverage(brandSlug: string, filters: PaymentCoverageFilters = {}) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: paymentGatewayKeys.coverage(brandSlug, locale, filters),
    queryFn: () => paymentGatewayService.listCoverage(brandSlug, filters),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}
