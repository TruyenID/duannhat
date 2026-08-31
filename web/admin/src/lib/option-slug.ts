/**
 * Slug helpers — two shapes, because the backend enforces two different
 * alphabets:
 *
 * 1. Option `key` / option value `value` — `^[a-z0-9_]+$`, UNDERSCORE-joined.
 *    Use `slugifyAscii` / `toOptionSlug`.
 * 2. Resource slugs (product / shop / category) — HYPHEN-joined. Use
 *    `slugifyHyphen` / `toResourceSlug`. Shops are the strict one:
 *    `HQ/StoreShopRequest.php:21-28` and `HQ/UpdateShopRequest.php:24-32`
 *    require `regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/` — **an underscore is
 *    rejected**, so `toOptionSlug`'s `<prefix>_<hash>` fallback must not be
 *    reused here. Products (`ProductStoreRequest.php:61-66`,
 *    `ProductUpdateRequest.php:51-57`) and categories
 *    (`Omnify/Modules/Category/Requests/CategoryStoreRequestBase.php:63`) only
 *    constrain length + uniqueness, so the hyphen shape satisfies all three.
 *
 * Both shapes exist for the same reason. A plain slugify strips every non-ASCII
 * character, which yields an EMPTY string for a fully Japanese label (`量`,
 * `サイズ`, `東京店`, `唐揚げ` — kanji and katakana have no NFD decomposition to
 * ASCII). Laravel treats `""` as absent, so `required` fired and every Japanese
 * option failed with "The key field is required." (#2097), and every Japanese
 * shop failed the same way on `slug` (#2263). These helpers guarantee a
 * non-empty, format-valid slug for any input the user can type.
 */

/** Strip accents and punctuation down to `[a-z0-9_]`. May return `""`. */
export function slugifyAscii(raw: string | null | undefined): string {
  return (raw ?? "")
    .trim()
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9\s]/g, "")
    .replace(/\s+/g, "_")
    .replace(/_+/g, "_")
    .replace(/^_+|_+$/g, "");
}

/**
 * Deterministic ASCII suffix derived from the raw label, so two different
 * Japanese names never collapse onto the same fallback key (`量` and `色` must
 * not both become `option_1`). Non-cryptographic FNV-1a — we only need spread.
 */
function labelHash(raw: string): string {
  let hash = 0x811c9dc5;
  for (let i = 0; i < raw.length; i += 1) {
    hash ^= raw.charCodeAt(i);
    hash = Math.imul(hash, 0x01000193) >>> 0;
  }
  return hash.toString(36);
}

/**
 * Slug that is guaranteed non-empty and `^[a-z0-9_]+$`-valid.
 *
 * ASCII-ish labels keep their readable slug (`Size` → `size`); labels that
 * slugify to nothing fall back to `<prefix>_<hash-of-label>`, which stays
 * stable for the same label and distinct across different ones.
 */
export function toOptionSlug(raw: string | null | undefined, prefix = "option"): string {
  const ascii = slugifyAscii(raw);
  if (ascii) return ascii;

  const source = (raw ?? "").trim();
  if (!source) return "";

  return `${prefix}_${labelHash(source)}`;
}

/**
 * Hyphen-joined counterpart of `slugifyAscii`, for product / shop / category
 * slugs. Existing hyphens in the input survive; everything else outside
 * `[a-z0-9]` is dropped after diacritic folding. May return `""` — that is the
 * right answer while the user is hand-editing a slug field (see
 * `toResourceSlug` for the auto-derive path).
 */
export function slugifyHyphen(raw: string | null | undefined): string {
  return (raw ?? "")
    .trim()
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/[^a-z0-9\s-]/g, "")
    .replace(/\s+/g, "-")
    .replace(/-+/g, "-")
    .replace(/^-+|-+$/g, "");
}

/**
 * Resource slug that is guaranteed non-empty and matches the strictest backend
 * rule in play — the shop one, `^[a-z0-9]+(?:-[a-z0-9]+)*$`.
 *
 * ASCII-ish names keep their readable slug (`Tokyo Store` → `tokyo-store`);
 * a fully Japanese name (`東京店`, `唐揚げ`) slugifies to nothing and falls back
 * to `<prefix>-<hash-of-name>`, stable for the same name and distinct across
 * different ones. The hash is base36, i.e. `[0-9a-z]`, so the joined result
 * still satisfies the shop regex.
 *
 * Use this only where the slug is DERIVED from a name. Hand-edited slug inputs
 * must use `slugifyHyphen`, otherwise a half-typed Japanese name would be
 * replaced under the cursor by a hash.
 */
export function toResourceSlug(raw: string | null | undefined, prefix: string): string {
  const ascii = slugifyHyphen(raw);
  if (ascii) return ascii;

  const source = (raw ?? "").trim();
  if (!source) return "";

  return `${prefix}-${labelHash(source)}`;
}
