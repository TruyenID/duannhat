/** Normalize operator / mDNS workstation base URLs to `http(s)://host[:port]`. */
export function normalizeBaseUrl(raw: string): string {
  const trimmed = raw.trim().replace(/\/+$/, "");
  if (!trimmed) return "";
  if (/^https?:\/\//i.test(trimmed)) return trimmed;
  return `http://${trimmed}`;
}

export function posUrlFromBase(baseUrl: string): string {
  return `${normalizeBaseUrl(baseUrl)}/pos`;
}

/**
 * True when `url` lives under the workstation we were told to open.
 *
 * The WebView is the whole product on a locked-down tablet: back gestures are
 * off and there is no address bar, so a navigation that leaves the workstation
 * strands the terminal on a page the cashier cannot escape. The boundary must
 * be a real one — a bare `startsWith(base)` would accept
 * `http://192.168.1.10:8080.evil.example`, so the next character has to be a
 * path/query/fragment separator (or the URL must be exactly the base).
 *
 * Scheme and host are case-insensitive; the comparison lowercases both sides,
 * which is safe because `base` never carries a path.
 */
export function isWithinBase(url: string, baseUrl: string): boolean {
  const base = normalizeBaseUrl(baseUrl).toLowerCase();
  if (!base) return false;
  const target = url.trim().toLowerCase();
  if (target === base) return true;
  return ["/", "?", "#"].some((sep) => target.startsWith(base + sep));
}

/**
 * Descending comparator for the version strings mDNS advertises. Numeric
 * segment by segment, because a plain string compare puts `0.9.0` ahead of
 * `0.10.0` — the setup list would offer an older workstation first every time
 * a shop crossed a x.10 release.
 */
export function compareVersionDesc(a: string, b: string): number {
  const pa = a.split(".");
  const pb = b.split(".");
  for (let i = 0; i < Math.max(pa.length, pb.length); i += 1) {
    // parseInt, not Number: "4a" (a legacy date tag suffix) must read as 4
    // rather than collapse to NaN and drop the whole comparison to 0.
    const na = parseInt(pa[i] ?? "0", 10);
    const nb = parseInt(pb[i] ?? "0", 10);
    const va = Number.isNaN(na) ? 0 : na;
    const vb = Number.isNaN(nb) ? 0 : nb;
    if (va !== vb) return vb - va;
  }
  return 0;
}
