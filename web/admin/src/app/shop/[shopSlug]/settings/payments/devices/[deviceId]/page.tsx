"use client";

import Link from "next/link";
import { ChevronLeft } from "lucide-react";
import { useParams } from "next/navigation";
import { Button } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { useDevicePaymentOptions } from "@/hooks/api/use-shop-payment-settings";
import { useShopDevices } from "@/hooks/api/use-devices";
import { PaymentsPageShell } from "../../components/payments-page-shell";
import { PaymentsViewPanel } from "../../components/payments-view-panel";
import { DevicePolicySection } from "../../components/device-policy-section";
import { resolvePaymentsViewState } from "../../lib/payments-view-state";

export default function DevicePaymentPolicyPage() {
  const { shopSlug, deviceId } = useParams<{ shopSlug: string; deviceId: string }>();
  const { t } = useTranslation();
  const { data, isLoading, isFetching, isError, error, refetch } = useDevicePaymentOptions(
    shopSlug,
    deviceId
  );
  // Device metadata (name/type) — the evaluation payload carries only policy
  // rows, so the header name comes from the shop device registry.
  const devicesQuery = useShopDevices(shopSlug, { per_page: 100 });
  const device = devicesQuery.data?.data.find((d) => d.id === deviceId);

  const evaluation = data?.data;
  const viewState = resolvePaymentsViewState({
    isLoading,
    isFetching,
    isError,
    error,
    hasData: Boolean(evaluation),
  });

  return (
    <PaymentsPageShell
      shopSlug={shopSlug}
      title={device?.name ?? t("shop.payments.device.title")}
      description={t("shop.payments.device.subtitle")}
      onRefresh={() => refetch()}
      isRefreshing={isFetching && !isLoading}
    >
      <div className="mb-4">
        <Button asChild variant="ghost" size="sm" className="h-8 gap-1 px-2 text-xs">
          <Link href={`/shop/${shopSlug}/settings/payments/devices`}>
            <ChevronLeft className="size-3.5" aria-hidden />
            {t("shop.payments.device.back_to_devices")}
          </Link>
        </Button>
      </div>

      <PaymentsViewPanel state={viewState} onRetry={() => refetch()}>
        {evaluation && (
          <DevicePolicySection shopSlug={shopSlug} deviceId={deviceId} evaluation={evaluation} />
        )}
      </PaymentsViewPanel>
    </PaymentsPageShell>
  );
}
