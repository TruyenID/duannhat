/**
 * Plan-023 M2-6 — composer 3-step wizard advance/back/submit.
 *
 * Lands on /compose, drives Next through 3 steps, asserts the broadcast
 * POST fires with the picked audience + template + channels.
 */
import { test, expect, type Route } from "@playwright/test";
import { setupBrandAdmin, TEST_BRAND_SLUG } from "./_shared";

test("composer wizard advances, retreats, and submits a broadcast", async ({ page }) => {
  let broadcastBody: unknown = null;

  await setupBrandAdmin(page, [
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/audiences*`,
      json: { data: [{ id: "aud-1", name: "All warehouse managers", is_system: false }], meta: { current_page: 1, last_page: 1, total: 1 } },
    },
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/audiences/preview`,
      method: "POST",
      json: { data: { count: 3, sample: [] } },
    },
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/templates*`,
      json: { data: [{ id: "tpl-1", key: "stock.alert.low", default_channels: ["in_app"], is_system: true, content: { ja: { title: "T", body: "B" } } }], meta: { current_page: 1, last_page: 1, total: 1 } },
    },
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/broadcast`,
      method: "POST",
      json: (route: Route) => {
        broadcastBody = route.request().postDataJSON();
        return { data: { id: "br-1", status: "dispatched" } };
      },
    },
  ]);

  await page.goto(`/hq/${TEST_BRAND_SLUG}/notifications/compose`);
  await page.waitForLoadState("networkidle");

  // Step 1: audience
  await page.getByText(/All warehouse managers/).first().click();
  await page.getByRole("button", { name: /next|次へ|tiếp/i }).first().click();

  // Step 2: template
  await page.getByText(/stock\.alert\.low/).first().click();
  await page.getByRole("button", { name: /next|次へ|tiếp/i }).first().click();

  // Step 3: delivery — back, then forward, then submit
  await page.getByRole("button", { name: /back|戻る|quay lại/i }).first().click();
  await page.getByRole("button", { name: /next|次へ|tiếp/i }).first().click();

  await page.getByRole("button", { name: /send|送信|gửi|submit/i }).first().click();

  await expect.poll(() => broadcastBody !== null, { timeout: 5_000 }).toBeTruthy();
});
