// @ts-nocheck
/**
 * gen-api-manifest.mjs — generate `pos-api-manifest.json` from the pos-web
 * service layer (godx-tempo#1169 T3.6/T3.7).
 *
 * WHY: pos-web can be served two ways — from Amplify (cloud) or embedded in the
 * workstation at `/pos` (same-origin LAN). In the LAN deployment every backend
 * call must be answered by the workstation, either by a local handler or by its
 * catch-all proxy to Cloud. This manifest is the machine-readable CONTRACT of
 * every {method, path} pos-web calls; the workstation vendors a copy and a Go
 * parity test asserts it covers each route. A new route pos-web calls that the
 * workstation cannot serve then turns the workstation's test red — instead of
 * silently 404'ing on a shop tablet.
 *
 * HOW: pos-web funnels EVERY backend call through `apiFetch` (Cloud/LAN,
 * `/api/v1/*`) or `lanFetch` (workstation-only, `/api/lan/print/*`). A
 * `no-restricted-globals` ESLint rule bans raw `fetch()`, so the funnel is
 * closed and auditable (this script also verifies no unknown raw-fetch site
 * appeared). We walk the TS AST, find every apiFetch/lanFetch call, and reduce
 * its path argument to a stable route key (dynamic `${…}` segments → `{param}`,
 * query strings dropped). Helpers (`ordersUrl`, `paymentsUrl`, `menuUrl`,
 * `path`, `BASE`, …) are inlined generically by evaluating their return
 * expression with the call's arguments bound.
 *
 * SAFETY: the reducer is FAIL-LOUD. Any apiFetch/lanFetch path it cannot reduce
 * statically throws with a file:line — a silently-dropped route is the one bug
 * that would make the whole contract worthless, so we make it impossible to
 * miss one. `route_count` guards against a truncated file. Run `--check` in CI
 * to fail when the committed manifest drifts from a fresh regeneration.
 *
 * USAGE:
 *   node scripts/gen-api-manifest.mjs           # write pos-api-manifest.json
 *   node scripts/gen-api-manifest.mjs --check   # exit 1 if committed file is stale
 */
import { Project, Node, SyntaxKind } from "ts-morph";
import { readFileSync, writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import path from "node:path";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, "..");
const OUT = path.join(ROOT, "pos-api-manifest.json");
const CHECK = process.argv.includes("--check");

/** Dynamic path segment marker. Any `${…}` interpolated into a PATH position. */
const PARAM = "{param}";

/** Built-in string fns that pass their argument through unchanged for routing. */
const ENCODE_FNS = new Set([
  "encodeURIComponent",
  "encodeURI",
  "decodeURIComponent",
  "decodeURI",
  "String",
]);

/**
 * Raw `fetch()` escape hatches (each carries an eslint-disable no-restricted-
 * globals comment). The first two are the funnels themselves; the rest are
 * standalone. Any `fetch()` outside these files means a new escape hatch
 * appeared and its route is NOT in the funnel — we fail rather than silently
 * miss it.
 */
const KNOWN_RAW_FETCH = new Set([
  "src/lib/api.ts", // apiFetch funnel
  "src/services/workstation-print-service.ts", // lanFetch funnel
  "src/providers/workstation-provider.tsx", // health probe → EXTRA_ROUTES below
  // #2494 — trang ghép nối probe CÙNG `/api/lan/health` để biết máy trạm đã ghép
  // chưa. Raw fetch có chủ ý: lúc này thiết bị CHƯA có token, nên `apiFetch`
  // (vốn đính Authorization) là sai chỗ. Route đã nằm trong EXTRA_ROUTES bên
  // dưới, không cần thêm mục mới.
  "src/app/pairing/page.tsx",
  "src/services/auth/pairing.ts", // Cloud-direct device pairing → excluded (see note)
  "src/services/workstation-cash-changer-service.ts", // 釣銭機 LAN bridge → EXTRA_ROUTES below
  "src/services/card-terminal-service.ts", // P400 LAN bridge → EXTRA_ROUTES below
]);

