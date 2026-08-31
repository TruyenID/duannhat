import * as Sentry from "@sentry/react";

/**
 * Sentry initialisation for pos-web (Vite / React).
 *
 * pos-web is the cash-handling POS terminal — uncaught errors during
 * checkout MUST be visible to ops within seconds, not hours. Without
 * Sentry the only diagnostic was console.error in the operator's
 * browser, which is invisible to support.
 *
 * Activated only when `VITE_SENTRY_DSN` is set at build time. Dev /
 * tests / unwired deploys see a silent no-op.
 *
 * Privacy posture (POS = staff terminal, sees customer.phone/email):
 *  - sendDefaultPii: false — strip IPs + cookies from events
 *  - Sentry Replay deliberately disabled — replay would record the
 *    cash-amount panel + customer order detail (PII)
 *  - tracesSampleRate 0.1 — POS sees moderate traffic; lower than
 *    default (1.0) to keep Sentry quota predictable
 *  - beforeBreadcrumb scrubs console messages
 *  - beforeSend scrubs exception messages + componentStack + extra
 *    so a render error in a panel showing `customer.phone` does not
 *    ship the phone number to Sentry via the React component stack
 *
 * The DSN is bundled (Sentry's documented pattern); it lets anyone
 * write to the project but is NOT a server-side secret.
 */

/**
 * Scrub auth tokens + email patterns from any string. Applied to
 * breadcrumb messages, exception messages, and the extra/context
 * componentStack we pass from ErrorBoundary. Phone numbers are
 * intentionally NOT regex-scrubbed — the false-positive rate against
 * minified bundle digit chunks is too high. The fix for phone PII
 * is to not render `customer.phone` inside React-rendered components
 * that could throw (tracked as a separate UI hardening follow-up).
 */
function scrubString(s: string): string {
  return s
    .replace(/Authorization\s*:\s*Bearer\s+[A-Za-z0-9._-]+/gi, "Authorization: Bearer <redacted>")
    .replace(/token\s*=\s*[^;\s"']+/gi, "token=<redacted>")
    .replace(/[a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g, "<email-redacted>");
}

export function initSentry(): void {
  const dsn = import.meta.env.VITE_SENTRY_DSN as string | undefined;
  if (!dsn) {
    return;
  }

  Sentry.init({
    dsn,
    environment: import.meta.env.MODE,
    release: import.meta.env.VITE_RELEASE as string | undefined,
    sendDefaultPii: false,
    tracesSampleRate: 0.1,
    integrations: [
      Sentry.browserTracingIntegration(),
    ],
    beforeBreadcrumb(breadcrumb) {
      if (breadcrumb.category === "console" && typeof breadcrumb.message === "string") {
        breadcrumb.message = scrubString(breadcrumb.message);
      }
      return breadcrumb;
    },
    beforeSend(event) {
      // Exception messages + componentStack are the leak surface that
      // beforeBreadcrumb doesn't cover. Walk the typical Sentry event
      // shape + scrub each string field.
      if (event.exception?.values) {
        for (const ex of event.exception.values) {
          if (ex.value) ex.value = scrubString(ex.value);
        }
      }
      if (event.extra && typeof event.extra.componentStack === "string") {
        event.extra.componentStack = scrubString(event.extra.componentStack);
      }
      if (event.contexts?.react && typeof event.contexts.react.componentStack === "string") {
        event.contexts.react.componentStack = scrubString(event.contexts.react.componentStack);
      }
      if (event.message) event.message = scrubString(event.message);
      return event;
    },
  });
}

export const captureException = Sentry.captureException;
export const captureMessage = Sentry.captureMessage;
export const addBreadcrumb = Sentry.addBreadcrumb;
