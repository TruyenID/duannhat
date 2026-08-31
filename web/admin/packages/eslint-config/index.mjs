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
      // Block raw Loader2/Loader2Icon — use <Spinner> from @godxjp/ui.
      //
      // The message used to name `@/components/ui/spinner`, which stopped
      // resolving in admin-web on 2026-04-11 (`76289f4` — the whole
      // `src/components/ui/` tree, spinner included, moved into the
      // @godxjp/ui package and 222 imports were rewritten). A lint error
      // whose remedy is an unresolvable import is worse than no rule, so it
      // names the package. Fixed by #2029.
      "no-restricted-imports": [
        "error",
        {
          paths: [
            {
              name: "lucide-react",
              importNames: ["Loader2", "Loader2Icon"],
              message:
                "Use <Spinner> from @godxjp/ui instead — it ships with built-in role='status' + aria-label='Loading' a11y attributes.",
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
      //
      // Raised from "warn" to "error": as a warning it sat unread among ~180
      // others, and two `console.log(">>> [DEBUG] …")` calls dumping a product
      // creation payload survived in the HQ product page that whole time. The
      // class has twice been worse than untidy — #1201 (customer-web, 84 calls
      // including a customer's entire order history) and #1245 (kiosk, payment
      // bodies and a table's QR token reaching the production device log, where
      // anyone with USB access reads them via adb).
      //
      // `info` no longer rides along: the one legitimate use marks a feature
      // held client-side until an endpoint lands, and it now carries an inline
      // waiver stating that — which is the point. An exception should be
      // visible, not implied by a permissive rule.
      "no-console": ["error", { allow: ["warn", "error"] }],
    },
  },

  // ─── Spinner carve-out ───────────────────────────────────────────────────
  // Allow the Spinner component itself to import Loader2Icon.
  // Matches both a single-file (spinner.tsx) and directory (spinner/**) layout.
  //
  // INERT IN admin-web — kept on purpose, do not read it as evidence that a
  // local spinner exists here. Since `76289f4` admin-web has no
  // `src/components/ui/` at all, so this glob matches zero files and the ban
  // above is effectively repo-wide (the only legitimate Loader2Icon importer
  // now lives inside the @godxjp/ui package, which is not linted from here).
  // It stays because an empty glob costs nothing and keeps the config correct
  // if a local spinner is ever vendored back in — deleting it would be a
  // silent trap for that day. This is admin-web's OWN copy of
  // @tempo/eslint-config (resolved via `workspace:*` from packages/ since the
  // submodule split), so it is not shared: pos-web keeps a separate copy where
  // the same carve-out IS live for its real `src/components/ui/spinner.tsx`.
  {
    files: ["src/components/ui/spinner.tsx", "src/components/ui/spinner/**"],
    rules: { "no-restricted-imports": "off" },
  },

  // ─── API lib carve-out ───────────────────────────────────────────────────
  {
    files: ["src/lib/api.ts"],
    rules: { "no-restricted-globals": "off" },
  },

  // ─── console carve-out: tests and maintenance scripts ────────────────────
  // Both print on purpose. A diagnostic in a failing test, or a CLI script
  // reporting what it changed, is read by a human at a terminal and never
  // shipped. (scripts/ was added after a repo-wide `pnpm lint` flagged
  // fix-ui-imports.mjs — a narrower `eslint src` run had not reached it.)
  {
    files: [
      "**/__tests__/**",
      "**/*.test.{ts,tsx}",
      "**/*.spec.{ts,tsx}",
      "scripts/**",
      "*.config.{js,mjs,ts}",
    ],
    rules: { "no-console": "off" },
  },
];
