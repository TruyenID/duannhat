"use client";

import { useParams } from "next/navigation";
import { useShopPaymentConfiguration } from "@/hooks/api/use-shop-payment-settings";
import { PaymentsPageShell } from "../components/payments-page-shell";
import { PaymentsViewPanel } from "../components/payments-view-panel";
import { OwnershipSection } from "../components/ownership-section";
import { resolvePaymentsViewState } from "../lib/payments-view-state";

export default function PaymentsOwnershipPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { data, isLoading, isFetching, isError, error, refetch } =
    useShopPaymentConfiguration(shopSlug);

  const config = data?.data;
  const viewState = resolvePaymentsViewState({
    isLoading,
    isFetching,
    isError,
    error,
    hasData: Boolean(config),
  });

  return (
    <PaymentsPageShell
      shopSlug={shopSlug}
      onRefresh={() => refetch()}
      isRefreshing={isFetching && !isLoading}
    >
      <PaymentsViewPanel state={viewState} onRetry={() => refetch()}>
        {config && (
          <OwnershipSection
            ownership={config.ownership}
            connectionMutable={config.connection_mutable}
          />
        )}
      </PaymentsViewPanel>
    </PaymentsPageShell>
  );
}
