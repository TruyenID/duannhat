"use client";

import type { PaymentGatewayConnectionSummary } from "@/services/payment-gateway-service";
import { useTranslation } from "@/providers/app-provider";
import { getPaymentGatewayEnvironmentLabel } from "@/types/models/enum/PaymentGatewayEnvironment";
import type { PaymentGatewayEnvironment } from "@/types/models/enum/PaymentGatewayEnvironment";

export interface SafeMerchantIdentityProps {
  connection: Pick<
    PaymentGatewayConnectionSummary,
    | "provider"
    | "environment"
    | "merchant_display_name"
    | "merchant_account_id"
    | "merchant_store_id"
    | "key_fingerprint"
  >;
}

/** Renders provider merchant identity safe for DOM (G4 — no secrets). */
export function SafeMerchantIdentity({ connection }: SafeMerchantIdentityProps) {
  const { locale, t } = useTranslation();

  const envLabel = getPaymentGatewayEnvironmentLabel(
    connection.environment as PaymentGatewayEnvironment,
    locale
  );

  return (
    <dl
      data-slot="safe-merchant-identity"
      className="grid gap-2 text-sm sm:grid-cols-2"
    >
      <div>
        <dt className="text-xs text-muted-foreground">{t("hq.payments.field.provider")}</dt>
        <dd className="font-medium">{connection.provider.name}</dd>
      </div>
      <div>
        <dt className="text-xs text-muted-foreground">{t("hq.payments.field.environment")}</dt>
        <dd>{envLabel}</dd>
      </div>
      <div>
        <dt className="text-xs text-muted-foreground">{t("hq.payments.field.merchant_account")}</dt>
        <dd>
          <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
            {connection.merchant_account_id}
          </code>
        </dd>
      </div>
      {connection.merchant_display_name ? (
        <div>
          <dt className="text-xs text-muted-foreground">{t("hq.payments.field.display_name")}</dt>
          <dd>{connection.merchant_display_name}</dd>
        </div>
      ) : null}
      {connection.merchant_store_id ? (
        <div>
          <dt className="text-xs text-muted-foreground">{t("hq.payments.field.store_id")}</dt>
          <dd>
            <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
              {connection.merchant_store_id}
            </code>
          </dd>
        </div>
      ) : null}
      {connection.key_fingerprint ? (
        <div className="sm:col-span-2">
          <dt className="text-xs text-muted-foreground">{t("hq.payments.field.key_fingerprint")}</dt>
          <dd>
            <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
              {connection.key_fingerprint}
            </code>
          </dd>
        </div>
      ) : null}
    </dl>
  );
}
