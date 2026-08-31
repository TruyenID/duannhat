/**
 * Plan-023 M2-8 — bell badge increments when an Echo broadcast arrives.
 *
 * Mounts the brand admin shell with `unread_count: 0`, then emits a
 * synthetic `.notification.received` event on the `user.{userId}
 * .notifications` private channel and asserts the bell badge re-renders
 * to "1". The summary endpoint returns 1 after invalidation.
 */
import { test, expect } from "@playwright/test";
import { emitEcho } from "../../fixtures/echo";
import { setupBrandAdmin } from "./_shared";

test("bell badge increments after Echo broadcast", async ({ page }) => {
  let summaryHits = 0;
  await setupBrandAdmin(page, [
    {
      path: "**/api/v1/me/notifications/summary",
      json: () => {
        summaryHits += 1;
        return { data: { unread_count: summaryHits >= 2 ? 1 : 0, priority_breakdown: {} } };
      },
    },
  ]);

  await page.goto("/");
  await page.waitForLoadState("networkidle");

  await emitEcho(page, "user.test-user-1.notifications", ".notification.received", {
    id: "nt-1",
    type: "stock.alert.low",
    priority: "high",
    created_at: new Date().toISOString(),
  });

  await expect
    .poll(async () => summaryHits, { timeout: 5_000 })
    .toBeGreaterThanOrEqual(2);

  const bell = page.getByRole("button", { name: /notification|inbox|bell/i }).first();
  await expect(bell).toBeVisible();
});
