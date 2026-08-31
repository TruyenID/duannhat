/**
 * Plan-023 M2 T2.1 — Playwright runner config for admin-web E2E specs.
 *
 * Separate from the Vitest browser config (`vitest.config.ts`), which
 * targets component / Storybook tests. This config drives full-page
 * navigation against a running Next.js dev server.
 *
 * Specs live under `test/browser/`. Shared fixtures (Echo stub, Sanctum
 * session, MSW backend mocks) live under `test/fixtures/`.
 */
import { defineConfig, devices } from "@playwright/test";

const PORT = 5430;
const BASE_URL = process.env.PLAYWRIGHT_BASE_URL ?? `http://127.0.0.1:${PORT}`;

export default defineConfig({
  testDir: "./test/browser",
  testMatch: /.*\.spec\.ts$/,
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: process.env.CI
    ? [["github"], ["html", { open: "never" }]]
    : [["list"], ["html", { open: "never" }]],
  timeout: 30_000,
  expect: {
    timeout: 5_000,
  },
  use: {
    baseURL: BASE_URL,
    trace: "retain-on-failure",
    video: "retain-on-failure",
    screenshot: "only-on-failure",
    actionTimeout: 10_000,
  },
  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
    },
  ],
  webServer: process.env.PLAYWRIGHT_REUSE_SERVER
    ? undefined
    : {
        command: "pnpm dev",
        port: PORT,
        timeout: 90_000,
        reuseExistingServer: !process.env.CI,
        env: {
          NEXT_PUBLIC_API_URL: process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:5400",
        },
      },
});
