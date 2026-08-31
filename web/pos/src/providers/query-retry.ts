import { ApiError } from "@/lib/api";

export const MAX_RETRIES = 3;
export const BASE_DELAY_MS = 500;
export const MAX_DELAY_MS = 4000;

/**
 * Retry policy for read-only React Query queries.
 *
 * Skip 4xx — these are authoritative responses (validation, auth, conflict).
 * Retrying won't change the result.
 * Retry 5xx and network errors (fetch throws TypeError / AbortError on
 * connectivity issues) up to MAX_RETRIES times.
 *
 * Mutations use shouldRetryMutation below. A browser timeout does not prove
 * the workstation failed before commit, so replaying writes here can duplicate
 * an order action or send the same action to another tier.
 */
export function shouldRetryQuery(failureCount: number, error: unknown): boolean {
  if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
    return false;
  }
  return failureCount < MAX_RETRIES;
}

/** Writes are retried only by an explicit domain flow after reconciliation. */
export function shouldRetryMutation(): false {
  return false;
}

/** Exponential backoff: 500ms → 1s → 2s → 4s cap. */
export function retryDelay(attemptIndex: number): number {
  return Math.min(BASE_DELAY_MS * 2 ** attemptIndex, MAX_DELAY_MS);
}
