"use client";

import {
  Badge,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  StatusBadge,
} from "@godxjp/ui";
import { formatDateTime } from "@/lib/date";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import type {
  PaymentConfigurationOwnership,
  PaymentConnectionSummary,
} from "@/services/shop-payment-settings-service";

export interface ConnectionSectionProps {
  ownership: PaymentConfigurationOwnership;
  connections: PaymentConnectionSummary[];
  setupRequired: boolean;
}

/**
 * Gateway connections registered for this shop's tenant. The backend only
 * discloses connections once the ownership projection resolves an owner
 * scope (fail-closed), so an empty list with an unresolved ownership means
 * "unknown", not "none" — the copy distinguishes the two.
 */
export function ConnectionSection({
  ownership,
  connections,
  setupRequired,
}: ConnectionSectionProps) {
  const { t } = useTranslation();

  if (connections.length === 0) {
    const unresolved = ownership.management_model === "unresolved";
    return (
      <Card data-slot="payments-connection-section">
        <CardHeader>
          <CardTitle>{t("shop.payments.connection.title")}</CardTitle>
          <CardDescription>
            {setupRequired
              ? t("shop.payments.connection.setup_required_desc")
              : unresolved
                ? t("shop.payments.connection.unresolved_desc")
                : t("shop.payments.connection.none")}
          </CardDescription>
        </CardHeader>
      </Card>
    );
  }

  return (
    <div data-slot="payments-connection-section" className="space-y-4">
      <div>
        <h2 className="text-base font-semibold">{t("shop.payments.connection.title")}</h2>
        <p className="text-sm text-muted-foreground">
          {t("shop.payments.connection.list_desc")}
        </p>
      </div>

      <ul className="space-y-4" role="list">
        {connections.map((connection) => (
          <li key={connection.id}>
            <ConnectionCard connection={connection} />
          </li>
        ))}
      </ul>
    </div>
  );
}

function ConnectionCard({ connection }: { connection: PaymentConnectionSummary }) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="flex items-center gap-2 text-sm">
            {connection.merchant_display_name || connection.provider}
            <Badge variant="secondary" className="h-5 text-[10px]">
              {connection.provider}
            </Badge>
            <Badge variant="outline" className="h-5 text-[10px] uppercase">
              {connection.environment}
            </Badge>
          </CardTitle>
          <CardDescription>
            {connection.owner_scope === "hq"
              ? t("shop.payments.connection.scope.hq")
              : connection.owner_scope === "franchise"
                ? t("shop.payments.connection.scope.franchise")
                : connection.owner_scope}
          </CardDescription>
        </div>
        <StatusBadge status={connection.is_active ? "active" : "inactive"} />
      </CardHeader>
      <CardContent>
        <dl className="grid gap-4 sm:grid-cols-2">
          <div>
            <dt className="text-xs text-muted-foreground">
              {t("shop.payments.connection.identity")}
            </dt>
            <dd className="mt-1 font-mono text-sm">{connection.merchant_account_id}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">
              {t("shop.payments.connection.health")}
            </dt>
            <dd className="mt-1 flex items-center gap-2">
              <Badge
                variant={connection.health === "ready" ? "default" : "secondary"}
                className="h-5 text-[10px]"
              >
                {connection.health}
              </Badge>
              {connection.health_reason_code && (
                <span className="font-mono text-xs text-muted-foreground">
                  {connection.health_reason_code}
                </span>
              )}
            </dd>
          </div>
          {connection.last_validated_at && (
            <div className="sm:col-span-2">
              <dt className="text-xs text-muted-foreground">
                {t("shop.payments.connection.last_validated")}
              </dt>
              <dd className="mt-1 text-sm">
                {formatDateTime(connection.last_validated_at, locale, timezone)}
              </dd>
            </div>
          )}
        </dl>
      </CardContent>
    </Card>
  );
}
