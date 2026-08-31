const LAST_BRAND_KEY = "tempo_last_brand_slug";

export function readLastBrandSlug(): string | null {
  if (typeof window === "undefined") return null;

  try {
    return window.localStorage.getItem(LAST_BRAND_KEY);
  } catch {
    return null;
  }
}

export function rememberLastBrandSlug(slug: string): void {
  if (typeof window === "undefined") return;

  try {
    window.localStorage.setItem(LAST_BRAND_KEY, slug);
  } catch {
    // Storage can be unavailable in private/restricted browser contexts.
  }
}
