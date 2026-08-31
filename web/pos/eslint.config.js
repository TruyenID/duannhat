import js from "@eslint/js";
import globals from "globals";
import reactHooks from "eslint-plugin-react-hooks";
import reactRefresh from "eslint-plugin-react-refresh";
import react from "eslint-plugin-react";
import tseslint from "typescript-eslint";
import { defineConfig, globalIgnores } from "eslint/config";
import prettierConfig from "eslint-config-prettier";
import tempoConfig from "@tempo/eslint-config";

/**
 * Plugins must be registered at the top level (no `files` restriction) so
 * they are globally available and @tempo/eslint-config rules can reference
 * them from separate config objects.
 */
export default defineConfig([
  // `scripts/` holds Node build tooling (gen-api-manifest.mjs), not app code —
  // it runs under Node globals and is not part of the browser bundle or tsconfig.
  globalIgnores(["dist", "scripts"]),

  // ─── Base: JS recommended ─────────────────────────────────────────────────
  js.configs.recommended,

  // ─── TypeScript + React ───────────────────────────────────────────────────
  // tseslint.configs.recommended is an array; spread it. Each object carries
  // its own files restriction (**/*.{ts,tsx,...}) so it won't apply to .js.
  ...tseslint.configs.recommended,

  // ─── React Hooks ─────────────────────────────────────────────────────────
  // recommended registers the plugin globally (no files restriction).
  reactHooks.configs["flat"]["recommended"],

  // ─── React Refresh ───────────────────────────────────────────────────────
  {
    files: ["**/*.{ts,tsx}"],
    plugins: { "react-refresh": reactRefresh },
    rules: {
      // Co-locating Provider + hook is standard React; react-refresh handles it fine.
      "react-refresh/only-export-components": ["warn", { allowConstantExport: true }],
    },
  },

  // ─── React plugin (needed for @tempo jsx-no-constructed-context-values) ──
  {
    plugins: { react },
    languageOptions: {
      globals: globals.browser,
    },
  },

  // ─── Shared TempoFast rules ───────────────────────────────────────────────
  // Design system, API layer, performance, TypeScript quality, general quality.
  // Source: packages/eslint-config — edit there, applies to all web apps.
  ...tempoConfig,

  // ─── Prettier — must be last to disable all formatting rules ─────────────
  prettierConfig,
]);
