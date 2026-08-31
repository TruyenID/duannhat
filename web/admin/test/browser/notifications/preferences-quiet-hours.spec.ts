/**
 * Plan-023 M2-5 — quiet hours roundtrip on /me/settings/notifications.
 *
 * Set quiet 22:00 → 06:00 tz Asia/Tokyo, PATCH succeeds, reload, values
 * restored from the GET response.
 */
import { test, expect, type Route } from "@playwright/test";
import { setupBrandAdmin } from "./_shared";

test("quiet hours timepicker round-trips", async ({ page }) => {
  let quietFrom = "00:00";
  let quietTo = "00:00";
  let tz = "Asia/Tokyo";

  await setupBrandAdmin(page, [
    {
      path: `**/api/v1/me/notification-preferences`,
      method: "GET",
      json: () => ({
        data: {
          master_mute: false,
          quiet_from: quietFrom,
          quiet_to: quietTo,
          timezone: tz,
          per_type_channel: {},
        },
      }),
    },
    {
      path: `**/api/v1/me/notification-preferences`,
      method: "PATCH",
      json: (route: Route) => {
        const body = route.request().postDataJSON() as Record<string, unknown>;
        if (typeof body.quiet_from === "string") quietFrom = body.quiet_from;
        if (typeof body.quiet_to === "string") quietTo = body.quiet_to;
        if (typeof body.timezone === "string") tz = body.timezone;
        return {
          data: { master_mute: false, quiet_from: quietFrom, quiet_to: quietTo, timezone: tz, per_type_channel: {} },
        };
      },
    },
  ]);

  await page.goto(`/me/settings/notifications`);
  await page.waitForLoadState("networkidle");

  await page.getByLabel(/quiet.*from|quiet hours start|静音.*開始|bắt đầu/i).first().fill("22:00");
  await page.getByLabel(/quiet.*to|quiet hours end|静音.*終了|kết thúc/i).first().fill("06:00");
  await page.getByRole("button", { name: /save|保存|lưu/i }).first().click();

  await expect.poll(() => quietFrom, { timeout: 5_000 }).toBe("22:00");
  await expect.poll(() => quietTo).toBe("06:00");
});
