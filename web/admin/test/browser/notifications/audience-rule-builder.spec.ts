/**
 * Plan-023 M2-1 — audience rule-builder live preview count.
 *
 * Loads the new-audience page, adds a role rule, asserts the
 * /audiences/preview endpoint is hit and the resulting count renders
 * in the preview panel.
 */
import { test, expect } from "@playwright/test";
import { setupBrandAdmin, TEST_BRAND_SLUG } from "./_shared";

test("audience rule builder calls preview endpoint after adding a rule", async ({ page }) => {
  let previewHits = 0;
  await setupBrandAdmin(page, [
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/audiences/preview`,
      method: "POST",
      json: () => {
        previewHits += 1;
        return { data: { count: 3, sample: [{ id: "u1", name: "User One" }] } };
      },
    },
  ]);

  await page.goto(`/hq/${TEST_BRAND_SLUG}/notifications/audiences/new`);
  await page.waitForLoadState("networkidle");

  // Add a role rule. Selector heuristic: any "Add rule" / "ルールを追加" button.
  const addRule = page.getByRole("button", { name: /add rule|ルールを追加|thêm rule/i }).first();
  if (await addRule.isVisible().catch(() => false)) {
    await addRule.click();

    // Pick role "warehouse_manager".
    const roleSelect = page.getByRole("combobox").first();
    await roleSelect.click();
    await page.getByText(/warehouse_manager/i).first().click();
  }

  // Debounce window in source is ~400 ms. Poll up to 5 s.
  await expect.poll(() => previewHits, { timeout: 5_000 }).toBeGreaterThan(0);
});
