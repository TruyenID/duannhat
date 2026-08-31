/**
 * Plan-023 M2-3 — template editor live render preview.
 *
 * Loads the templates editor, types into the params area, asserts the
 * /templates/render endpoint is invoked. (The page itself shows the
 * preview body; we assert the network call rather than the DOM string
 * to stay tolerant of locale label drift.)
 */
import { test, expect } from "@playwright/test";
import { setupBrandAdmin, TEST_BRAND_SLUG } from "./_shared";

test("template editor calls /render after params change", async ({ page }) => {
  let renderHits = 0;
  await setupBrandAdmin(page, [
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/templates*`,
      json: { data: [{ id: "tpl-1", key: "stock.alert.low", content: { ja: { title: "在庫アラート", body: "{{warehouse_name}} の在庫が低下" } }, default_channels: ["in_app"], is_system: true }], meta: { current_page: 1, last_page: 1, total: 1 } },
    },
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/templates/tpl-1`,
      json: { data: { id: "tpl-1", key: "stock.alert.low", content: { ja: { title: "在庫アラート", body: "{{warehouse_name}} の在庫が低下" }, en: { title: "Stock alert", body: "{{warehouse_name}} stock low" }, vi: { title: "Cảnh báo", body: "{{warehouse_name}} sắp hết" } }, default_channels: ["in_app"], params_schema: { warehouse_name: "string" }, is_system: true } },
    },
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/templates/render`,
      method: "POST",
      json: () => {
        renderHits += 1;
        return { data: { ja: { title: "在庫アラート", body: "Tokyo HQ の在庫が低下" }, en: { title: "Stock alert", body: "Tokyo HQ stock low" }, vi: { title: "Cảnh báo", body: "Tokyo HQ sắp hết" } } };
      },
    },
  ]);

  await page.goto(`/hq/${TEST_BRAND_SLUG}/notifications/templates/tpl-1`);
  await page.waitForLoadState("networkidle");

  // The params editor is usually a JSON textarea or per-key inputs. Type
  // into the first textbox under a "params" label, or fall back to any
  // textarea on the page.
  const paramInput = page
    .getByLabel(/warehouse_name|params/i)
    .first()
    .or(page.getByRole("textbox").first());
  await paramInput.fill("Tokyo HQ");

  await expect.poll(() => renderHits, { timeout: 5_000 }).toBeGreaterThan(0);
});