/**
 * Routes reached through a raw-fetch escape hatch rather than apiFetch/lanFetch.
 *   - `/api/lan/health`: probed from TWO sites — workstation-provider (discover
 *     the LAN hub) and the pairing page (is the workstation itself paired yet?).
 *     Both go raw: the provider must not let apiFetch's resolver send the probe
 *     to Cloud, and the pairing page runs pre-auth, so lanFetch — which attaches
 *     a device token this terminal does not have yet — is not available to it.
 *     The workstation serves the route, so it belongs in the contract.
 * DELIBERATELY EXCLUDED: `POST /api/v1/devices/pair` (src/services/auth/
 * pairing.ts). It hits `${CLOUD_URL}` directly, bypassing the LAN/Cloud
 * resolver — it is never served by the workstation, so it is not part of the
 * workstation coverage contract.
 */
const EXTRA_ROUTES = [
  { method: "GET", path: "/api/lan/health", note: "workstation-provider LAN probe" },
  // 釣銭機 (#1804). Raw fetch for the same reason print is: apiFetch resolves a
  // Cloud base URL and these exist ONLY on the workstation. They belong in the
  // contract — the workstation's parity test is what stops one of them being
  // renamed on the Go side while the POS keeps calling the old path.
  {
    method: "POST",
    path: "/api/v1/pos/cash-changer/collect",
    note: "cash recycler: start a collection",
  },
  {
    method: "GET",
    path: "/api/v1/pos/cash-changer/collect/{session}",
    note: "cash recycler: poll a collection",
  },
  {
    method: "POST",
    path: "/api/v1/pos/cash-changer/collect/{session}/cancel",
    note: "cash recycler: return the deposited cash",
  },
  // Verifone P400 (#1088). These USED to be scanned automatically: the service
  // called apiFetch until it was moved to address the workstation directly, the
  // same way print and the 釣銭機 do — the reader sits behind the shop's NAT and
  // Cloud has no route to it. Nobody regenerated afterwards, so the file became
  // an unregistered raw-fetch site (`--check` threw) while the manifest kept the
  // three routes the old scan had captured. Listing them here is what makes the
  // contract true again, and it ADDS the two the scan never saw (`current`,
  // `abandon`) — the workstation has had local handlers for both all along, so
  // until now `TestPosApiLocalHandlerVerbsMatchTheManifest` skipped them as
  // "pos-web never calls this path" and asserted nothing about their verbs.
  //
  // `{param}` (not `{session}` like the block above) on purpose: these three
  // lines are what the scanner itself produced, so keeping the marker means the
  // manifest diff is exactly the two missing routes and nothing else. The Go
  // side treats any `{…}` segment as a wildcard, so the choice is cosmetic there.
  {
    method: "POST",
    path: "/api/v1/pos/terminal/charge",
    note: "card terminal: start a charge",
  },
  {
    method: "GET",
    path: "/api/v1/pos/terminal/charge/{param}",
    note: "card terminal: poll a charge",
  },
  {
    method: "POST",
    path: "/api/v1/pos/terminal/charge/{param}/cancel",
    note: "card terminal: ask the reader to return the card",
  },
  {
    method: "GET",
    path: "/api/v1/pos/terminal/current",
    note: "card terminal: what is holding the reader right now",
  },
  {
    method: "POST",
    path: "/api/v1/pos/terminal/abandon",
    note: "card terminal: settle a session the workstation can no longer drive",
  },
];

// ─── AST reducer ────────────────────────────────────────────────────────────

function fail(node, msg) {
  const sf = node.getSourceFile();
  const rel = path.relative(ROOT, sf.getFilePath());
  throw new Error(`${rel}:${node.getStartLineNumber()} — ${msg}\n    ${node.getText().slice(0, 160)}`);
}

function unwrap(node) {
  while (
    Node.isParenthesizedExpression(node) ||
    Node.isAsExpression(node) ||
    Node.isNonNullExpression(node) ||
    Node.isSatisfiesExpression(node) ||
    Node.isTypeAssertion(node)
  ) {
    node = node.getExpression();
  }
  return node;
}

/** Cut a contribution at the first `?` — queries never belong to the route key. */
const cut = (s) => (s.includes("?") ? s.slice(0, s.indexOf("?")) : s);

