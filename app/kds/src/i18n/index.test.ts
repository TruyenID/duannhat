import { readdirSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import {
  FALLBACK_LOCALE,
  getTranslations,
  isLocaleCode,
  translate,
  type LocaleCode,
} from "./index";

const locales: LocaleCode[] = ["ja", "en", "vi"];
const sourceRoot = join(dirname(fileURLToPath(import.meta.url)), "..");

function productionSourceFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);

    if (entry.isDirectory()) return productionSourceFiles(path);
    if (!/\.[jt]sx?$/.test(entry.name) || /\.(?:test|spec)\.[jt]sx?$/.test(entry.name)) {
      return [];
    }

    return [path];
  });
}

describe("KDS translation catalogue", () => {
  it("defines exactly the same keys in every supported locale", () => {
    const fallbackKeys = Object.keys(getTranslations(FALLBACK_LOCALE)).sort();

    for (const locale of locales) {
      expect(Object.keys(getTranslations(locale)).sort(), locale).toEqual(fallbackKeys);
    }
  });

  it("defines every literal translation key used by production source", () => {
    const definedKeys = new Set(Object.keys(getTranslations(FALLBACK_LOCALE)));
    const missingKeys = new Set<string>();

    for (const path of productionSourceFiles(sourceRoot)) {
      const source = readFileSync(path, "utf8");
      for (const match of source.matchAll(/\bt\(\s*["']([^"']+)["']/g)) {
        if (!definedKeys.has(match[1])) missingKeys.add(match[1]);
      }
    }

    expect([...missingKeys].sort()).toEqual([]);
  });
});

describe("translate", () => {
  it("uses the selected locale when the key exists", () => {
    expect(translate("ja", "common.loading")).toBe(
      getTranslations("ja")["common.loading"],
    );
    expect(translate("vi", "common.loading")).toBe(
      getTranslations("vi")["common.loading"],
    );
  });

  it("falls back to English when the selected locale is missing a key", () => {
    const selected = getTranslations("vi");
    const key = "common.loading";
    const original = selected[key];

    delete selected[key];
    try {
      expect(translate("vi", key)).toBe(getTranslations("en")[key]);
    } finally {
      selected[key] = original;
    }
  });

  it("interpolates variables after resolving an English fallback", () => {
    const selected = getTranslations("ja");
    const key = "connection.lan";
    const original = selected[key];

    delete selected[key];
    try {
      expect(translate("ja", key, { url: "http://tempo.local" })).toBe(
        "LAN: http://tempo.local",
      );
    } finally {
      selected[key] = original;
    }
  });

  it("returns the raw key only when both selected and fallback locales lack it", () => {
    expect(translate("vi", "missing.translation.key")).toBe("missing.translation.key");
  });

  it("does not render moustache syntax when an interpolation value is absent", () => {
    expect(translate("en", "connection.lan", {})).toBe("LAN: url");
  });
});

describe("isLocaleCode", () => {
  it.each(locales)("accepts supported locale %s", (locale) => {
    expect(isLocaleCode(locale)).toBe(true);
  });

  it.each(["", "jp", "vn", "EN", null, undefined, 1])(
    "rejects unsupported locale %s",
    (locale) => {
      expect(isLocaleCode(locale)).toBe(false);
    },
  );
});
