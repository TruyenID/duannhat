/**
 * buildDineInUrl — the customer-facing dine-in URL encoded into a table QR.
 *
 * Shared by `<TableQr>` (the small card preview) and `<TableQrPoster>` (the
 * printable A7 poster) so both encode exactly the same payload. The backend
 * does NOT render QR images — it only exposes the raw `qr_token`; the frontend
 * builds the full URL and renders the QR client-side.
 *
 * Resolution order — first usable candidate wins:
 *
 *   1. `runtimeUrl` — `CUSTOMER_WEB_URL`, read per request in the root layout.
 *      The ONLY thing that may re-point a production QR. Deliberately not a
 *      `NEXT_PUBLIC_*` name: those are inlined at build time, so they cannot
 *      follow a domain change. Set it and a restart is enough — no rebuild.
 *   2. Production stops here → the canonical customer domain.
 *   3. Outside production only: `configuredUrl`
 *      (`NEXT_PUBLIC_CUSTOMER_WEB_URL`), then the current browser origin.
 *
 * Why production ignores the build-time variable (umbrella issue #1004): a
 * stale Amplify environment variable once leaked `*.amplifyapp.com` into
 * PRINTED QR codes, and paper cannot be re-deployed. A deploy console anyone
 * can edit is not a safe source for something that goes on a sticker, so
 * production only honours a variable set deliberately on the server itself.
 *
 * The previous version enforced that by ignoring the environment entirely in
 * production, which also made the QR domain unchangeable without a code edit —
 * the reason a staging table QR sent guests to the production customer site.
 * Tier 1 restores that control without re-opening #1004; hosts ending in
 * `.amplifyapp.com` stay refused at every tier as belt-and-braces.
 */
export const PRODUCTION_CUSTOMER_WEB_URL = "https://menu.vietorigin.jp";

/**
 * Hostnames that must never end up on printed paper. Suffix match, so it
 * covers every branch/app subdomain Amplify mints.
 */
const NEVER_PRINTED_HOST_SUFFIXES = [".amplifyapp.com"];

/**
 * Normalise a candidate base URL, or return null when it must not be used.
 * A relative / malformed value is refused rather than silently producing a
 * QR that only resolves from inside admin-web.
 */
function usableBaseUrl(candidate: string | undefined): string | null {
  const trimmed = candidate?.trim().replace(/\/+$/, "");
  if (!trimmed) {
    return null;
  }

  let hostname: string;
  try {
    hostname = new URL(trimmed).hostname.toLowerCase();
  } catch {
    return null;
  }

  if (NEVER_PRINTED_HOST_SUFFIXES.some((suffix) => hostname.endsWith(suffix))) {
    return null;
  }

  return trimmed;
}

export interface CustomerWebBaseUrlOptions {
  /** `CUSTOMER_WEB_URL` handed down from the server at request time. */
  runtimeUrl?: string;
  /** `NEXT_PUBLIC_CUSTOMER_WEB_URL`, inlined at build time. */
  configuredUrl?: string;
  runtimeOrigin?: string;
  nodeEnv?: string;
}

export function resolveCustomerWebBaseUrl({
  runtimeUrl,
  configuredUrl,
  runtimeOrigin = "",
  nodeEnv,
}: CustomerWebBaseUrlOptions): string {
  const runtime = usableBaseUrl(runtimeUrl);
  if (runtime) {
    return runtime;
  }

  // Production deliberately stops before the build-time variable — see #1004
  // in the module docblock. Without CUSTOMER_WEB_URL this is byte-for-byte the
  // pre-existing behaviour, so shipping this change re-points nothing until a
  // server opts in.
  if (nodeEnv === "production") {
    return PRODUCTION_CUSTOMER_WEB_URL;
  }

  return (
    usableBaseUrl(configuredUrl) ??
    usableBaseUrl(runtimeOrigin) ??
    PRODUCTION_CUSTOMER_WEB_URL
  );
}

export function buildDineInUrl(
  shopSlug: string,
  qrToken: string,
  runtimeUrl?: string,
): string {
  const base = resolveCustomerWebBaseUrl({
    runtimeUrl,
    configuredUrl: process.env.NEXT_PUBLIC_CUSTOMER_WEB_URL,
    runtimeOrigin: typeof window !== "undefined" ? window.location.origin : "",
    nodeEnv: process.env.NODE_ENV,
  });

  return `${base.replace(/\/+$/, "")}/dine-in/${shopSlug}/table/${qrToken}`;
}
