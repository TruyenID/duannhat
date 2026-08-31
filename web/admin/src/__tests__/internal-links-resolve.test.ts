import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative, sep } from "node:path";
import { describe, expect, it } from "vitest";

/**
 * #1214 — two sidebar/redirect links pointed at routes that do not exist, and
 * Next answers those with a hard 404.
 *
 * Neither was caught by anything: `/hq/{brand}/approvals` was the first item of
 * its nav group, translated into all three locales, and had never had a route
 * in this repo's whole history — it came over with the nav from the old Inertia
 * app and the page did not. The other sent you to a 404 *after* deleting a
 * topping group, so the destructive part had already happened.
 *
 * TypeScript cannot help here: these are template strings, and every one of
 * them is a perfectly valid string. So this resolves each internal path
 * literal against the actual app-router tree instead.
 *
 * It only sees literals of the `/hq/…` and `/shops/…` shape. A path assembled
 * from variables slips through — the point is to catch the common case cheaply,
 * not to prove the absence of dead links.
 */

const SRC = join(__dirname, "..");
const APP = join(SRC, "app");

function walk(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    const p = join(dir, entry);
    if (statSync(p).isDirectory()) walk(p, out);
    else out.push(p);
  }
  return out;
}

/** Every route the app router actually serves, dynamic segments as `*`. */
function servedRoutes(): Set<string> {
  const routes = new Set<string>();
  for (const file of walk(APP)) {
    if (!/[\\/]page\.tsx?$/.test(file)) continue;
    const segments = relative(APP, file)
      .split(sep)
      .slice(0, -1)
      // Route groups `(foo)` are organisational and contribute no URL segment.
      .filter((s) => !(s.startsWith("(") && s.endsWith(")")))
      .map((s) => (s.startsWith("[") ? "*" : s));
    routes.add("/" + segments.join("/"));
  }
  return routes;
}

/** Interpolations become `*`; query and hash are dropped. */
function normalise(path: string): string {
  return path
    .replace(/\$\{[^}]*\}/g, "*")
    .replace(/[?#].*$/, "")
    .replace(/\/$/, "");
}

function isServed(path: string, routes: Set<string>): boolean {
  const target = path.split("/");
  for (const route of routes) {
    const candidate = route.split("/");
    if (candidate.length !== target.length) continue;
    if (candidate.every((s, i) => s === "*" || target[i] === "*" || s === target[i])) {
      return true;
    }
  }
  return false;
}

describe("#1214 internal links resolve to a real route", () => {
  it("has no link to a route the app router does not serve", () => {
    const routes = servedRoutes();
    expect(routes.size).toBeGreaterThan(50);

    const dead: string[] = [];
    const pattern = /["'`](\/(?:hq|shops)\/[^"'`\s]*)["'`]/g;

    for (const file of walk(SRC)) {
      if (!/\.tsx?$/.test(file)) continue;
      // Service files describe BACKEND urls that share this prefix, and doc
      // comments spell out `{shopSlug}` placeholders — neither is a router
      // link. Tests are skipped too, including this one: its own examples are
      // deliberately unroutable.
      if (file.includes(`${sep}services${sep}`)) continue;
      if (file.includes(`${sep}__tests__${sep}`) || /\.test\.tsx?$/.test(file)) continue;

      const lines = readFileSync(file, "utf8").split("\n");
      lines.forEach((line, index) => {
        // Dòng CHÚ THÍCH không phải link. Bộ quét đã bỏ qua `services/` vì cùng
        // lý do — nó mô tả URL backend — nhưng một docblock giải thích API cũng
        // nằm được trong page component, và khi đó tên đường dẫn trong văn xuôi
        // bị đọc như một link app-router.
        //
        // Đây là bài học godx-tempo#1822 ở phía TS: đo MÃ, đừng đo văn xuôi.
        // Không dùng parser vì bộ quét cố ý đọc theo DÒNG để báo được số dòng;
        // đánh đổi là một link thật viết chung dòng với `//` sẽ bị bỏ sót — chưa
        // từng xảy ra trong repo này, và bỏ sót thì im lặng, còn báo oan thì dạy
        // người ta phớt lờ cả bộ quét.
        const trimmed = line.trimStart();
        if (trimmed.startsWith("*") || trimmed.startsWith("//") || trimmed.startsWith("/*")) return;

        for (const match of line.matchAll(pattern)) {
          const path = normalise(match[1]);
          if (path.split("/").length < 3) continue;
          if (!isServed(path, routes)) {
            dead.push(`${path}  ←  ${relative(SRC, file)}:${index + 1}`);
          }
        }
      });
    }

    // Joined into a string so a failure names the offenders and their call
    // sites rather than printing an array diff.
    expect(dead.join("\n")).toBe("");
  });
});
