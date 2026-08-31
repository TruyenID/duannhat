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
      // Block raw Loader2/Loader2Icon — every loading affordance goes through
      // a <Spinner> so the a11y attributes and the animation stay consistent
      // and globally restyleable. WHERE Spinner comes from differs per app, so
      // the message names both rather than one dead path (#2029: it used to
      // name only `@/components/ui/spinner`, which admin-web does not have —
      // following the message there produces an import that cannot resolve).
      "no-restricted-imports": [
        "error",
        {
          paths: [
            {
              name: "lucide-react",
              importNames: ["Loader2", "Loader2Icon"],
              message:
                "Use <Spinner> instead — it ships with built-in role='status' + aria-label='Loading' a11y attributes. Import it from '@godxjp/ui' (admin-web) or from the app's own '@/components/ui/spinner' (pos-web).",
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
      "no-console": ["warn", { allow: ["warn", "error", "info"] }],
    },
  },

  // ─── Spinner carve-out ───────────────────────────────────────────────────
  // Allow an app-local Spinner component to import Loader2Icon — it is the one
  // place the raw icon is legitimately used. LIVE for pos-web
  // (`src/components/ui/spinner.tsx` exists and wraps Loader2Icon); a no-op for
  // admin-web, which has no `src/components/ui/` at all because its Spinner
  // comes from `@godxjp/ui` (node_modules — never linted here). Keep the
  // carve-out narrow: widening it to `src/components/ui/**` would silently
  // unban raw Loader2 across a whole directory.
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
