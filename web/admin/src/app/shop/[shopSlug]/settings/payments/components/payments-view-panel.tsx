"use client";

import Link from "next/link";
import { AlertCircle, Lock, RefreshCw, ShieldAlert, WifiOff } from "lucide-react";
import { Alert, AlertDescription, AlertTitle, Button, Spinner } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import {
  paymentsStateTestId,
  type PaymentsViewState,
} from "../lib/payments-view-state";

export interface PaymentsViewPanelProps {
  state: PaymentsViewState;
  onRetry?: () => void;
  onProviderAction?: () => void;
  setupHref?: string;
  children?: React.ReactNode;
}

/**
 * Renders exactly one primary state (G12). Pass `children` only when state.kind === "data".
 */
export function PaymentsViewPanel({
  state,
  onRetry,
  onProviderAction,
  setupHref,
  children,
}: PaymentsViewPanelProps) {
  const { t } = useTranslation();

  if (state.kind === "loading") {
    return (
      <div
        data-slot="payments-view-panel"
        data-testid={paymentsStateTestId("loading")}
        className="flex items-center gap-2 py-12 text-sm text-muted-foreground"
        role="status"
        aria-live="polite"
      >
        <Spinner className="size-4" aria-hidden />
        {t("shop.payments.state.loading")}
      </div>
    );
  }

  if (state.kind === "unauthorized") {
    return (
      <Alert
        data-slot="payments-view-panel"
        data-testid={paymentsStateTestId("unauthorized")}
        variant="destructive"
        role="alert"
      >
        <Lock className="size-4" aria-hidden />
        <AlertTitle>{t("shop.payments.state.unauthorized_title")}</AlertTitle>
        <AlertDescription>{t("shop.payments.state.unauthorized_desc")}</AlertDescription>
      </Alert>
    );
  }

  if (state.kind === "forbidden") {
    return (
      <Alert
        data-slot="payments-view-panel"
        data-testid={paymentsStateTestId("forbidden")}
        variant="destructive"
        role="alert"
      >
        <ShieldAlert className="size-4" aria-hidden />
        <AlertTitle>{t("shop.payments.state.forbidden_title")}</AlertTitle>
        <AlertDescription>{t("shop.payments.state.forbidden_desc")}</AlertDescription>
      </Alert>
    );
  }

  if (state.kind === "prerequisite") {
    return (
      <div
        data-slot="payments-view-panel"
        data-testid={paymentsStateTestId("prerequisite")}
        className="flex flex-col items-start gap-4 rounded-lg border border-dashed bg-muted/30 p-8"
        role="region"
        aria-labelledby="payments-prerequisite-title"
      >
        <AlertCircle className="size-8 text-muted-foreground" aria-hidden />
        <div className="space-y-1">
          <h3 id="payments-prerequisite-title" className="text-base font-semibold">
            {t("shop.payments.state.prerequisite_title")}
          </h3>
          <p className="max-w-prose text-sm text-muted-foreground">
            {t("shop.payments.state.prerequisite_desc")}
          </p>
        </div>
        {setupHref && (
          <Button asChild size="sm">
            <Link href={setupHref}>{t("shop.payments.state.prerequisite_action")}</Link>
          </Button>
        )}
      </div>
    );
  }

  if (state.kind === "empty") {
    return (
      <div
        data-slot="payments-view-panel"
        data-testid={paymentsStateTestId("empty")}
        className="py-12 text-center text-sm text-muted-foreground"
        role="status"
      >
        {t("shop.payments.state.empty")}
      </div>
    );
  }

  if (state.kind === "transient") {
    return (
      <Alert
        data-slot="payments-view-panel"
        data-testid={paymentsStateTestId("transient")}
        role="alert"
      >
        <WifiOff className="size-4" aria-hidden />
        <AlertTitle>{t("shop.payments.state.transient_title")}</AlertTitle>
        <AlertDescription className="flex flex-wrap items-center gap-3">
          <span>{t("shop.payments.state.transient_desc")}</span>
          {onRetry && (
            <Button variant="outline" size="sm" onClick={onRetry}>
              <RefreshCw className="mr-1.5 size-3.5" aria-hidden />
              {t("common.retry")}
            </Button>
          )}
        </AlertDescription>
      </Alert>
    );
  }

  if (state.kind === "provider_action") {
    return (
      <Alert
        data-slot="payments-view-panel"
        data-testid={paymentsStateTestId("provider_action")}
        role="alert"
      >
        <AlertCircle className="size-4" aria-hidden />
        <AlertTitle>{t("shop.payments.state.provider_action_title")}</AlertTitle>
        <AlertDescription className="flex flex-wrap items-center gap-3">
          <span>{t("shop.payments.state.provider_action_desc")}</span>
          {onProviderAction && (
            <Button size="sm" onClick={onProviderAction}>
              {t("shop.payments.state.provider_action_button")}
            </Button>
          )}
        </AlertDescription>
      </Alert>
    );
  }

  if (state.kind === "error") {
    const isConflict = state.statusCode === 409;
    return (
      <Alert
        data-slot="payments-view-panel"
        data-testid={paymentsStateTestId("error")}
        variant="destructive"
        role="alert"
      >
        <AlertCircle className="size-4" aria-hidden />
        <AlertTitle>
          {isConflict
            ? t("shop.payments.state.conflict_title")
            : t("shop.payments.state.error_title")}
        </AlertTitle>
        <AlertDescription className="flex flex-wrap items-center gap-3">
          <span>
            {isConflict
              ? t("shop.payments.state.conflict_desc")
              : t("shop.payments.state.error_desc")}
          </span>
          {onRetry && (
            <Button variant="outline" size="sm" onClick={onRetry}>
              {t("common.retry")}
            </Button>
          )}
        </AlertDescription>
      </Alert>
    );
  }

  return (
    <div data-slot="payments-view-panel" data-testid={paymentsStateTestId("data")}>
      {state.isStale && (
        <p className="mb-3 text-xs text-muted-foreground" role="status" aria-live="polite">
          {t("shop.payments.state.refreshing")}
        </p>
      )}
      {children}
    </div>
  );
}
