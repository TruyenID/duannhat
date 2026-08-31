#!/usr/bin/env node
/**
 * Refuse a tree where the YAML schemas and `.omnify/schemas.json` have drifted
 * apart (#1640).
 *
 * ## The failure this catches
 *
 * `252628e7b` committed PointReward's generated code — model, resource, and the
 * migration `2000_04_29_000000_alter_point_rewards_table.php` — but not
 * `.omnify/schemas.json`. The state file stayed at `1c13ced89`. Nothing failed.
 *
 * The bill arrived for the NEXT person: a regen emitted a SECOND migration
 * (`2000_04_30_…alter_point_rewards_table`) duplicating the first. That one was
 * harmless because the generator emits `if (! Schema::hasColumn(...))` guards —
 * harmless by accident, not by design. A non-idempotent change (a column type
 * change, a dropped index) would have produced two migrations fighting.
 *
 * CLAUDE.md documents the mirror image as generator trap #2 (#1216): the state
 * file marked as applied while no DDL was emitted. Both directions make the
 * repo lie about what has been generated, and both only surface when somebody
 * happens to regen.
 *
 * ## Why this compares the files DIRECTLY instead of running `omnify diff`
 *
 * The obvious guard — "`omnify diff` must say *No changes detected*" — was
 * built first and then MEASURED, and it does not detect this failure. Reverting
 * `.omnify/schemas.json` to the state file from `1c13ced89` (221 schemas
 * instead of 225, missing exactly the four the incident was about) leaves
 * `omnify diff` reporting a clean tree. Deleting `.omnify/workspace-cache/`
 * first changes nothing: `omnify diff` simply does not read this file.
 *
 * It does react to a YAML edit, which is why the wrong guard looks convincing
 * for one probe and then silently covers nothing. A guard that passes because
 * it never examined the thing it is named for is worse than no guard, because
 * it also answers the question "is this covered?" with a yes.
 *
 * So: compare the two artifacts this repo actually commits.
 *
 * ## Why it does NOT look at `git status .omnify/`
 *
 * `.omnify/lock.json` reorders its arrays and restamps `timestamp` on EVERY
 * run, changed or not (CLAUDE.md, generator trap #5). A guard keyed on the
 * directory being clean fires on every single run and teaches people to ignore
 * it.
 *
 * ## Scope, stated plainly
 *
 * This compares NAMES: which schemas exist, and which properties each declares.
 * It does not compare types, lengths or options — those live in a richer shape
 * in the state file than in the YAML, and re-deriving that mapping here would
 * be a second implementation of the generator's own reader. Names are where the
 * incident happened (four missing schemas, four missing properties) and they
 * are comparable without guessing.
 */
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join, basename, extname } from "node:path";
import { globSync } from "node:fs";
import { parse as parseYaml } from "yaml";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");

/**
 * Property names a schema YAML declares.
 *
 * Omnify names a schema after its FILE, not after a key inside it, so the file
 * basename is the schema name. (Getting this wrong sends you looking for
 * `PointRewardBranch:` as a top-level key and concluding the schema does not
 * exist — it does, as `schemas/Backend/Loyalty/PointRewardBranch.yaml`.)
 *
 * @param {string} text
 * @returns {Set<string>}
 */
export function propertiesInYaml(text) {
  const doc = parseYaml(text) ?? {};
  const props = doc.properties;

  return new Set(props && typeof props === "object" ? Object.keys(props) : []);
}

/**
 * @param {Record<string, {properties?: Record<string, unknown>}>} stateSchemas
 * @param {Record<string, Set<string>>} yamlSchemas
 * @returns {{ok: boolean, reason: string}}
 */
export function verdictFor(stateSchemas, yamlSchemas) {
  const inState = new Set(Object.keys(stateSchemas));
  const inYaml = new Set(Object.keys(yamlSchemas));

  const missingFromState = [...inYaml].filter((n) => !inState.has(n)).sort();
  const missingFromYaml = [...inState].filter((n) => !inYaml.has(n)).sort();

  /** @type {string[]} */
  const propGaps = [];
  for (const name of [...inYaml].filter((n) => inState.has(n)).sort()) {
    const stateProps = new Set(Object.keys(stateSchemas[name]?.properties ?? {}));
    for (const p of [...yamlSchemas[name]].sort()) {
      if (!stateProps.has(p)) propGaps.push(`${name}.${p}`);
    }
  }

  if (missingFromState.length === 0 && missingFromYaml.length === 0 && propGaps.length === 0) {
    return { ok: true, reason: "" };
  }

  const lines = [];
  if (missingFromState.length) {
    lines.push(`Schemas in the YAML but NOT in .omnify/schemas.json (${missingFromState.length}):`);
    lines.push(...missingFromState.map((n) => `  + ${n}`));
  }
  if (propGaps.length) {
    lines.push(`Properties in the YAML but NOT in .omnify/schemas.json (${propGaps.length}):`);
    lines.push(...propGaps.map((n) => `  + ${n}`));
  }
  if (missingFromYaml.length) {
    lines.push(`Schemas in .omnify/schemas.json with no YAML file (${missingFromYaml.length}):`);
    lines.push(...missingFromYaml.map((n) => `  - ${n}`));
  }

  return {
    ok: false,
    reason:
      `${lines.join("\n")}\n\n` +
      "The state file and the schemas have drifted. If the changes are yours: run\n" +
      "`npm run omnify:gen` and commit `.omnify/schemas.json` WITH the generated code —\n" +
      "they are one commit, never two.\n" +
      "If they are not: somebody shipped generated code without the state file, and the next\n" +
      "regen emits a DUPLICATE migration for each entry above (#1640).",
  };
}

