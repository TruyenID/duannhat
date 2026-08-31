import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";

import en from "./en.json";
import ja from "./ja.json";
import vi from "./vi.json";

/**
 * A key used in code but absent from the catalogue is rendered VERBATIM.
 *
 * `app-provider.tsx` resolves `translations[key] ?? fallbackTranslations[key] ??
 * key`, so the last resort is the dot-path itself. Six had drifted, and one of
 * them sat in a delete-confirmation dialog: an operator was asked to confirm a
 * destructive action whose explanation read
 * `notifications.rules.delete.body`. Another, `common.error`, was a near-miss
 * for the `common.error_loading` that does exist.
 *
 * This is the OPPOSITE direction to a locale-parity check. Parity asks whether
 * the three catalogues agree with each other; nothing there notices a key that
 * every catalogue is equally missing, which is exactly the shape of all six.
 *
 * Runtime-assembled keys — `t(\`prefix.${value}\`)` — get a second, weaker
 * check: the STATIC PREFIX must match at least one catalogue key. That cannot
 * prove every value resolves, but it catches the failure that actually
 * happened: three prefixes with NO matching key at all, so every filter button
 * and every outcome badge on the notification screens rendered as a dot-path.
 *
 * What is still not covered: a prefix that resolves for some values and not
 * others. Stated rather than implied by a green run.
 */
const CATALOGUES = { ja, en, vi } as const;

const SRC = join(__dirname, "..");

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    if (entry === "node_modules" || entry === ".next") continue;
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      walk(full, out);
    } else if (/\.tsx?$/.test(entry) && !/\.(test|spec)\.tsx?$/.test(entry)) {
      out.push(full);
    }
  }
  return out;
}

describe("translation keys used in code", () => {
  const used = new Map<string, string>();

  for (const file of walk(SRC)) {
    const source = readFileSync(file, "utf8");
    for (const match of source.matchAll(/\bt\(\s*"([a-zA-Z0-9_.]+)"/g)) {
      if (!used.has(match[1])) used.set(match[1], file.replace(`${SRC}/`, ""));
    }
  }

  it("finds call sites at all", () => {
    // A regex that silently matched nothing would read exactly like a clean
    // codebase, which is the failure mode this whole file exists to prevent.
    expect(used.size).toBeGreaterThan(100);
  });

  it("have a catalogue entry for every dynamic prefix", () => {
    // `t(`a.b.${x}`)` — collect the literal part before the first ${.
    const prefixes = new Map<string, string>();

    for (const file of walk(SRC)) {
      const source = readFileSync(file, "utf8");
      for (const match of source.matchAll(/t\(\s*`([a-zA-Z0-9_.]*)\$\{/g)) {
        if (match[1] && !prefixes.has(match[1])) {
          prefixes.set(match[1], file.replace(`${SRC}/`, ""));
        }
      }
    }

    const catalogue = Object.keys(ja as Record<string, string>);
    const dead: string[] = [];

    for (const [prefix, where] of prefixes) {
      if (!catalogue.some((key) => key.startsWith(prefix))) {
        dead.push(`${prefix}…  (${where})`);
      }
    }

    expect(
      dead,
      `Dynamic key prefixes with no catalogue entry at all — every value they\nproduce renders as a raw key:\n  ${dead.join("\n  ")}`
    ).toEqual([]);
  });

  it("are all defined in every locale", () => {
    const missing: string[] = [];

    for (const [key, where] of used) {
      for (const [locale, catalogue] of Object.entries(CATALOGUES)) {
        if (!(key in (catalogue as Record<string, string>))) {
          missing.push(`${locale}: ${key}  (${where})`);
        }
      }
    }

    expect(
      missing,
      `Used in code, absent from the catalogue — these render as the raw key:\n  ${missing.join("\n  ")}`
    ).toEqual([]);
  });
});
