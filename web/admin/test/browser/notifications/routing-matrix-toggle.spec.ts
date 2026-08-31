/**
 * Plan-023 M2-4 — routing matrix toggle persists across reload.
 *
 * Two visits: first toggles the email checkbox off and patches via the
 * routes endpoint; second loads the same page and asserts the GET
 * response shape reflects email: false.
 */
import { test, expect, type Route } from "@playwright/test";
import { setupBrandAdmin, TEST_BRAND_SLUG } from "./_shared";

test("routing matrix toggle saves + survives reload", async ({ page }) => {
  let emailEnabled = true;
  await setupBrandAdmin(page, [
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/routing*`,
      method: "GET",
      json: () => ({
        data: [
          {
            id: "rt-1",
            type: "stock.alert.low",
            channels: { in_app: true, realtime: true, email: emailEnabled, push: false },
          },
        ],
      }),
    },
    {
      path: `**/api/v1/hq/${TEST_BRAND_SLUG}/notifications/routing/rt-1`,
      method: "PATCH",
      json: (route: Route) => {
        const body = route.request().postDataJSON() as { channels?: { email?: boolean } };
        if (body?.channels && "email" in body.channels) {
          emailEnabled = !!body.channels.email;
        }
        return { data: { id: "rt-1", type: "stock.alert.low", channels: { in_app: true, realtime: true, email: emailEnabled, push: false } } };
      },
    },
  ]);

  await page.goto(`/hq/${TEST_BRAND_SLUG}/notifications/routing`);
  await page.waitForLoadState("networkidle");

  // Find the email checkbox in the row labelled stock.alert.low and toggle.
  const row = page.locator("tr", { hasText: "stock.alert.low" }).first();
  const checkbox = row.getByRole("checkbox").nth(2); // 0=in_app 1=realtime 2=email 3=push
  await checkbox.uncheck();

  await expect.poll(() => emailEnabled, { timeout: 5_000 }).toBe(false);

  await page.reload();
  await page.waitForLoadState("networkidle");
  const reloadedRow = page.locator("tr", { hasText: "stock.alert.low" }).first();
  await expect(reloadedRow.getByRole("checkbox").nth(2)).not.toBeChecked();
});
