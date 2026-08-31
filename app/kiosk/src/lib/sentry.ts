import * as Sentry from "@sentry/react-native";

/**
 * Sentry initialisation for godx-kiosk (Expo / React Native).
 *
 * Activated only when `EXPO_PUBLIC_SENTRY_DSN` is set at build time.
 * Dev / tests / unwired deploys see a silent no-op so a missing env
 * var doesn't crash boot.
 *
 * Privacy posture (kiosk = customer-facing payment terminal):
 *  - `sendDefaultPii: false` — no device IP, no user agent stripping.
 *  - `tracesSampleRate: 0.05` — 24/7 tablet, full sampling kills quota.
 *  - `beforeBreadcrumb` redacts any console message that looks like it
 *    contains the device token. Console.log is NOT stripped in our
 *    production build (babel-preset-expo default), so payment-flow
 *    debug logs could otherwise carry the token into Sentry events.
 *  - PCI-DSS posture: Sentry sees payment_id (opaque UUID) but never
 *    PAN, cryptograms, or terminal output. Lifecycle audit goes to the
 *    backend `/api/v1/kiosk/audit-logs` endpoint, NOT Sentry.
 */
export function initSentry(): void {
  const dsn = process.env.EXPO_PUBLIC_SENTRY_DSN;
  if (!dsn) {
    return;
  }

  Sentry.init({
    dsn,
    // Without environment + release Sentry conflates dev/staging/prod
    // events under one bucket — issue triage becomes guesswork. Read
    // both from Expo's public env vars so a kiosk build can be tagged
    // at CI time.
    environment: process.env.EXPO_PUBLIC_SENTRY_ENV ?? process.env.NODE_ENV,
    release: process.env.EXPO_PUBLIC_RELEASE,
    sendDefaultPii: false,
    tracesSampleRate: 0.05,
    // Sentry RN has its own ReplayIntegration; deliberately NOT
    // included — replay would record customer-facing screens including
    // the cash-amount keypad, which is PII-adjacent.
    beforeBreadcrumb(breadcrumb) {
      if (breadcrumb.category === "console" && typeof breadcrumb.message === "string") {
        breadcrumb.message = breadcrumb.message
          .replace(/device_token['"]?\s*[:=]\s*['"][^'"]+['"]/gi, 'device_token="<redacted>"')
          .replace(/device_token['"]?\s*[:=]\s*([^\s,;}'"]+)/gi, "device_token=<redacted>")
          .replace(/Bearer\s+[A-Za-z0-9._-]+/g, "Bearer <redacted>");
      }
      return breadcrumb;
    },
    beforeSend(event) {
      // Exception messages + componentStack pass through Sentry without
      // touching beforeBreadcrumb — scrub the same auth-token patterns
      // there too so a stack-trace from inside use-payment that
      // happened to interpolate the token doesn't leak.
      const scrub = (s: string) =>
        s
          .replace(/device_token['"]?\s*[:=]\s*['"]?[^\s,;}'"]+/gi, "device_token=<redacted>")
          .replace(/Bearer\s+[A-Za-z0-9._-]+/g, "Bearer <redacted>");
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
