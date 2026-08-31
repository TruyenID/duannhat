import js from "@eslint/js";
import globals from "globals";
import reactHooks from "eslint-plugin-react-hooks";
import reactRefresh from "eslint-plugin-react-refresh";
import react from "eslint-plugin-react";
import tseslint from "typescript-eslint";
import { defineConfig, globalIgnores } from "eslint/config";
import prettierConfig from "eslint-config-prettier";

/**
 * Standalone ESLint config for godx-tempo-kds.
 *
 * NOT a pnpm workspace member — rules from @tempo/eslint-config are inlined
 * here. Sync manually when the umbrella packages/eslint-config updates.
 *
 * Required plugins are registered globally (no files restriction) so that
 * rule objects in separate config entries can reference them by name.
 */
export default defineConfig([
  globalIgnores(["dist"]),

  // ─── Base: JS recommended ─────────────────────────────────────────────────
  js.configs.recommended,

  // ─── TypeScript + React ───────────────────────────────────────────────────
  ...tseslint.configs.recommended,

  // ─── React Hooks ─────────────────────────────────────────────────────────
  reactHooks.configs["flat"]["recommended"],

  // ─── React Refresh ───────────────────────────────────────────────────────
  {
    files: ["**/*.{ts,tsx}"],
    plugins: { "react-refresh": reactRefresh },
    rules: {
      "react-refresh/only-export-components": ["warn", { allowConstantExport: true }],
    },
  },

  // ─── React plugin ────────────────────────────────────────────────────────
  {
    plugins: { react },
    languageOptions: {
      globals: globals.browser,
    },
    settings: {
      react: {
        version: "19",
      },
    },
  },

  // ─── React hooks rules (inlined from @tempo/eslint-config) ───────────────
  {
    rules: {
      "react-hooks/set-state-in-effect": "warn",
      "react-hooks/preserve-manual-memoization": "warn",
    },
  },

  // ─── React rules (inlined from @tempo/eslint-config) ─────────────────────
  {
    rules: {
      "react/jsx-no-constructed-context-values": "error",
    },
  },

  // ─── TypeScript quality (inlined from @tempo/eslint-config) ──────────────
  {
    files: ["**/*.{ts,tsx,mts}"],
    rules: {
      "@typescript-eslint/no-explicit-any": "warn",
      "@typescript-eslint/no-unused-vars": [
        "warn",
        {
          argsIgnorePattern: "^_",
          varsIgnorePattern: "^_",
          caughtErrorsIgnorePattern: "^_",
        },
      ],
      "@typescript-eslint/consistent-type-imports": [
        "warn",
        { prefer: "type-imports", fixStyle: "inline-type-imports" },
      ],
    },
  },

  // ─── Design system + API + general quality (inlined from @tempo/eslint-config)
  {
    rules: {
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
      "no-restricted-globals": [
        "error",
        {
          name: "fetch",
          message:
            "Use apiFetch from @/lib/api instead of raw fetch(). apiFetch centralises auth headers, Accept-Language, and 401 redirect.",
        },
      ],
      // error, not warn (#1259). A warning does not fail CI, so a bare
      // console.log reaches production unopposed — the road #1245 travelled,
      // where fourteen of them wrote payment bodies and QR tokens into kiosk
      // device logs. `info` is dropped for the same reason: console.info
      // carries anything console.log can. warn/error stay allowed, because a
      // breadcrumb in the device log is worth having when Sentry is offline.
      "no-console": ["error", { allow: ["warn", "error"] }],
    },
  },

  // ─── Spinner carve-out ───────────────────────────────────────────────────
  {
    files: ["src/components/ui/spinner.tsx", "src/components/ui/spinner/**"],
    rules: { "no-restricted-imports": "off" },
  },

  // ─── API lib + pairing carve-out ─────────────────────────────────────────
  // pairing.ts uses raw fetch() intentionally — device_token doesn't exist
  // yet during pairing so apiFetch can't be used (no auth header to inject).
  {
    files: ["src/lib/api.ts", "src/services/auth/pairing.ts"],
    rules: { "no-restricted-globals": "off" },
  },

  // ─── Test carve-out ───────────────────────────────────────────────────────
  // Test files assign global.fetch = vi.fn() intentionally for mocking.
  {
    files: ["**/__tests__/**/*.{ts,tsx}", "**/*.test.{ts,tsx}", "**/*.spec.{ts,tsx}"],
    rules: { "no-restricted-globals": "off" },
  },

  // ─── Prettier — must be last to disable all formatting rules ─────────────
  prettierConfig,
]);
