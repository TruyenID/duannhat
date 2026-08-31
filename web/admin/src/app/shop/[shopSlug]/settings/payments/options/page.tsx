"use client";

import { useParams } from "next/navigation";
import { useShopPaymentConfiguration } from "@/hooks/api/use-shop-payment-settings";
import { PaymentsPageShell } from "../components/payments-page-shell";
import { PaymentsViewPanel } from "../components/payments-view-panel";
import { OptionsSection } from "../components/options-section";
import { PayPaySection } from "../components/paypay-section";
import { resolvePaymentsViewState } from "../lib/payments-view-state";

export default function PaymentsOptionsPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { data, isLoading, isFetching, isError, error, refetch } =
    useShopPaymentConfiguration(shopSlug);

  const config = data?.data;
  const setupRequired = Boolean(config?.setup_required);

  const viewState = resolvePaymentsViewState({
    isLoading,
    isFetching,
    isError,
    error,
    hasData: Boolean(config),
    setupRequired,
    isEmpty: Boolean(config && !setupRequired && config.options.length === 0),
  });

  return (
    <PaymentsPageShell
      shopSlug={shopSlug}
      onRefresh={() => refetch()}
      isRefreshing={isFetching && !isLoading}
    >
      {/* Deliberately OUTSIDE PaymentsViewPanel: the panel renders exactly one
          state and swallows its children on setup-required / empty, which is
          the very case the PayPay switch has to survive — a shop with no
          provisioned gateway options is a shop that has never taken a PayPay
          payment, and that is when opting out still means something. */}
      <div className="mb-6">
        <PayPaySection shopSlug={shopSlug} />
      </div>

      <PaymentsViewPanel state={viewState} onRetry={() => refetch()}>
        {config && (
          <OptionsSection
            shopSlug={shopSlug}
            options={config.options}
            setupRequired={setupRequired}
          />
        )}
      </PaymentsViewPanel>
    </PaymentsPageShell>
  );
}
