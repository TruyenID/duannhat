import { readdirSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import {
  getTranslations,
  isLocaleCode,
  type LocaleCode,
} from "./index";

const locales: LocaleCode[] = ["ja", "en", "vi"];
const projectRoot = join(dirname(fileURLToPath(import.meta.url)), "../..");
const reportedMissingKeys = [
  "common.active",
  "common.inactive",
  "common.status",
  "common.search",
];

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

describe("TMS translation catalogue", () => {
  it("defines exactly the same keys in every supported locale", () => {
    const sourceKeys = Object.keys(getTranslations("ja")).sort();

    for (const locale of locales) {
      expect(Object.keys(getTranslations(locale)).sort(), locale).toEqual(sourceKeys);
    }
  });

  it("defines every literal translation key used by production app and source files", () => {
    const used = new Map<string, string>();

    for (const sourceDirectory of [join(projectRoot, "app"), join(projectRoot, "src")]) {
      for (const path of productionSourceFiles(sourceDirectory)) {
        const source = readFileSync(path, "utf8");
        for (const match of source.matchAll(/\bt\(\s*["']([A-Za-z0-9_.]+)["']/g)) {
          if (!used.has(match[1])) used.set(match[1], path);
        }
      }
    }

    expect(used.size, "sanity check: the source scanner must find real call sites").toBeGreaterThan(45);

    const missing: string[] = [];
    for (const [key, path] of used) {
      for (const locale of locales) {
        if (!(key in getTranslations(locale))) {
          missing.push(`${locale}: ${key} (${path.replace(`${projectRoot}/`, "")})`);
        }
      }
    }

    expect(missing).toEqual([]);
  });

  it.each(locales)("renders the four reported demo labels in %s", (locale) => {
    const translations = getTranslations(locale);

    for (const key of reportedMissingKeys) {
      expect(translations[key], key).toBeTypeOf("string");
      expect(translations[key], key).not.toBe(key);
      expect(translations[key], key).not.toBe("");
    }
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
