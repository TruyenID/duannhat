/**
 * #1738 — every dialog must carry its own render error boundary.
 *
 * The boundary is wired into the pos-web-local `DialogContent`
 * (`src/components/ui/dialog.tsx`). That makes it impossible to forget *per
 * call site*, but it is still possible to bypass in one move: import
 * `DialogContent` straight from `@godxjp/ui` again. That import compiles, it
 * renders identically, and the missing boundary shows up only the day a render
 * crash inside that dialog wipes the cashier's order off the screen.
 *
 * So the rule is enforced here, statically: `@godxjp/ui`'s raw `DialogContent`
 * may be named in exactly one file — the wrapper that adds the boundary.
 *
 * Stated limit: this is a source scan, not a type check. It sees
 * `import { DialogContent } from "@godxjp/ui"`, and it sees a namespace import
 * used as `ui.DialogContent`. It would not see an alias laundered through a
 * third module. That is an acceptable floor — the failure it guards against is
 * an ordinary copy-paste of an old import line, not an adversary.
 */

// @vitest-environment node

import { describe, expect, it } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative, sep } from "node:path";

const SRC = join(__dirname, "..");

/**
 * The one file allowed to touch the raw component — it is what adds the
 * boundary. This test file exempts itself too: its own prose quotes the banned
 * import line, and a scanner that flags its own explanation is useless.
 */
const EXEMPT = [
  join("components", "ui", "dialog.tsx"),
  join("__tests__", "dialog-boundary.arch.test.ts"),
].map((p) => p.split(sep).join("/"));

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    if (entry === "node_modules" || entry === "dist") continue;
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) walk(full, out);
    else if (/\.tsx?$/.test(entry)) out.push(full);
  }
  return out;
}

const FILES = walk(SRC);

/** Import statements whose module specifier is `@godxjp/ui`. */
function uiImportClauses(source: string): string[] {
  const out: string[] = [];
  const re = /import\s+([^;]*?)\s+from\s+["']@godxjp\/ui["']/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(source)) !== null) out.push(m[1]);
  return out;
}

describe("dialog boundary (#1738)", () => {
  it("scans a non-trivial set of files", () => {
    // Without this, a broken walk() would make every assertion below vacuously
    // true and the guard would silently stop guarding.
    expect(FILES.length).toBeGreaterThan(50);
  });

  it("routes every DialogContent through the local boundary wrapper", () => {
    const offenders: string[] = [];

    for (const file of FILES) {
      const rel = relative(SRC, file).split(sep).join("/");
      if (EXEMPT.includes(rel)) continue;

      const source = readFileSync(file, "utf8");
      if (uiImportClauses(source).some((clause) => /\bDialogContent\b/.test(clause))) {
        offenders.push(rel);
      }
    }

    expect(
      offenders,
      'Import DialogContent from "@/components/ui/dialog" instead — the raw ' +
        "@godxjp/ui one has no error boundary, so a render crash inside the " +
        "dialog unmounts the whole order screen (#1738).",
    ).toEqual([]);
  });

  it("keeps at least one dialog wired to the wrapper", () => {
    // Guards the mirror-image failure: someone "fixes" the rule above by
    // deleting the wrapper import everywhere.
    const users = FILES.filter((f) =>
      /from\s+["']@\/components\/ui\/dialog["']/.test(readFileSync(f, "utf8")),
    );
    expect(users.length).toBeGreaterThan(15);
  });
});