/**
 * Reduce an expression to its route-path contribution (a string; dynamic
 * segments become `{param}`; a leading/trailing query keeps its `?` so callers
 * can stop). Throws (fail-loud) on anything it cannot reduce statically.
 * `scope` maps an inlined helper's parameter names to their bound contributions.
 */
function resolveRaw(node, scope) {
  node = unwrap(node);

  if (Node.isStringLiteral(node) || Node.isNoSubstitutionTemplateLiteral(node)) {
    return node.getLiteralValue();
  }

  if (Node.isTemplateExpression(node)) {
    let out = node.getHead().getLiteralText();
    if (out.includes("?")) return out;
    for (const span of node.getTemplateSpans()) {
      out += resolveRaw(span.getExpression(), scope);
      if (out.includes("?")) return out;
      out += span.getLiteral().getLiteralText();
      if (out.includes("?")) return out;
    }
    return out;
  }

  if (Node.isBinaryExpression(node) && node.getOperatorToken().getText() === "+") {
    let out = resolveRaw(node.getLeft(), scope);
    if (out.includes("?")) return out; // right side is query — stop
    return out + resolveRaw(node.getRight(), scope);
  }

  if (Node.isConditionalExpression(node)) {
    // The only idiomatic conditional in a path is an OPTIONAL trailing query
    // (`x ? "?a=..." : ""`); both branches then contribute the same route. Any
    // conditional that yields two DIFFERENT routes is genuinely ambiguous — fail.
    const a = cut(resolveRaw(node.getWhenTrue(), scope));
    const b = cut(resolveRaw(node.getWhenFalse(), scope));
    if (a === b) return a;
    fail(node, `ambiguous conditional path: "${a}" vs "${b}"`);
  }

  if (Node.isCallExpression(node)) {
    const callee = unwrap(node.getExpression());
    if (Node.isIdentifier(callee)) {
      const name = callee.getText();
      if (ENCODE_FNS.has(name)) {
        const arg = node.getArguments()[0];
        return arg ? resolveRaw(arg, scope) : PARAM;
      }
      const fn = getCallableDecl(callee);
      if (fn) return inlineFunction(fn, node.getArguments(), scope, node);
    }
    fail(node, `unresolvable call — cannot statically reduce ${callee.getText()}(…)`);
  }

  if (Node.isIdentifier(node)) return resolveIdentifier(node, scope);

  if (Node.isPropertyAccessExpression(node) || Node.isElementAccessExpression(node)) {
    // A member access interpolated into a path is a dynamic value (e.g.
    // `input.orderId`) — a single dynamic segment.
    return PARAM;
  }

  if (Node.isNumericLiteral(node)) return PARAM; // numeric segment → dynamic key

  fail(node, `unsupported path expression (${node.getKindName()})`);
}

function resolveIdentifier(node, scope) {
  const name = node.getText();
  if (scope.has(name)) return scope.get(name);

  const sym = node.getSymbol();
  const decls = sym ? sym.getDeclarations() : [];
  if (decls.length === 0) fail(node, `cannot resolve identifier "${name}"`);
  const d = decls[0];

  if (Node.isParameterDeclaration(d) || Node.isBindingElement(d)) return PARAM;

  if (Node.isVariableDeclaration(d)) {
    const init = d.getInitializer();
    if (!init) return PARAM;
    const k = unwrap(init);
    if (
      Node.isStringLiteral(k) ||
      Node.isNoSubstitutionTemplateLiteral(k) ||
      Node.isTemplateExpression(k) ||
      Node.isConditionalExpression(k) ||
      (Node.isBinaryExpression(k) && k.getOperatorToken().getText() === "+")
    ) {
      return resolveRaw(k, scope);
    }
    if (Node.isCallExpression(k)) {
      // `const x = ordersUrl(...)` → reduce it; `const d = Math.max(...)` and
      // other computed values → a dynamic segment.
      const callee = unwrap(k.getExpression());
      if (Node.isIdentifier(callee) && (ENCODE_FNS.has(callee.getText()) || getCallableDecl(callee))) {
        return resolveRaw(k, scope);
      }
      return PARAM;
    }
    // Object/array/other computed initializer → dynamic value.
    return PARAM;
  }

  fail(node, `identifier "${name}" resolves to ${d.getKindName()} — not a path value`);
}

