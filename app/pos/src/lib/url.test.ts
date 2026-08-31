import { describe, expect, it } from "vitest";
import {
  compareVersionDesc,
  isWithinBase,
  normalizeBaseUrl,
  posUrlFromBase,
} from "./url";

/**
 * Everything the shell does hangs off this string: the health probe, the stored
 * pairing, and the WebView URL. An operator types `192.168.1.10:8080` on a
 * tablet keyboard, so trailing slashes, whitespace and a missing scheme are the
 * normal input, not the edge case.
 */
describe("normalizeBaseUrl", () => {
  it("adds the http scheme an operator will not type", () => {
    expect(normalizeBaseUrl("192.168.1.10:8080")).toBe("http://192.168.1.10:8080");
  });

  it("keeps an explicit scheme, either one", () => {
    expect(normalizeBaseUrl("http://ws.local:6969")).toBe("http://ws.local:6969");
    expect(normalizeBaseUrl("https://shop.godx.jp")).toBe("https://shop.godx.jp");
  });

  it("is case-insensitive about the scheme", () => {
    expect(normalizeBaseUrl("HTTP://192.168.1.10")).toBe("HTTP://192.168.1.10");
  });

  it("trims surrounding whitespace from a pasted value", () => {
    expect(normalizeBaseUrl("  192.168.1.10:8080  ")).toBe("http://192.168.1.10:8080");
  });

  it("strips every trailing slash so the /pos join never doubles up", () => {
    expect(normalizeBaseUrl("http://192.168.1.10:8080/")).toBe("http://192.168.1.10:8080");
    expect(normalizeBaseUrl("http://192.168.1.10:8080///")).toBe("http://192.168.1.10:8080");
  });

  it("returns empty for empty / whitespace-only input", () => {
    expect(normalizeBaseUrl("")).toBe("");
    expect(normalizeBaseUrl("   ")).toBe("");
  });

  it("keeps a host without a port (default :80 on the LAN)", () => {
    expect(normalizeBaseUrl("ws-app.local")).toBe("http://ws-app.local");
  });
});

describe("posUrlFromBase", () => {
  it("builds exactly one /pos, whatever the input shape", () => {
    expect(posUrlFromBase("192.168.1.10:8080")).toBe("http://192.168.1.10:8080/pos");
    expect(posUrlFromBase("http://192.168.1.10:8080/")).toBe(
      "http://192.168.1.10:8080/pos",
    );
    expect(posUrlFromBase("  http://192.168.1.10:8080//  ")).toBe(
      "http://192.168.1.10:8080/pos",
    );
  });

  it("does not deduplicate an already-/pos base — callers pass the ROOT", () => {
    // Documented so nobody "fixes" setup.tsx by storing the /pos URL: the
    // health probe and the WebView both derive from the root.
    expect(posUrlFromBase("http://192.168.1.10:8080/pos")).toBe(
      "http://192.168.1.10:8080/pos/pos",
    );
  });
});

/**
 * The origin lock on the WebView. A tablet with no address bar and no back
 * gesture cannot recover from a navigation that leaves the workstation, so
 * this predicate is the only thing standing between a stray link and a
 * stranded till.
 */
describe("isWithinBase", () => {
  const BASE = "http://192.168.1.10:8080";

  it("accepts the base itself and anything under its path", () => {
    expect(isWithinBase(BASE, BASE)).toBe(true);
    expect(isWithinBase(`${BASE}/pos`, BASE)).toBe(true);
    expect(isWithinBase(`${BASE}/pos/shop/hongo/shift/open`, BASE)).toBe(true);
  });

  it("accepts a bare query or fragment on the root", () => {
    expect(isWithinBase(`${BASE}?debug=1`, BASE)).toBe(true);
    expect(isWithinBase(`${BASE}#/pairing`, BASE)).toBe(true);
  });

  it("REJECTS a host that merely starts with the base — the classic prefix hole", () => {
    expect(isWithinBase("http://192.168.1.10:8080.evil.example/pos", BASE)).toBe(false);
    expect(isWithinBase("http://192.168.1.10:80801/pos", BASE)).toBe(false);
  });

  it("rejects a different host, port or scheme", () => {
    expect(isWithinBase("http://192.168.1.11:8080/pos", BASE)).toBe(false);
    expect(isWithinBase("http://192.168.1.10:9000/pos", BASE)).toBe(false);
    expect(isWithinBase("https://192.168.1.10:8080/pos", BASE)).toBe(false);
    expect(isWithinBase("https://evil.example/", BASE)).toBe(false);
  });

  it("is case-insensitive about scheme and host", () => {
    expect(isWithinBase("HTTP://192.168.1.10:8080/pos", BASE)).toBe(true);
    expect(isWithinBase("http://WS-App.local/pos", "http://ws-app.local")).toBe(true);
  });

  it("tolerates a base with a trailing slash or no scheme", () => {
    expect(isWithinBase(`${BASE}/pos`, `${BASE}/`)).toBe(true);
    expect(isWithinBase(`${BASE}/pos`, "192.168.1.10:8080")).toBe(true);
  });

  it("rejects everything when there is no base to compare against", () => {
    expect(isWithinBase(`${BASE}/pos`, "")).toBe(false);
    expect(isWithinBase(`${BASE}/pos`, "   ")).toBe(false);
  });
});

describe("compareVersionDesc", () => {
  it("sorts numerically, not lexicographically — 0.10.0 is NEWER than 0.9.0", () => {
    expect(compareVersionDesc("0.10.0", "0.9.0")).toBeLessThan(0);
    expect(compareVersionDesc("0.9.0", "0.10.0")).toBeGreaterThan(0);
  });

  it("returns 0 for equal versions so the name tie-break can run", () => {
    expect(compareVersionDesc("0.4.0", "0.4.0")).toBe(0);
  });

  it("treats a missing segment as 0", () => {
    expect(compareVersionDesc("1.2", "1.2.0")).toBe(0);
    expect(compareVersionDesc("1.2.1", "1.2")).toBeLessThan(0);
  });

  it("reads a numeric prefix out of a suffixed segment instead of giving up", () => {
    expect(compareVersionDesc("2026.8.10a", "2026.8.5b")).toBeLessThan(0);
  });

  it("puts an unparseable version last rather than throwing", () => {
    expect(compareVersionDesc("0.4.0", "unknown")).toBeLessThan(0);
    expect(compareVersionDesc("0.0.0", "")).toBe(0);
  });

  it("orders a real list newest-first when used as a comparator", () => {
    expect(["0.9.0", "0.10.0", "0.4.0"].sort(compareVersionDesc)).toEqual([
      "0.10.0",
      "0.9.0",
      "0.4.0",
    ]);
  });
});
