import { expect, test } from "@playwright/test";
import { signInAs } from "../fixtures/session";

test.beforeEach(async ({ page }) => {
  await signInAs(page, { role: "shop_manager", locale: "en" });
});

test("shows access denied for a 403 without requesting workspace lists", async ({ page }) => {
  const requestedPaths: string[] = [];
  await page.route("**/api/v1/**", async (route) => {
    requestedPaths.push(new URL(route.request().url()).pathname);
    await route.fulfill({
      status: 403,
      contentType: "application/json",
      body: JSON.stringify({ message: "Forbidden" }),
    });
  });

  await page.goto("/select-context");

  await expect(page.getByRole("heading", { name: "Access denied" })).toBeVisible();
  await expect(page.getByRole("button", { name: "Sign out" })).toBeVisible();
  expect(requestedPaths).toEqual(["/api/v1/me/context"]);
});

test("shows access denied for an authenticated account with no workspaces", async ({ page }) => {
  let requestCount = 0;
  await page.route("**/api/v1/**", async (route) => {
    requestCount += 1;
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        user: { id: "staff-1", name: "Staff", email: "staff@example.com" },
        brand_count: 0,
        shop_count: 0,
      }),
    });
  });

  await page.goto("/select-context");

  await expect(page.getByRole("heading", { name: "Access denied" })).toBeVisible();
  await expect(page.getByText("Select workspace")).toHaveCount(0);
  expect(requestCount).toBe(1);
});

test("recovers from a transient bootstrap failure via Retry", async ({ page }) => {
  let attempts = 0;
  await page.route("**/api/v1/me/context", async (route) => {
    attempts += 1;
    if (attempts <= 2) {
      await route.fulfill({
        status: 500,
        contentType: "application/json",
        body: JSON.stringify({ message: "Temporary failure" }),
      });

      return;
    }

    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        user: { id: "admin-1", name: "Admin", email: "admin@example.com" },
        brand_count: 0,
        shop_count: 0,
      }),
    });
  });

  await page.goto("/select-context");
  await page.getByRole("button", { name: "Retry" }).click();

  await expect(page.getByRole("heading", { name: "Access denied" })).toBeVisible();
  expect(attempts).toBe(3);
});
