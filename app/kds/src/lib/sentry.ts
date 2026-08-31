import * as Sentry from "@sentry/react";

/**
 * Sentry initialisation for godx-kds.
 *
 * Activated only when `VITE_SENTRY_DSN` is set at build time. The DSN is
 * env-driven so dev builds + tests don't ship breadcrumbs to a real
 * Sentry project, and each restaurant / staging tier can point at a
 * separate Sentry org without code changes.
 *
 * Privacy posture (KDS = kitchen device, no end-customer PII):
 *  - `sendDefaultPii: false` — no IP addresses or browser cookies.
 *  - `replayIntegration` not included — Sentry Replay would record
 *    the order list (table number + dish name) which counts as customer
 *    data. Add only if a replay-DSN is opt-in per branch.
 *  - `beforeBreadcrumb` redacts `localStorage` values from console
 *    breadcrumbs so the device_token does not leak into Sentry events
 *    when a future debug log enumerates storage.
 *
 * The DSN itself is technically a secret-light identifier (lets anyone
 * write to the project); it's NOT a server-side secret. Embedding into
 * the production bundle is the documented Sentry pattern.
 */
export function initSentry(): void {
  const dsn = import.meta.env.VITE_SENTRY_DSN as string | undefined;
  if (!dsn) {
    // Dev / unit tests / a deploy that hasn't been wired yet. Silent
    // no-op so a missing env var doesn't block boot.
    return;
  }

  Sentry.init({
    dsn,
    environment: import.meta.env.MODE,
    release: import.meta.env.VITE_RELEASE as string | undefined,
    sendDefaultPii: false,
    // Lower than default (1.0) — tablet runs 24/7, full sampling would
    // blow the Sentry quota for what's mostly noise.
    tracesSampleRate: 0.05,
    integrations: [
      Sentry.browserTracingIntegration(),
    ],
    beforeBreadcrumb(breadcrumb) {
      // Console breadcrumbs can quote localStorage values when debug
      // code enumerates storage. Strip the token field if it shows up.
      // Covers BOTH quoted forms (`token: "abc"`) AND unquoted shells
      // (`token: abc` from console.log object printing).
      if (breadcrumb.category === "console" && typeof breadcrumb.message === "string") {
        breadcrumb.message = breadcrumb.message
          .replace(/(kds_device_token['"]?\s*[:=]\s*['"])[^'"]+(['"])/gi, "$1<redacted>$2")
          .replace(/(kds_device_token['"]?\s*[:=]\s*)([^\s,;}'"]+)/gi, "$1<redacted>")
          .replace(/Authorization\s*:\s*Bearer\s+[A-Za-z0-9._-]+/gi, "Authorization: Bearer <redacted>");
      }
      return breadcrumb;
    },
    beforeSend(event) {
      // Exception messages + componentStack are the leak surface that
      // beforeBreadcrumb misses. Walk Sentry's event shape + scrub.
      const scrub = (s: string) =>
        s
          .replace(/(kds_device_token['"]?\s*[:=]\s*['"]?)[^\s,;}'"]+/gi, "$1<redacted>")
          .replace(/Authorization\s*:\s*Bearer\s+[A-Za-z0-9._-]+/gi, "Authorization: Bearer <redacted>");
      if (event.exception?.values) {
        for (const ex of event.exception.values) {
          if (ex.value) ex.value = scrub(ex.value);
        }
      }
      if (event.extra && typeof event.extra.componentStack === "string") {
        event.extra.componentStack = scrub(event.extra.componentStack);
      }
      if (event.contexts?.react && typeof event.contexts.react.componentStack === "string") {
        event.contexts.react.componentStack = scrub(event.contexts.react.componentStack);
      }
      if (event.message) event.message = scrub(event.message);
      return event;
    },
  });
}

/**
 * Re-export a few common surfaces so callers don't import sentry/react
 * directly + can be mocked in one place during tests.
 */
export const captureException = Sentry.captureException;
export const captureMessage = Sentry.captureMessage;
export const addBreadcrumb = Sentry.addBreadcrumb;
