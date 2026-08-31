/**
 * Plan-023 M2 T2.5 — arch test forbidding `test.skip` / `test.fixme` /
 * `describe.skip` inside Playwright specs.
 *
 * Lives next to the specs so it runs in the same `pnpm test:browser`
 * invocation. Glob the sibling notifications/ directory and grep each
 * file. Fails if any literal is present.
 *
 * This guards the plan-023 M2 promise: zero skipped browser tests
 * post-M2. If a scenario genuinely can't run yet, delete it (or move
 * it to a feature branch) — don't `.skip` it.
 */
import { test, expect } from "@playwright/test";
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join } from "node:path";

const FORBIDDEN_TOKENS = ["test.skip(", "test.fixme(", "describe.skip(", "it.skip(", "it.fixme("];

function* walk(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      // Skip our own directory — the arch test itself doesn't need to be
      // scanned (it would self-trip on the string-literal token list).
      if (entry === "_arch") continue;
      yield* walk(full);
    } else if (full.endsWith(".spec.ts")) {
      yield full;
    }
  }
}

test("no spec under test/browser/notifications/ uses test.skip / test.fixme / describe.skip", () => {
  const specDir = join(__dirname, "..");
  const offenders: Array<{ file: string; token: string }> = [];

  for (const file of walk(specDir)) {
    const contents = readFileSync(file, "utf8");
    for (const token of FORBIDDEN_TOKENS) {
      if (contents.includes(token)) {
        offenders.push({ file, token });
      }
    }
  }

  expect(
    offenders,
    `Skipped browser tests found:\n${offenders.map((o) => `  ${o.file}: ${o.token}`).join("\n")}`,
  ).toHaveLength(0);
});
