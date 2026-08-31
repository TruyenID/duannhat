/**
 * Plan-023 M2-9 — bell dropdown links to /inbox.
 *
 * Opens the bell, clicks the "View all" / "Inbox" link, asserts URL.
 */
import { test, expect } from "@playwright/test";
import { setupBrandAdmin } from "./_shared";

test("bell dropdown navigates to /inbox", async ({ page }) => {
  await setupBrandAdmin(page);
  await page.goto("/");

  await page.getByRole("button", { name: /notification|inbox|bell/i }).first().click();
  await page.getByRole("link", { name: /inbox|view all|すべて見る|tất cả/i }).first().click();

  await expect(page).toHaveURL(/\/inbox(\?.*)?$/);
});
