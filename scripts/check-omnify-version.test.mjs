/**
 * Guard for the guard (#1495).
 *
 * `check-omnify-version.mjs` is the only thing standing between a regen and a
 * generator that is not the one this repo pins, and it already regressed once:
 * it compared against the `package.json` RANGE, so `^5.9.18` waved through
 * 5.9.19 — a version that changes two of the five known regen landmines. A gate
 * that silently stops gating looks exactly like a gate that passes, so the
 * failure directions are pinned here.
 *
 * No test dependency: node's built-in runner. Run with `npm run test:omnify-check`.
 *
 * The script under test resolves its root from its own location, so each case
 * gets a throwaway root carrying its own package.json / package-lock.json /
 * node_modules.
 */
import { test } from "node:test";
import assert from "node:assert/strict";
import { mkdtempSync, mkdirSync, writeFileSync, copyFileSync, rmSync } from "node:fs";
import { spawnSync } from "node:child_process";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const here = dirname(fileURLToPath(import.meta.url));
const script = join(here, "check-omnify-version.mjs");

/** Build a fake repo root and run the checker in it; returns its exit code. */
function runIn({ range, locked, installed }) {
  const root = mkdtempSync(join(tmpdir(), "omnify-check-"));
  try {
    mkdirSync(join(root, "scripts"), { recursive: true });
    copyFileSync(script, join(root, "scripts", "check-omnify-version.mjs"));
    writeFileSync(
      join(root, "package.json"),
      JSON.stringify({ devDependencies: range ? { "@omnifyjp/omnify": range } : {} }),
    );
    if (locked !== undefined) {
      writeFileSync(
        join(root, "package-lock.json"),
        JSON.stringify({
          lockfileVersion: 3,
          packages: locked === null ? {} : { "node_modules/@omnifyjp/omnify": { version: locked } },
        }),
      );
    }
    if (installed !== undefined) {
      const dir = join(root, "node_modules", "@omnifyjp", "omnify");
      mkdirSync(dir, { recursive: true });
      writeFileSync(join(dir, "package.json"), JSON.stringify({ version: installed }));
    }
    return spawnSync(process.execPath, [join(root, "scripts", "check-omnify-version.mjs")], {
      encoding: "utf8",
    }).status;
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
}

test("lock present: installed matching the lock passes", () => {
  assert.equal(runIn({ range: "^5.9.18", locked: "5.9.18", installed: "5.9.18" }), 0);
});

test("lock present: installed NEWER than the lock is refused (the #1495 hole)", () => {
  // `^5.9.18` is satisfied by 5.9.19, so a range check passes this. The lock does not.
  assert.notEqual(runIn({ range: "^5.9.18", locked: "5.9.18", installed: "5.9.19" }), 0);
});

test("lock present: installed older than the lock is refused", () => {
  assert.notEqual(runIn({ range: "^5.9.18", locked: "5.9.18", installed: "5.9.17" }), 0);
});

test("lock present: a different major is refused", () => {
  assert.notEqual(runIn({ range: "^5.9.18", locked: "5.9.18", installed: "6.0.0" }), 0);
});

test("no lock: falls back to the package.json range", () => {
  assert.equal(runIn({ range: "^5.9.18", installed: "5.9.19" }), 0);
  assert.notEqual(runIn({ range: "^5.9.18", installed: "5.9.17" }), 0);
});

test("no lock: an exact pin still means exact", () => {
  assert.notEqual(runIn({ range: "5.9.18", installed: "5.9.19" }), 0);
});

test("a lock that does not mention the package falls back to the range", () => {
  assert.equal(runIn({ range: "^5.9.18", locked: null, installed: "5.9.19" }), 0);
});

test("not installed at all is refused", () => {
  assert.notEqual(runIn({ range: "^5.9.18", locked: "5.9.18" }), 0);
});

test("nothing pinned in package.json: not this script's business", () => {
  assert.equal(runIn({ range: "", installed: "5.9.19" }), 0);
});
