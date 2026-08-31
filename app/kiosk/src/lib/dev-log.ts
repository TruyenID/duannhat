/**
 * Debug tracing that does not survive a release build.
 *
 * `error-reporter.ts` states the fact this exists for: "babel-preset-expo does
 * NOT strip console in production, so this call survives." That is deliberate
 * for `console.error` — a breadcrumb in the device log when Sentry is offline is
 * worth having. It is not what you want for request/response tracing.
 *
 * Fourteen `console.log` calls were writing payment request bodies, raw order
 * payloads, split-bill previews and a table's QR token into the production
 * device log. A kiosk sits in a dining room; anyone who can reach its USB port
 * can read that log with `adb logcat`. The QR token in particular is a session
 * credential — it is what identifies a table's order to the customer app.
 *
 * `__DEV__` is a compile-time constant, so Metro removes these blocks entirely
 * from a release bundle rather than merely skipping them at runtime. Nothing new
 * is added to package.json to achieve that.
 *
 * For anything that should reach production, use `reportError` — that is the
 * deliberate breadcrumb path.
 */
export function devLog(...args: unknown[]): void {
  if (__DEV__) {
    // The one sanctioned console.log in the app (#1259). It is unreachable in a
    // production bundle — __DEV__ is compile-time, so the call is stripped —
    // which is precisely why every other console.log has to go through here
    // rather than being written inline.
    // eslint-disable-next-line no-console
    console.log(...args);
  }
}
