/**
 * ⚠️ customer-web DOES NOT LINT WITH THIS FILE.
 *
 * `eslint.config.mjs` at the repo root builds its own config from
 * eslint-config-next and never imports @tempo/eslint-config. This package is a
 * workspace member (pnpm-workspace.yaml lists packages/*) that nothing consumes
 * for linting, so edits here change nothing.
 *
 * Edit `eslint.config.mjs` instead. I changed this file for #1259 believing it
 * was live, and the change was inert until it was noticed — hence the banner
 * rather than a silent deletion, since removing it is a separate decision.
 */
/**
 * Shared ESLint rules for all TempoFast web apps.
 *
 * PURE RULES — no plugin registration. Consumers must register the required
 * plugins at the top level of their own eslint.config so the rules are
 * resolvable:
 *
 *   Required plugins (register globally, not inside a `files` block):
 *     - react-hooks      (eslint-plugin-react-hooks)
 *     - react            (eslint-plugin-react)
 *     - @typescript-eslint (typescript-eslint or @typescript-eslint/eslint-plugin)
 *
 * Usage:
 *   import tempoConfig from "@tempo/eslint-config";
 *   export default defineConfig([...frameworkConfig, ...tempoConfig, prettierConfig]);
 *
 * What stays per-app:
 *   - Framework plugins (eslint-config-next, TanStack Query, Storybook, react-refresh)
 *   - Plugin registrations + parser config
 *   - Global ignores (.next/**, generated types)
 *   - Prettier (must remain last)
 */

export default [
  // ─── React hooks ─────────────────────────────────────────────────────────
  {
    rules: {
      // Intentionally downgraded from error: idiomatic form-hydration effects
      // (setForm inside useEffect watching `open` or `item`) are correct React
      // patterns; the rule is overly broad for this codebase.
      "react-hooks/set-state-in-effect": "warn",
      "react-hooks/preserve-manual-memoization": "warn",
    },
  },

  // ─── React ───────────────────────────────────────────────────────────────
  {
    rules: {
      // Inline object/array literals in JSX props create a new reference on
      // every render, defeating React.memo and causing child re-renders.
      "react/jsx-no-constructed-context-values": "error",
    },
  },

  // ─── TypeScript quality (TS/TSX files only) ───────────────────────────────
  {
    files: ["**/*.{ts,tsx,mts}"],
    rules: {
      // `any` is unavoidable at API boundary / Omnify generated types for now.
      "@typescript-eslint/no-explicit-any": "warn",
      "@typescript-eslint/no-unused-vars": [
        "warn",
        {
          argsIgnorePattern: "^_",
          varsIgnorePattern: "^_",
          caughtErrorsIgnorePattern: "^_",
        },
      ],
      // Consistent type imports reduce bundle size (import elision).
      "@typescript-eslint/consistent-type-imports": [
        "warn",
        { prefer: "type-imports", fixStyle: "inline-type-imports" },
      ],
    },
  },

  // ─── Design system + API + general quality ───────────────────────────────
  {
    rules: {
      // Block raw Loader2/Loader2Icon — use <Spinner> from @/components/ui/spinner
      "no-restricted-imports": [
        "error",
        {
          paths: [
            {
              name: "lucide-react",
              importNames: ["Loader2", "Loader2Icon"],
              message:
                "Use <Spinner> from @/components/ui/spinner instead — it ships with built-in role='status' + aria-label='Loading' a11y attributes.",
            },
          ],
        },
      ],

      // Every HTTP call must go through apiFetch.
      "no-restricted-globals": [
        "error",
        {
          name: "fetch",
          message:
            "Use apiFetch from @/lib/api instead of raw fetch(). apiFetch centralises auth headers, Accept-Language, and 401 redirect.",
        },
      ],

      // console.log/warn left in source are almost always debug artifacts.
      // error, not warn (#1259). A warning does not fail CI, so a bare
      // console.log reaches production unopposed — which is exactly the road
      // #1245 travelled: fourteen of them wrote payment request bodies and QR
      // tokens into kiosk device logs. `info` is dropped from the allow-list
      // for the same reason; console.info carries anything console.log can.
      //
      // warn/error stay allowed on purpose: #1245 deliberately kept
      // console.error as a breadcrumb in the device log for when Sentry is
      // unreachable.
      "no-console": ["error", { allow: ["warn", "error"] }],
    },
  },

  // ─── Spinner carve-out ───────────────────────────────────────────────────
  // Allow the Spinner component itself to import Loader2Icon.
  // Matches both a single-file (spinner.tsx) and directory (spinner/**) layout.
  {
    files: ["src/components/ui/spinner.tsx", "src/components/ui/spinner/**"],
    rules: { "no-restricted-imports": "off" },
  },

  // ─── API lib carve-out ───────────────────────────────────────────────────
  {
    files: ["src/lib/api.ts"],
    rules: { "no-restricted-globals": "off" },
  },
];
