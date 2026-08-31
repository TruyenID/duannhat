import { defineConfig, devices } from "@playwright/test";

const PORT = 5450;
const externalBaseURL = process.env.PLAYWRIGHT_CUSTOMER_BASE_URL;

export default defineConfig({
  testDir: "./test/customer-browser",
  fullyParallel: false,
  timeout: 45_000,
  expect: { timeout: 8_000 },
  reporter: [["list"]],
  use: {
    baseURL: externalBaseURL ?? `http://localhost:${PORT}`,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [
    { name: "desktop", use: { ...devices["Desktop Chrome"] } },
    { name: "mobile", use: { ...devices["iPhone 13"], browserName: "chromium" } },
  ],
  webServer: externalBaseURL ? undefined : {
    command: "pnpm --dir ../customer-web dev",
    port: PORT,
    timeout: 90_000,
    reuseExistingServer: true,
    env: { NEXT_PUBLIC_API_URL: "http://localhost:5400" },
  },
});
