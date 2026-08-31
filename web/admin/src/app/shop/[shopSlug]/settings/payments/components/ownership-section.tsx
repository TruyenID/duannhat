"use client";

import { AlertCircle } from "lucide-react";
import {
  Alert,
  AlertDescription,
  AlertTitle,
  Badge,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import type { PaymentConfigurationOwnership } from "@/services/shop-payment-settings-service";

export interface OwnershipSectionProps {
  ownership: PaymentConfigurationOwnership;
  connectionMutable: boolean;
}

/**
 * Branch-management projection (plan-047). The backend fails CLOSED: until the
 * identity projection adapter resolves this branch, management_model is
 * "unresolved" and every option is denied at the ownership layer — the alert
 * below explains that state instead of hiding it.
 */
export function OwnershipSection({ ownership, connectionMutable }: OwnershipSectionProps) {
  const { t } = useTranslation();

  const model = ownership.management_model;
  const managementLabel =
    model === "hq_managed"
      ? t("shop.payments.ownership.hq_managed")
      : model === "franchise_owned"
        ? t("shop.payments.ownership.franchise")
        : t("shop.payments.ownership.unresolved");

  return (
    <Card data-slot="payments-ownership-section">
      <CardHeader>
        <CardTitle>{t("shop.payments.ownership.title")}</CardTitle>
        <CardDescription>{t("shop.payments.ownership.description")}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {model === "unresolved" && (
          <Alert role="status">
            <AlertCircle className="size-4" aria-hidden />
            <AlertTitle>{t("shop.payments.ownership.unresolved_title")}</AlertTitle>
            <AlertDescription>
              {t("shop.payments.ownership.unresolved_desc")}
              {ownership.reason && (
                <span className="mt-1 block font-mono text-xs">{ownership.reason}</span>
              )}
            </AlertDescription>
          </Alert>
        )}

        <dl className="grid gap-4 sm:grid-cols-2">
          <div>
            <dt className="text-xs text-muted-foreground">
              {t("shop.payments.ownership.management_type")}
            </dt>
            <dd className="mt-1 flex items-center gap-2">
              <span className="font-medium">{managementLabel}</span>
              <Badge variant="outline" className="h-5 text-[10px]">
                {model}
              </Badge>
            </dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">
              {t("shop.payments.ownership.revision")}
            </dt>
            <dd className="mt-1 font-mono text-sm">
              {ownership.ownership_revision ?? "—"}
            </dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">
              {t("shop.payments.ownership.brand_owner_org_unit")}
            </dt>
            <dd className="mt-1 font-mono text-xs">
              {ownership.brand_owner_org_unit_id ?? "—"}
            </dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">
              {t("shop.payments.ownership.operator_org_unit")}
            </dt>
            <dd className="mt-1 font-mono text-xs">
              {ownership.operator_org_unit_id ?? "—"}
            </dd>
          </div>
        </dl>

        <p className="text-xs text-muted-foreground">
          {connectionMutable
            ? t("shop.payments.ownership.can_manage_connection")
            : t("shop.payments.ownership.read_only_connection")}
        </p>
      </CardContent>
    </Card>
  );
}
