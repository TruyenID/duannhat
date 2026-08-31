import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";
import path from "path";
import { fileURLToPath } from "node:url";

const dirname =
  typeof __dirname !== "undefined" ? __dirname : path.dirname(fileURLToPath(import.meta.url));

/**
 * Một project duy nhất (#1184, storybook gỡ ở #2315):
 *
 *   • `unit` — jsdom, không cần browser binary. Đây là thứ `pnpm test` chạy và
 *     là thứ cổng CI (#1182) chạy được trên runner trần.
 *
 * `@` resolves to `src/` here the same way it does in tsconfig `paths`, so a
 * test imports exactly what the app imports.
 */
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      "@": path.resolve(dirname, "./src"),
    },
  },
  test: {
    projects: [
      {
        extends: true,
        test: {
          name: "unit",
          // Default env. Pure-logic files opt out per-file with a
          // `@vitest-environment node` pragma — jsdom costs ~10s of setup per
          // file, which dominates the suite otherwise.
          environment: "jsdom",
          globals: true,
          setupFiles: ["./src/__tests__/setup.ts"],
          include: ["src/**/*.test.{ts,tsx}"],
          // Admin screens mount deep provider stacks (query + i18n + Radix);
          // 5s is not enough headroom on a cold transform, and a timeout there
          // reads as a false regression.
          testTimeout: 15_000,
          hookTimeout: 15_000,
        },
      },
    ],
    coverage: {
      provider: "v8",
      reporter: ["text", "html"],
      reportOnFailure: true,
      include: ["src/lib/**", "src/services/**", "src/hooks/**", "src/providers/**"],
      exclude: ["**/*.test.{ts,tsx}", "**/*.d.ts", "src/types/models/base/**"],
    },
  },
});
