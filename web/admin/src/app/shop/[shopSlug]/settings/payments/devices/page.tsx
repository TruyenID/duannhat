"use client";

import { useParams } from "next/navigation";
import { useShopDevices } from "@/hooks/api/use-devices";
import { PaymentsPageShell } from "../components/payments-page-shell";
import { PaymentsViewPanel } from "../components/payments-view-panel";
import { DevicesSection } from "../components/devices-section";
import { resolvePaymentsViewState } from "../lib/payments-view-state";

export default function PaymentsDevicesPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const devicesQuery = useShopDevices(shopSlug, { per_page: 100 });

  const devices = devicesQuery.data?.data ?? [];
  const hasData = Boolean(devicesQuery.data);

  const viewState = resolvePaymentsViewState({
    isLoading: devicesQuery.isLoading,
    isFetching: devicesQuery.isFetching,
    isError: devicesQuery.isError,
    error: devicesQuery.error,
    hasData,
  });

  return (
    <PaymentsPageShell
      shopSlug={shopSlug}
      onRefresh={() => void devicesQuery.refetch()}
      isRefreshing={devicesQuery.isFetching && !devicesQuery.isLoading}
    >
      <PaymentsViewPanel state={viewState} onRetry={() => void devicesQuery.refetch()}>
        {hasData && <DevicesSection shopSlug={shopSlug} devices={devices} />}
      </PaymentsViewPanel>
    </PaymentsPageShell>
  );
}
