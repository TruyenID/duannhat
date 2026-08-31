#!/usr/bin/env node
/**
 * Refuse to run codegen with a generator that is not the one this repo pins
 * (#1267).
 *
 * CLAUDE.md already says this in words — "npm install trước khi regen, không
 * phải tuỳ chọn", "npx omnify version phải khớp lock trước khi tin bất cứ
 * output nào" — and records what it cost when it slipped: an old generator
 * rewrote correct code into broken code and took 68 tests red, silently.
 *
 * The rule still slipped again. A session ran `npm install`, and the dependency
 * was raised afterwards by someone else; node_modules then held 5.9.14 while
 * package.json asked for ^5.9.15. That is the shape of a rule that lives only
 * in a person's head: correct, written down, and still missed, because the
 * condition changed between reading it and acting.
 *
 * 5.9.14 in particular is the version WITHOUT schema validation (runGenerate
 * returned into the workspace path before validateSchemaGroups; omnify-go#141),
 * so regenerating with it also loses the check that keeps a misspelled type from
 * becoming a silent varchar.
 *
 * #1495: the lock is the pin, not the range. This script used to compare the
 * installed version against the `package.json` RANGE, which catches a generator
 * OLDER than the pin but waves through one NEWER than the lock — `^5.9.18`
 * happily accepts 5.9.19. That is not a hypothetical gap: 5.9.19 addresses
 * omnify-go#150 and #151, two of the five regen landmines CLAUDE.md lists, so
 * the two versions emit materially different diffs. Two sessions could regen
 * with different generators, both pass this gate, and nothing in the output
 * would say which one produced the diff being reviewed. So compare against
 * `package-lock.json` and refuse in BOTH directions; the range stays as the
 * fallback for when there is no lock to read.
 */
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const pkg = JSON.parse(readFileSync(join(root, "package.json"), "utf8"));
const want = (pkg.devDependencies?.["@omnifyjp/omnify"] ?? pkg.dependencies?.["@omnifyjp/omnify"] ?? "").trim();

if (!want) {
  // Nothing pinned: not this script's problem to invent a version.
  process.exit(0);
}

let installed;
try {
  installed = JSON.parse(
    readFileSync(join(root, "node_modules/@omnifyjp/omnify/package.json"), "utf8"),
  ).version;
} catch {
  fail(`@omnifyjp/omnify is not installed. Run:\n\n    npm install\n`);
}

// The lock is what the repo actually pins. Prefer it over the range whenever it
// is readable; a lock that does not mention the package is treated as absent
// rather than guessed at.
const locked = lockedVersion();
if (locked) {
  if (installed !== locked) {
    const direction =
      compare(installed.split(".").map(Number), locked.split(".").map(Number)) > 0
        ? `NEWER than the lock`
        : `older than the lock`;
    fail(
      `Installed @omnifyjp/omnify is ${installed}; package-lock.json pins ${locked}.\n\n` +
        `That generator is ${direction}, and codegen output differs between\n` +
        `patch versions (see CLAUDE.md — 5.9.19 alone changes two of the five known\n` +
        `regen landmines). Run:\n\n    npm ci\n\n` +
        `or, if the bump is intentional, update package.json + package-lock.json in\n` +
        `their own commit before regenerating.\n`,
    );
  }
  process.exit(0);
}

// No lock to read — fall back to the package.json range.
// Only ^x.y.z / ~x.y.z / exact are used here; anything else is left alone
// rather than guessed at.
const range = want.match(/^([\^~]?)(\d+)\.(\d+)\.(\d+)$/);
if (!range) {
  process.exit(0);
}
const [, op, major, minor, patch] = range;
const [iMajor, iMinor, iPatch] = installed.split(".").map(Number);
const wanted = [Number(major), Number(minor), Number(patch)];
const got = [iMajor, iMinor, iPatch];

const satisfies =
  op === ""
    ? got.every((v, i) => v === wanted[i])
    : op === "~"
      ? got[0] === wanted[0] && got[1] === wanted[1] && got[2] >= wanted[2]
      : got[0] === wanted[0] &&
        (got[1] > wanted[1] || (got[1] === wanted[1] && got[2] >= wanted[2]));

if (!satisfies) {
  fail(
    `Installed @omnifyjp/omnify is ${installed}; package.json asks for ${want}.\n\n` +
      `Codegen with a generator older than the pin has rewritten correct code into\n` +
      `broken code before, silently (see CLAUDE.md). Run:\n\n    npm install\n`,
  );
}

/** Version this repo locks, or null when there is no lock entry to read. */
function lockedVersion() {
  let lock;
  try {
    lock = JSON.parse(readFileSync(join(root, "package-lock.json"), "utf8"));
  } catch {
    return null;
  }
  // lockfileVersion 2/3 keep resolved versions under `packages`; v1 under `dependencies`.
  return (
    lock.packages?.["node_modules/@omnifyjp/omnify"]?.version ??
    lock.dependencies?.["@omnifyjp/omnify"]?.version ??
    null
  );
}

/** -1 / 0 / 1 for two [major, minor, patch] triples. */
function compare(a, b) {
  for (let i = 0; i < 3; i += 1) {
    if ((a[i] ?? 0) !== (b[i] ?? 0)) {
      return (a[i] ?? 0) > (b[i] ?? 0) ? 1 : -1;
    }
  }
  return 0;
}

function fail(message) {
  process.stderr.write(`\n  ✗ omnify version check failed\n\n  ${message.replace(/\n/g, "\n  ")}\n`);
  process.exit(1);
}
