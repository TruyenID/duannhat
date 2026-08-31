/**
 * G12 — mutually exclusive primary view states for shop payment settings.
 */

import { ApiError } from "@/lib/api";

export type PaymentsViewStateKind =
  | "loading"
  | "forbidden"
  | "unauthorized"
  | "prerequisite"
  | "empty"
  | "error"
  | "transient"
  | "provider_action"
  | "data";

export interface PaymentsViewState {
  kind: PaymentsViewStateKind;
  /** True when refetching but stale data may still be shown. */
  isStale?: boolean;
  statusCode?: number;
  errorCode?: string;
  messageKey?: string;
}

export interface ResolvePaymentsViewStateInput {
  isLoading: boolean;
  isFetching?: boolean;
  isError: boolean;
  error?: unknown;
  hasData: boolean;
  /** Franchise shop with no connection — show setup prerequisite immediately. */
  setupRequired?: boolean;
  /** List section with zero rows after successful load. */
  isEmpty?: boolean;
  /** Provider onboarding / validation action required. */
  providerActionRequired?: boolean;
  providerActionCode?: string;
}

function errorStatus(error: unknown): number | undefined {
  if (error instanceof ApiError) return error.status;
  return undefined;
}

function errorCode(error: unknown): string | undefined {
  if (error instanceof ApiError && error.body && typeof error.body === "object") {
    const body = error.body as Record<string, unknown>;
    if (typeof body.code === "string") return body.code;
    if (typeof body.error === "string") return body.error;
  }
  return undefined;
}

/**
 * Returns exactly one primary state. Callers must render a single panel based on `kind`.
 */
export function resolvePaymentsViewState(input: ResolvePaymentsViewStateInput): PaymentsViewState {
  const {
    isLoading,
    isFetching,
    isError,
    error,
    hasData,
    setupRequired,
    isEmpty,
    providerActionRequired,
    providerActionCode,
  } = input;

  const status = errorStatus(error);
  const code = errorCode(error);

  if (isLoading && !hasData) {
    return { kind: "loading" };
  }

  if (isError && !hasData) {
    if (status === 401) return { kind: "unauthorized", statusCode: 401, errorCode: code };
    if (status === 403) return { kind: "forbidden", statusCode: 403, errorCode: code };
    if (status === 409) return { kind: "error", statusCode: 409, errorCode: code ?? "CONFLICT" };
    if (status === 422) return { kind: "error", statusCode: 422, errorCode: code ?? "VALIDATION" };
    if (code === "GATEWAY_SETUP_REQUIRED" || setupRequired) {
      return { kind: "prerequisite", errorCode: code ?? "GATEWAY_SETUP_REQUIRED" };
    }
    if (providerActionRequired || code === "GATEWAY_ACTION_REQUIRED") {
      return {
        kind: "provider_action",
        errorCode: providerActionCode ?? code ?? "GATEWAY_ACTION_REQUIRED",
      };
    }
    if (status === 408 || status === 504 || status === 503) {
      return { kind: "transient", statusCode: status, errorCode: code };
    }
    return { kind: "error", statusCode: status, errorCode: code };
  }

  if (!hasData && setupRequired) {
    return { kind: "prerequisite", errorCode: "GATEWAY_SETUP_REQUIRED" };
  }

  if (hasData && providerActionRequired) {
    return {
      kind: "provider_action",
      errorCode: providerActionCode ?? "GATEWAY_ACTION_REQUIRED",
      isStale: isFetching,
    };
  }

  if (hasData && isEmpty) {
    return { kind: "empty", isStale: isFetching };
  }

  if (hasData) {
    return { kind: "data", isStale: Boolean(isFetching && !isLoading) };
  }

  return { kind: "empty" };
}

/** Guard for tests — only one of these selectors should match at a time. */
export const PAYMENTS_STATE_TEST_IDS = {
  loading: "payments-state-loading",
  forbidden: "payments-state-forbidden",
  unauthorized: "payments-state-unauthorized",
  prerequisite: "payments-state-prerequisite",
  empty: "payments-state-empty",
  error: "payments-state-error",
  transient: "payments-state-transient",
  providerAction: "payments-state-provider-action",
  data: "payments-state-data",
} as const;

export function paymentsStateTestId(kind: PaymentsViewStateKind): string {
  switch (kind) {
    case "loading":
      return PAYMENTS_STATE_TEST_IDS.loading;
    case "forbidden":
      return PAYMENTS_STATE_TEST_IDS.forbidden;
    case "unauthorized":
      return PAYMENTS_STATE_TEST_IDS.unauthorized;
    case "prerequisite":
      return PAYMENTS_STATE_TEST_IDS.prerequisite;
    case "empty":
      return PAYMENTS_STATE_TEST_IDS.empty;
    case "error":
      return PAYMENTS_STATE_TEST_IDS.error;
    case "transient":
      return PAYMENTS_STATE_TEST_IDS.transient;
    case "provider_action":
      return PAYMENTS_STATE_TEST_IDS.providerAction;
    case "data":
      return PAYMENTS_STATE_TEST_IDS.data;
  }
}
