import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
  testDir: "./test/production",
  testMatch: /.*\.spec\.ts$/,
  fullyParallel: false,
  forbidOnly: true,
  retries: 0,
  workers: 1,
  reporter: [["list"], ["html", { open: "never", outputFolder: "playwright-report-production" }]],
  timeout: 60_000,
  expect: {
    timeout: 10_000,
  },
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? "https://tempo.godx.jp",
    locale: "ja-JP",
    trace: "retain-on-failure",
    video: "retain-on-failure",
    screenshot: "only-on-failure",
    actionTimeout: 15_000,
    ...devices["Desktop Chrome"],
  },
});