/** Resolve an identifier callee to its function/arrow declaration, if local. */
function getCallableDecl(idNode) {
  const sym = idNode.getSymbol();
  if (!sym) return null;
  for (const d of sym.getDeclarations()) {
    if (Node.isFunctionDeclaration(d)) return d;
    if (Node.isVariableDeclaration(d)) {
      const init = d.getInitializer();
      if (init && (Node.isArrowFunction(init) || Node.isFunctionExpression(init))) return init;
    }
  }
  return null;
}

function inlineFunction(fn, args, callerScope, callNode) {
  const local = new Map();
  fn.getParameters().forEach((p, i) => {
    const argNode = args[i];
    let val;
    if (argNode) {
      val = resolveRaw(argNode, callerScope);
    } else {
      const def = p.getInitializer();
      val = def ? resolveRaw(def, local) : PARAM;
    }
    local.set(p.getName(), val);
  });
  return resolveRaw(getReturnExpression(fn, callNode), local);
}

function getReturnExpression(fn, callNode) {
  if (Node.isArrowFunction(fn)) {
    const body = fn.getBody();
    if (!Node.isBlock(body)) return body; // expression-bodied arrow
  }
  const body = fn.getBody();
  if (!body || !Node.isBlock(body)) fail(callNode, `helper has no reducible body`);
  const returns = body.getStatements().filter((s) => Node.isReturnStatement(s));
  if (returns.length === 0) fail(callNode, `helper has no top-level return`);
  const exprs = returns.map((r) => r.getExpression()).filter(Boolean);
  if (exprs.length === 1) return exprs[0];
  // Multiple returns: only allowed if they reduce to the same route.
  const reduced = exprs.map((e) => cut(resolveRaw(e, new Map())));
  if (new Set(reduced).size === 1) return exprs[0];
  fail(callNode, `helper has divergent returns: ${[...new Set(reduced)].join(" | ")}`);
}

// ─── method extraction ──────────────────────────────────────────────────────

function methodFromOptions(optionsNode) {
  if (!optionsNode) return "GET";
  const o = unwrap(optionsNode);
  if (!Node.isObjectLiteralExpression(o)) {
    // `{ ...spread }` or a variable — method not statically knowable.
    fail(o, `apiFetch options must be an object literal to read its method`);
  }
  const prop = o.getProperty("method");
  if (!prop) return "GET";
  if (!Node.isPropertyAssignment(prop)) fail(prop, `method must be a literal`);
  const v = unwrap(prop.getInitializer());
  if (!Node.isStringLiteral(v)) fail(v, `method must be a string literal`);
  return v.getLiteralValue().toUpperCase();
}

function litArg(node, i, what) {
  const a = node.getArguments()[i];
  if (!a) fail(node, `missing ${what} argument`);
  const u = unwrap(a);
  if (!Node.isStringLiteral(u) && !Node.isNoSubstitutionTemplateLiteral(u)) {
    fail(u, `${what} must be a string literal`);
  }
  return u.getLiteralValue().toUpperCase();
}

// ─── scan ───────────────────────────────────────────────────────────────────

function isTestFile(sf) {
  const p = sf.getFilePath();
  return /\.(test|spec)\.[tj]sx?$/.test(p) || /[\\/](__tests__|__mocks__|test|test-utils)[\\/]/.test(p);
}

function normalizePath(raw, node) {
  let p = cut(raw).trim();
  if (!p.startsWith("/")) fail(node, `resolved path is not absolute: "${p}"`);
  // Collapse any residual duplicate slashes (defensive).
  p = p.replace(/\/{2,}/g, "/");
  // Trailing slash is not significant for these routes; drop except root.
  if (p.length > 1 && p.endsWith("/")) p = p.slice(0, -1);
  return p;
}

