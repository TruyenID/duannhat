/**
 * Production browser traffic must stay same-origin so Amplify can reverse
 * proxy `/api/*` to Tempo. Server rendering may still use the configured
 * absolute origin, while local development keeps its explicit API URL.
 */
export function resolveApiBase(
  configuredUrl: string | undefined,
  environment: string | undefined,
  isBrowser: boolean,
): string {
  if (environment === "production" && isBrowser) {
    return "";
  }

  return configuredUrl || "http://localhost:5400";
}
