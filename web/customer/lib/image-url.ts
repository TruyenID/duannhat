/**
 * Rewrite a MinIO/S3 image URL so the browser fetches it through this app's OWN
 * origin (/s3/...) instead of the storage host directly. Staging-only; a no-op
 * against a real S3/CDN host (only localhost:5490/tempo URLs match).
 */
export function proxyImageUrl<T extends string | null | undefined>(url: T): T {
  if (!url) return url;
  return url.replace(
    /^https?:\/\/(?:localhost|127\.0\.0\.1):5490\/tempo\//i,
    "/s3/",
  ) as T;
}

const MINIO_URL_RE = /https?:\/\/(?:localhost|127\.0\.0\.1):5490\/tempo\//gi;

/** Deep-rewrite every MinIO URL in a parsed API response to same-origin /s3/. */
export function rewriteMinioUrlsDeep<T>(value: T): T {
  if (typeof value === "string") {
    return value.replace(MINIO_URL_RE, "/s3/") as unknown as T;
  }
  if (Array.isArray(value)) {
    return value.map((v) => rewriteMinioUrlsDeep(v)) as unknown as T;
  }
  if (value && typeof value === "object") {
    const out: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
      out[k] = rewriteMinioUrlsDeep(v);
    }
    return out as unknown as T;
  }
  return value;
}