function main() {
  const project = new Project({
    tsConfigFilePath: path.join(ROOT, "tsconfig.app.json"),
    skipAddingFilesFromTsConfig: false,
  });

  const routes = new Map(); // key `METHOD path` → {method, path}
  const add = (method, p) => routes.set(`${method} ${p}`, { method, path: p });

  const sourceFiles = project.getSourceFiles().filter((sf) => {
    const fp = sf.getFilePath();
    return fp.includes(`${path.sep}src${path.sep}`) && !isTestFile(sf);
  });

  let apiCalls = 0;
  let lanCalls = 0;
  const rawFetchViolations = [];

  for (const sf of sourceFiles) {
    const rel = path.relative(ROOT, sf.getFilePath()).split(path.sep).join("/");

    for (const call of sf.getDescendantsOfKind(SyntaxKind.CallExpression)) {
      const callee = unwrap(call.getExpression());
      if (!Node.isIdentifier(callee)) continue;
      const name = callee.getText();

      if (name === "apiFetch") {
        const p = normalizePath(resolveRaw(call.getArguments()[0], new Map()), call);
        add(methodFromOptions(call.getArguments()[1]), p);
        apiCalls++;
      } else if (name === "lanFetch") {
        const method = litArg(call, 0, "lanFetch method");
        const p = normalizePath(resolveRaw(call.getArguments()[1], new Map()), call);
        add(method, p);
        lanCalls++;
      } else if (name === "fetch") {
        if (!KNOWN_RAW_FETCH.has(rel)) rawFetchViolations.push(`${rel}:${call.getStartLineNumber()}`);
      }
    }
  }

  if (rawFetchViolations.length > 0) {
    throw new Error(
      `Unknown raw fetch() escape hatch(es) — route it via apiFetch/lanFetch or ` +
        `register it in gen-api-manifest.mjs (KNOWN_RAW_FETCH / EXTRA_ROUTES):\n  ` +
        rawFetchViolations.join("\n  "),
    );
  }

  // Verify the health-probe escape hatches still exist where EXTRA_ROUTES claims.
  // Checked per site, not "any site": a file that stops probing keeps its
  // KNOWN_RAW_FETCH entry, and that stale entry would silently wave through an
  // unrelated raw fetch added to the same file later.
  for (const rel of ["src/providers/workstation-provider.tsx", "src/app/pairing/page.tsx"]) {
    const sf = project.getSourceFile((f) => f.getFilePath().endsWith(rel));
    if (!sf || !sf.getFullText().includes("/api/lan/health")) {
      throw new Error(
        `EXTRA_ROUTES lists /api/lan/health with ${rel} as a probe site, but that site is gone — ` +
          `drop it from KNOWN_RAW_FETCH in gen-api-manifest.mjs`,
      );
    }
  }
  for (const r of EXTRA_ROUTES) add(r.method, r.path);

  const list = [...routes.values()].sort((a, b) =>
    a.path === b.path ? a.method.localeCompare(b.method) : a.path.localeCompare(b.path),
  );

  const manifest = {
    $comment:
      "GENERATED by scripts/gen-api-manifest.mjs — DO NOT EDIT BY HAND. " +
      "Contract for the workstation /pos parity test (godx-tempo#1169 T3.6/T3.7). " +
      "Run `pnpm gen:api-manifest` after changing any apiFetch/lanFetch call.",
    version: 1,
    route_count: list.length,
    routes: list.map(({ method, path }) => ({ method, path })),
  };

  const json = JSON.stringify(manifest, null, 2) + "\n";

  if (CHECK) {
    let current = "";
    try {
      current = readFileSync(OUT, "utf8");
    } catch {
      /* missing → drift */
    }
    if (current !== json) {
      console.error(
        `pos-api-manifest.json is stale (${list.length} routes generated). ` +
          `Run \`pnpm gen:api-manifest\` and commit the result.`,
      );
      process.exit(1);
    }
    console.log(`pos-api-manifest.json up to date (${list.length} routes).`);
    return;
  }

  writeFileSync(OUT, json);
  console.log(
    `Wrote ${path.relative(ROOT, OUT)} — ${list.length} routes ` +
      `(${apiCalls} apiFetch, ${lanCalls} lanFetch, ${EXTRA_ROUTES.length} raw-fetch).`,
  );
}

main();