/**
 * Second front: `.omnify/lock.json`'s `snapshot` vs `.omnify/schemas.json` (#2317).
 *
 * The two artifacts answer different questions, and only ONE of them was
 * guarded. `schemas.json` is what the generator read; the lock's `snapshot` is
 * the baseline `omnify diff` compares against. A tree can be green on the check
 * above while the lock sits two versions behind — which is exactly what was
 * found: lock at v138 (2026-08-07) still carrying `OrderAdjustment{,Allocation}`
 * and missing `Brand.customer_{header,order}_logo_file_id`, while `schemas.json`
 * and every YAML had moved on.
 *
 * That is not cosmetic. Measured on this repo before the fix, the next
 * `omnify generate` emitted THREE migrations for changes that had already
 * shipped: one re-adding two existing columns, and two DROPPING tables a pair
 * of committed migrations had already dropped. The column re-add was harmless
 * only by accident (`if (! Schema::hasColumn(...))`); nothing makes the drops
 * harmless anywhere the tables still hold rows.
 *
 * A guard that reports green while the next regen would do that is the
 * "vacuous green" shape the file header already warns about — same failure,
 * one file over.
 *
 * @param {Record<string, {properties?: Record<string, unknown>}>} lockSnapshot
 * @param {Record<string, {properties?: Record<string, unknown>}>} stateSchemas
 * @returns {{ok: boolean, reason: string}}
 */
export function lockVerdictFor(lockSnapshot, stateSchemas) {
  /** @type {Record<string, Set<string>>} */
  const asNameSets = {};
  for (const [name, schema] of Object.entries(stateSchemas)) {
    asNameSets[name] = new Set(Object.keys(schema?.properties ?? {}));
  }

  const verdict = verdictFor(lockSnapshot, asNameSets);
  if (verdict.ok) {
    return verdict;
  }

  // `verdictFor` words its report for the YAML-vs-state comparison. Here both
  // sides are generator artifacts, so relabel rather than fork the comparator —
  // a second implementation is how the two checks would drift apart.
  return {
    ok: false,
    reason:
      verdict.reason
        .split("\n\nThe state file")[0]
        .replace(/in the YAML but NOT in \.omnify\/schemas\.json/g,
                 "in .omnify/schemas.json but NOT in .omnify/lock.json's snapshot")
        .replace(/in \.omnify\/schemas\.json with no YAML file/g,
                 "in .omnify/lock.json's snapshot but NOT in .omnify/schemas.json") +
      "\n\n" +
      "`.omnify/lock.json`'s snapshot is the baseline `omnify diff` compares against, and it\n" +
      "has drifted from `.omnify/schemas.json`. The next `omnify generate` will treat every\n" +
      "entry above as NEW: re-adding columns that exist, and DROPPING tables that are already\n" +
      "gone (#2317).\n" +
      "Fix: run `npm run omnify:gen`, then delete the migrations it emits for changes that\n" +
      "already shipped, and commit the lock. Read the diff — do not commit it blind.",
  };
}

if (process.argv[1] && process.argv[1].endsWith("check-omnify-drift.mjs")) {
  const state = JSON.parse(readFileSync(join(root, ".omnify", "schemas.json"), "utf8")).schemas ?? {};

  /** @type {Record<string, Set<string>>} */
  const yamlSchemas = {};
  for (const file of globSync("schemas/**/*.yaml", { cwd: root })) {
    yamlSchemas[basename(file, extname(file))] = propertiesInYaml(readFileSync(join(root, file), "utf8"));
  }

  if (Object.keys(yamlSchemas).length === 0) {
    // No schemas found at all means the glob broke, not that the repo is empty.
    // Passing here would be the vacuous-green shape this file exists to avoid.
    console.error("\n✘ omnify drift guard (#1640): found no schema YAML at all — the scan is broken, not the tree.\n");
    process.exit(1);
  }

  const verdict = verdictFor(state, yamlSchemas);

  if (!verdict.ok) {
    console.error(`\n✘ omnify drift guard (#1640)\n\n${verdict.reason}\n`);
    process.exit(1);
  }

  const lockSnapshot = JSON.parse(readFileSync(join(root, ".omnify", "lock.json"), "utf8")).snapshot ?? {};

  if (Object.keys(lockSnapshot).length === 0) {
    console.error("\n✘ omnify drift guard (#2317): .omnify/lock.json carries no snapshot — the read is broken, not the tree.\n");
    process.exit(1);
  }

  const lockVerdict = lockVerdictFor(lockSnapshot, state);

  if (!lockVerdict.ok) {
    console.error(`\n✘ omnify lock drift guard (#2317)\n\n${lockVerdict.reason}\n`);
    process.exit(1);
  }

  console.log(`✔ .omnify/schemas.json in step with ${Object.keys(yamlSchemas).length} schema YAML files`);
  console.log(`✔ .omnify/lock.json snapshot in step with .omnify/schemas.json (${Object.keys(lockSnapshot).length} schemas)`);
}
