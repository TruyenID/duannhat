/**
 * Shop payment gateway settings hooks — plan-047 T5.3 / T5.4.
 *
 * Query + mutation wrappers around shopPaymentSettingsService (real backend
 * shapes). Mutations return { option, revision }; on success the returned
 * option row is written back into the cached configuration / device
 * evaluation so the UI reflects the authoritative server state without a
 * refetch, then the whole namespace is invalidated for consistency.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  shopPaymentSettingsService,
  type EffectiveOptionsEvaluation,
  type EffectivePaymentOptionRow,
  type ShopPaymentConfiguration,
  type ShopPayPaySwitchState,
  type UpdateDevicePaymentOptionInput,
  type UpdateShopPaymentOptionInput,
  type UpdateShopPayPaySwitchInput,
} from "@/services/shop-payment-settings-service";
import { shopPaymentSettingsKeys } from "./query-keys";

export function useShopPaymentConfiguration(shopSlug: string) {
  return useQuery({
    queryKey: shopPaymentSettingsKeys.configuration(shopSlug),
    queryFn: () => shopPaymentSettingsService.getConfiguration(shopSlug),
    enabled: !!shopSlug,
    placeholderData: keepPreviousData,
  });
}

export function useDevicePaymentOptions(shopSlug: string, deviceId: string) {
  return useQuery({
    queryKey: shopPaymentSettingsKeys.devicePolicy(shopSlug, deviceId),
    queryFn: () => shopPaymentSettingsService.getDeviceOptions(shopSlug, deviceId),
    enabled: !!shopSlug && !!deviceId,
  });
}

function replaceOption(
  options: EffectivePaymentOptionRow[],
  next: EffectivePaymentOptionRow | null
): EffectivePaymentOptionRow[] {
  if (!next) return options;
  return options.map((row) => (row.id === next.id ? next : row));
}

export function useUpdateShopPaymentOption(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      optionId,
      data,
    }: {
      optionId: string;
      data: UpdateShopPaymentOptionInput;
    }) => shopPaymentSettingsService.updateShopOption(shopSlug, optionId, data),
    onSuccess: (res) => {
      qc.setQueryData<{ data: ShopPaymentConfiguration } | undefined>(
        shopPaymentSettingsKeys.configuration(shopSlug),
        (prev) =>
          prev
            ? {
                data: {
                  ...prev.data,
                  revision: res.data.revision,
                  options: replaceOption(prev.data.options, res.data.option),
                },
              }
            : prev
      );
      void qc.invalidateQueries({ queryKey: shopPaymentSettingsKeys.all(shopSlug) });
    },
  });
}

export function useUpdateDevicePaymentOption(shopSlug: string, deviceId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: UpdateDevicePaymentOptionInput) =>
      shopPaymentSettingsService.updateDeviceOption(shopSlug, deviceId, data),
    onSuccess: (res) => {
      qc.setQueryData<{ data: EffectiveOptionsEvaluation } | undefined>(
        shopPaymentSettingsKeys.devicePolicy(shopSlug, deviceId),
        (prev) =>
          prev
            ? {
                data: {
                  ...prev.data,
                  revision: res.data.revision,
                  options: replaceOption(prev.data.options, res.data.option),
                },
              }
            : prev
      );
      void qc.invalidateQueries({
        queryKey: shopPaymentSettingsKeys.configuration(shopSlug),
      });
    },
  });
}

/**
 * plan-054 D9 — the shop-level PayPay (customer-web QR) switch.
 *
 * Separate from `useShopPaymentConfiguration` because the capability is absent
 * from that payload until the first PayPay checkout provisions its connection
 * option; see shop-payment-settings-service.ts for the full note.
 */
export function useShopPayPaySwitch(shopSlug: string) {
  return useQuery({
    queryKey: shopPaymentSettingsKeys.paypay(shopSlug),
    queryFn: () => shopPaymentSettingsService.getPayPaySwitch(shopSlug),
    enabled: !!shopSlug,
  });
}

export function useUpdateShopPayPaySwitch(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: UpdateShopPayPaySwitchInput) =>
      shopPaymentSettingsService.updatePayPaySwitch(shopSlug, data),
    onSuccess: (res) => {
      qc.setQueryData<{ data: ShopPayPaySwitchState } | undefined>(
        shopPaymentSettingsKeys.paypay(shopSlug),
        res
      );
      // Both controls write the same shop_payment_options row, so the options
      // list beside this one must be refetched or it would show a stale
      // preference for the very same capability.
      void qc.invalidateQueries({ queryKey: shopPaymentSettingsKeys.all(shopSlug) });
    },
  });
}

export type { ShopPaymentConfiguration, EffectiveOptionsEvaluation, ShopPayPaySwitchState };
