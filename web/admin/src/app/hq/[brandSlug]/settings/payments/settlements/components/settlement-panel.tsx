"use client";

import type { ReactNode } from "react";
import { AlertCircle } from "lucide-react";
import { Alert, AlertDescription, AlertTitle, Button } from "@godxjp/ui";

import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import {
  paymentsStateTestId,
  resolvePaymentsViewState,
  type PaymentsViewState,
} from "@/app/shop/[shopSlug]/settings/payments/lib/payments-view-state";
import { useTranslation } from "@/providers/app-provider";

export interface SettlementPanelProps {
  isLoading: boolean;
  isFetching?: boolean;
  isError: boolean;
  error?: unknown;
  /** A successful response arrived (even if it carried zero rows). */
  hasData: boolean;
  /** Successful response with zero rows. */
  isEmpty: boolean;
  columns: number;
  onRetry: () => void;
  /** The table. Rendered for both the empty and the populated state. */
  children: ReactNode;
}

/**
 * One panel, one state — for a settlement table (#1157).
 *
 * THE POINT OF THIS COMPONENT IS THAT "NO ROWS" AND "COULD NOT LOAD" LOOK
 * DIFFERENT. On a reconciliation screen those two mean opposite things: an
 * empty batch list says the provider sent nothing today, a failed request says
 * we do not know what the provider sent. If both render as a blank table, an
 * accountant closes the month on the second one believing it was the first.
 *
 * So: an error gets a destructive Alert plus a retry; empty gets the table's own
 * muted "no rows" line and no red anywhere. Red is reserved for real failure.
 *
 * The state machine is `resolvePaymentsViewState` from the shop payment
 * settings screens — already written, already covered by
 * `src/__tests__/payments-view-state.test.ts`. Rebuilding a second one for HQ
 * would be a second thing to drift.
 */
export function SettlementPanel({
  isLoading,
  isFetching,
  isError,
  error,
  hasData,
  isEmpty,
  columns,
  onRetry,
  children,
}: SettlementPanelProps) {
  const { t } = useTranslation();

  const state: PaymentsViewState = resolvePaymentsViewState({
    isLoading,
    isFetching,
    isError,
    error,
    hasData,
    isEmpty,
  });

  if (state.kind === "loading") {
    return (
      <div data-slot="settlement-panel" data-testid={paymentsStateTestId(state.kind)}>
        <DataTableSkeleton columns={columns} />
      </div>
    );
  }

  if (
    state.kind === "error" ||
    state.kind === "transient" ||
    state.kind === "forbidden" ||
    state.kind === "unauthorized"
  ) {
    const messageKey =
      state.kind === "forbidden" || state.kind === "unauthorized"
        ? "hq.settlements.error.forbidden"
        : "hq.settlements.error.load_failed";

    return (
      <div data-slot="settlement-panel" data-testid={paymentsStateTestId(state.kind)}>
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertTitle>{t("hq.settlements.error.title")}</AlertTitle>
          <AlertDescription>
            <span>{t(messageKey)}</span>
            {state.statusCode ? (
              <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                {`HTTP ${state.statusCode}${state.errorCode ? ` · ${state.errorCode}` : ""}`}
              </code>
            ) : null}
            <Button variant="outline" size="sm" className="mt-2 h-7 text-xs" onClick={onRetry}>
              {t("common.retry")}
            </Button>
          </AlertDescription>
        </Alert>
      </div>
    );
  }

  return (
    <div data-slot="settlement-panel" data-testid={paymentsStateTestId(state.kind)}>
      {children}
    </div>
  );
}
