import * as Sentry from "@sentry/react";

/**
 * Sentry initialisation for the workstation Wails frontend.
 *
 * The frontend runs INSIDE Wails' webview, served from
 * http://localhost:8080 — there is no external network surface, but
 * Sentry breadcrumbs still go to the cloud project so HQ ops can see
 * a fleet view across all workstations.
 *
 * Activated only when `VITE_SENTRY_DSN` is set at build time. Dev /
 * tests / unwired deploys see a silent no-op so a missing env var
 * doesn't fail boot inside the Wails webview.
 *
 * Privacy posture:
 *  - sendDefaultPii: false — strip user-agent / IP
 *  - Sentry Replay disabled — workstation displays full order detail
 *    + payment receipts which contain customer data
 *  - tracesSampleRate 0.1 — moderate traffic per workstation
 *  - beforeBreadcrumb strips Authorization headers so the device-
 *    token used for cloud sync doesn't leak into Sentry events
 *
 * To wire a Sentry DSN, set `VITE_SENTRY_DSN` in the Wails build
 * environment + rebuild. The DSN is bundled into the embedded assets.
 */
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
        breadcrumb.message = breadcrumb.message.replace(
          /Authorization\s*:\s*Bearer\s+[A-Za-z0-9._-]+/gi,
          "Authorization: Bearer <redacted>",
        );
      }
      return breadcrumb;
    },
    beforeSend(event) {
      // Exception messages + componentStack are the secondary leak
      // surface that beforeBreadcrumb misses. Workstation FE sees order
      // detail + payment receipts (PII-adjacent) so we scrub both.
      const scrub = (s: string) =>
        s.replace(/Authorization\s*:\s*Bearer\s+[A-Za-z0-9._-]+/gi, "Authorization: Bearer <redacted>");
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

export const captureException = Sentry.captureException;
export const captureMessage = Sentry.captureMessage;
export const addBreadcrumb = Sentry.addBreadcrumb;
