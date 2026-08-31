import { defineConfig, devices } from "@playwright/test";

export default defineConfig({
  testDir: "./test/customer-production",
  fullyParallel: false,
  timeout: 60_000,
  expect: { timeout: 12_000 },
  reporter: [["list"]],
  use: {
    baseURL: process.env.PLAYWRIGHT_CUSTOMER_BASE_URL ?? "https://menu.vietorigin.jp",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [
    { name: "desktop", use: { ...devices["Desktop Chrome"] } },
    { name: "mobile", use: { ...devices["iPhone 13"], browserName: "chromium" } },
  ],
});
