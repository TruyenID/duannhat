import { captureException } from "./sentry";

/**
 * Centralised error reporting for kiosk runtime failures.
 *
 * Forwards to:
 *  - Sentry via `captureException` with the scope tag — no-op when
 *    `EXPO_PUBLIC_SENTRY_DSN` is unset (dev / tests / unwired deploys).
 *  - `console.error` so dev still sees the stack and prod device logs
 *    keep a breadcrumb even when Sentry is offline. babel-preset-expo
 *    does NOT strip console in production, so this call survives.
 *
 * Use a kebab-case scope tag that uniquely identifies the failure site
 * (e.g. `payment-init-card`, `qr-resolve`, `receipt-print`). Sentry
 * groups issues by the `scope` tag.
 */
export function reportError(scope: string, err: unknown): void {
  // Sentry first so a downstream console-write that throws (rare but
  // possible on some RN dev environments) doesn't lose the report.
  captureException(err, { tags: { scope } });
  console.error(`[kiosk:${scope}]`, err);
}

/**
 * Convenience for sites where you have additional context to attach.
 * Sentry's `extra` block keeps the context structured + searchable.
 */
export function reportErrorWithContext(
  scope: string,
  err: unknown,
  context: Record<string, unknown>,
): void {
  captureException(err, { tags: { scope }, extra: context });
  console.error(`[kiosk:${scope}]`, err, context);
}
