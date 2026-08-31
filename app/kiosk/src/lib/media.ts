import { CLOUD_URL } from "../services/workstation/base-url-resolver";
import type { Order } from "../types/kiosk";

/**
 * The backend serialises product images as object-store URLs
 * (`http://localhost:5490/tempo/<path>` — MinIO on the API server's localhost).
 * A browser client rewrites those to a same-origin `/s3/...` path its own web
 * server proxies (see scripts/ensure-image-proxy.mjs in the umbrella), but the
 * kiosk is a NATIVE app with no server to proxy through and can't reach the API
 * host's localhost. It only reaches the backend via the API tunnel, so rewrite
 * the MinIO URL to the same-origin backend media proxy (GET /api/v1/media/<path>)
 * which streams the same bytes over that reachable origin.
 *
 * No-op for anything else: a real S3/CDN host in production never matches, so
 * the URL passes through untouched.
 */
const MINIO_PREFIX_RE = /^https?:\/\/localhost:5490\/tempo\//i;

export function resolveMediaUrl(url: string | null | undefined): string | undefined {
  if (!url) {
    return undefined;
  }
  if (MINIO_PREFIX_RE.test(url)) {
    return url.replace(MINIO_PREFIX_RE, `${CLOUD_URL}/api/v1/media/`);
  }
  return url;
}

/**
 * Rewrite every line-item image URL on an order through {@link resolveMediaUrl}
 * so the bill / order-summary / split-bill sidebar all render reachable images.
 * Returns the same order reference (items mutated in place) for call-site
 * convenience.
 */
export function normalizeOrderImages<T extends Order | null | undefined>(order: T): T {
  if (!order) {
    return order;
  }
  for (const item of order.items ?? []) {
    item.image_url = resolveMediaUrl(item.image_url);
  }
  return order;
}
