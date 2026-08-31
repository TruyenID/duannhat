/**
 * Seeded E2E — shop peripheral-device registration (P400 / 釣銭機).
 *
 * Drives the real UI at /shop/<slug>/peripheral-devices against a running,
 * seeded backend (SSO dev-bypass). Proves the whole chain a live click-through
 * exercises: form → BFF → backend validation → DB → list render, including the
 * network-address (metadata.host/port) fields that sync DOWN to the workstation.
 *
 * Env (defaults target the Famgia docker seed):
 *   PLAYWRIGHT_SEEDED_SHOP   shop slug           (default "sjk")
 *   PLAYWRIGHT_SEEDED_TOKEN  dev-bypass token    (default "dev:<famgia admin sub>")
 *   PLAYWRIGHT_SEEDED_ORG    console org id      (default Famgia console org)
 *
 * Requires the backend to run with SSO_DEV_BYPASS=true and the token's subject
 * in SSO_DEV_BYPASS_SUBJECTS.
 */
import { expect, test, type Page } from "@playwright/test";
import { signInAs } from "../fixtures/session";

const SHOP = process.env.PLAYWRIGHT_SEEDED_SHOP ?? "sjk";
const TOKEN = process.env.PLAYWRIGHT_SEEDED_TOKEN ?? "dev:019e8a3b-8001-7a00-8001-000000000001";
const ORG = process.env.PLAYWRIGHT_SEEDED_ORG ?? "00000000-aaaa-4aaa-aaaa-000000000001";
const BASE = `/shop/${SHOP}/peripheral-devices`;
const API = `/api/v1/shops/${SHOP}/peripheral-devices`;

// Unique per run so the branch-scoped name-uniqueness rule never trips on re-run.
const RUN = String(Date.now()).slice(-6);

test.describe("shop peripheral-device registration", () => {
  test.beforeEach(async ({ page }) => {
    await signInAs(page, { role: "shop_manager", token: TOKEN, orgId: ORG, locale: "ja" });
  });

  // Remove anything this run created, whatever the outcome, via the same API.
  test.afterEach(async ({ page }) => {
    const res = await page.request.get(`${API}?search=E2E-${RUN}&with_trashed=1&per_page=100`);
    if (!res.ok()) return;
    const body = (await res.json()) as { data?: Array<{ id: string }> };
    for (const row of body.data ?? []) {
      await page.request.delete(`${API}/${row.id}`);
    }
  });

  test("registers a 釣銭機 with host/port and shows it in the list", async ({ page }) => {
    // ASCII-only name so the type-cell "釣銭機" assertion stays unambiguous.
    const name = `E2E-${RUN} changer`;
    await gotoList(page);

    await page.getByRole("button", { name: "機器を登録" }).click();
    const dialog = page.locator('[data-slot="peripheral-device-form-dialog"]');
    await expect(dialog).toBeVisible();

    // Switch the type to 釣銭機 (coin_changer) — host/port must appear for it.
    await dialog.getByRole("combobox").first().click();
    await page.getByRole("option", { name: "釣銭機" }).click();

    await dialog.getByPlaceholder("例：レジ横 P400").fill(name);
    await dialog.getByPlaceholder("192.168.0.77").fill("192.168.0.10");
    await dialog.getByPlaceholder("80").fill("8080");
    await dialog.getByRole("button", { name: "保存" }).click();

    await expect(dialog).toBeHidden();

    // The new row renders with its type label + resolved address.
    const row = page.getByRole("row", { name: new RegExp(name) });
    await expect(row).toBeVisible();
    await expect(row.getByRole("cell", { name: "釣銭機", exact: true })).toBeVisible();
    await expect(row.getByText("192.168.0.10:8080")).toBeVisible();
  });

  test("blocks a payment_terminal with no host (backend 422 → field error)", async ({ page }) => {
    const name = `E2E-${RUN} P400-nohost`;
    await gotoList(page);

    await page.getByRole("button", { name: "機器を登録" }).click();
    const dialog = page.locator('[data-slot="peripheral-device-form-dialog"]');
    await expect(dialog).toBeVisible();

    // Default type is payment_terminal — fill the name but leave host empty.
    await dialog.getByPlaceholder("例：レジ横 P400").fill(name);
    await dialog.getByRole("button", { name: "保存" }).click();

    // Dialog stays open with the host validation error surfaced under the field.
    await expect(dialog).toBeVisible();
    await expect(dialog.getByText(/host/i)).toBeVisible();
  });
});

async function gotoList(page: Page): Promise<void> {
  const res = await page.goto(BASE, { waitUntil: "domcontentloaded" });
  expect(res?.status(), "peripheral-devices page should load").toBeLessThan(400);
  expect(page.url(), "should not bounce to login").not.toMatch(/\/login(?:\?|$)/);
  await expect(page.getByRole("button", { name: "機器を登録" })).toBeVisible();
}
