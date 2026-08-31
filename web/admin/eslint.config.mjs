import tanstackQuery from "@tanstack/eslint-plugin-query";
import prettierConfig from "eslint-config-prettier";

import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";
import tempoConfig from "@tempo/eslint-config";

const eslintConfig = defineConfig([
  // ─── Base: Next.js + TypeScript ──────────────────────────────────────────
  ...nextVitals,
  ...nextTs,

  // ─── TanStack Query ──────────────────────────────────────────────────────
  // Catches: missing queryKey, exhaustive-deps in queryFn, stale closures.
  ...tanstackQuery.configs["flat/recommended"],

  // ─── Shared TempoFast rules ───────────────────────────────────────────────
  // Design system, API layer, performance, TypeScript quality, general quality.
  // Source: packages/eslint-config — edit there, applies to all web apps.
  ...tempoConfig,

  // ─── Ignore generated / build artefacts ─────────────────────────────────
  globalIgnores([
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
    // Omnify codegen — annotated "DO NOT EDIT", clobbered on next regen.
    "src/types/models/**",
  ]),

  // ─── Storybook ───────────────────────────────────────────────────────────

  // ─── Prettier — must be last to disable all formatting rules ─────────────
  prettierConfig,
]);

export default eslintConfig;
