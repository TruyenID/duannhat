import { SUPPORTED_LOCALES } from "@/types/models/payload-helpers";

/**
 * Fill empty locales with the first non-empty value in priority order
 * (ja → en → vi → …).
 *
 * Happy-path: user fills all locales → each keeps its own value.
 * Partial-fill: empty locales are set to the best available value so the
 * backend never stores an explicit empty string that breaks Astrotomic
 * fallback display.
 *
 * Example: user fills { ja: "白ソース", vi: "Sốt trắng" }
 * → returns  { ja: "白ソース", en: "白ソース", vi: "Sốt trắng" }
 */
export function fillLocalesFallback(map: Record<string, string>): Record<string, string> {
  const fallback = SUPPORTED_LOCALES.map((l) => map[l]?.trim()).find((v) => v) ?? "";
  return Object.fromEntries(SUPPORTED_LOCALES.map((l) => [l, map[l]?.trim() || fallback]));
}
