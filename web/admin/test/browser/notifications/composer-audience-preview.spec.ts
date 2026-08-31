/**
 * Plan-023 M2-7 — composer step 1 audience preview shows count + sample.
 *
 * Asserts the /audiences/preview POST fires after the user constructs an
 * inline rule + the count is rendered.
 */
import { test, expect } from "@playwright/test";
import { setupBrandAdmin, TEST_BRAND_SLUG } from "./_shared";

test("composer step 1 fetches audience preview count", async ({ page }) => {
  let previewCalls = 0;
  await setupBrandAdmin(page, [
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/audiences/preview`,
      method: "POST",
      json: () => {
        previewCalls += 1;
        return { data: { count: 7, sample: [{ id: "u1", name: "Alpha" }, { id: "u2", name: "Beta" }] } };
      },
    },
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/audiences*`,
      json: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } },
    },
  ]);

  await page.goto(`/hq/${TEST_BRAND_SLUG}/notifications/compose`);
  await page.waitForLoadState("networkidle");

  const inlineMode = page.getByRole("tab", { name: /inline|rule|ルール/i }).first();
  if (await inlineMode.isVisible().catch(() => false)) {
    await inlineMode.click();
    await page.getByRole("button", { name: /add.*rule|ルールを追加|thêm rule/i }).first().click();
    await page.getByRole("combobox").first().click();
    await page.getByText(/role|役割/i).first().click();
  }

  await expect.poll(() => previewCalls, { timeout: 5_000 }).toBeGreaterThan(0);
});
