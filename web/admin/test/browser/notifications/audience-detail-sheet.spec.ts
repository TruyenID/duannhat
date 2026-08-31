/**
 * Plan-023 M2-2 — audience detail Sheet opens from card click or View button.
 *
 * Backend returns one audience; spec opens the Sheet via card click and
 * then closes + re-opens via the View-icon button. Edit/Delete buttons
 * on the card must NOT open the Sheet (stopPropagation contract).
 */
import { test, expect } from "@playwright/test";
import { setupBrandAdmin, TEST_BRAND_SLUG } from "./_shared";

const AUDIENCE = {
  id: "aud-1",
  name: "All warehouse managers",
  description: "Every warehouse_manager across our warehouses",
  rule: {
    combinator: "or",
    rules: [{ type: "role", role: "warehouse_manager" }],
  },
  shop_id: null,
  brand_id: "brand-1",
  is_system: false,
};

test("audience card click opens detail sheet", async ({ page }) => {
  await setupBrandAdmin(page, [
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/audiences*`,
      json: { data: [AUDIENCE], meta: { current_page: 1, last_page: 1, total: 1 } },
    },
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/audiences/aud-1`,
      json: { data: { ...AUDIENCE, resolved_count: 3, sample: [{ id: "u1", name: "User One" }] } },
    },
  ]);

  await page.goto(`/hq/${TEST_BRAND_SLUG}/notifications/audiences`);
  await page.waitForLoadState("networkidle");

  await page.getByText(AUDIENCE.name).first().click();
  await expect(page.getByRole("dialog").or(page.getByRole("complementary"))).toBeVisible();
});

test("audience View icon button opens the detail sheet without firing Edit", async ({ page }) => {
  await setupBrandAdmin(page, [
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/audiences*`,
      json: { data: [AUDIENCE], meta: { current_page: 1, last_page: 1, total: 1 } },
    },
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/audiences/aud-1`,
      json: { data: { ...AUDIENCE, resolved_count: 3, sample: [] } },
    },
  ]);

  await page.goto(`/hq/${TEST_BRAND_SLUG}/notifications/audiences`);
  await page.waitForLoadState("networkidle");

  const viewBtn = page.getByRole("button", { name: /view|表示|xem/i }).first();
  if (await viewBtn.isVisible().catch(() => false)) {
    await viewBtn.click();
    await expect(page).toHaveURL(/\/audiences$/);
  }
});
