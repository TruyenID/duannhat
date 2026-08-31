import { expect, test, type Page, type Response } from "@playwright/test";
import { signInAs } from "../fixtures/session";

const MENU_ID = "019f6efa-2f83-71a8-b061-2c8f9435718a";
const PRODUCT_ID = "019f6ed5-4d4f-7020-b776-2ea6674e7580";

function failure(response: Response): string | null {
  const url = new URL(response.url());
  const isApplicationRequest =
    url.pathname.startsWith("/api/") || url.pathname.startsWith("/storage/");
  if (!isApplicationRequest || response.status() < 400) return null;

  return `${response.status()} ${url.pathname}${url.search}`;
}

async function visitScreen(page: Page, path: string, failures: string[]): Promise<void> {
  const response = await page.goto(path, { waitUntil: "domcontentloaded" });
  if (!response || response.status() >= 400) {
    failures.push(`${path}: document ${response?.status() ?? "missing"}`);
  }
  if (/\/login(?:\?|$)/.test(page.url())) {
    failures.push(`${path}: redirected to login`);
  }
  await expect(page.locator("body")).toBeVisible();
  if (await page.getByText("Application error", { exact: false }).isVisible()) {
    failures.push(`${path}: rendered Application error`);
  }
  await page.waitForTimeout(400);
}

test("fresh production seed boots every seeded Betoya management surface", async ({ page }) => {
  test.setTimeout(180_000);
  await signInAs(page, {
    role: "brand_admin",
    token: process.env.PLAYWRIGHT_SEEDED_TOKEN ?? "dev:seed-audit-admin",
    locale: "ja",
  });

  const failures: string[] = [];
  const pageErrors: string[] = [];
  page.on("pageerror", (error) => pageErrors.push(error.message));
  page.on("response", (response) => {
    const message = failure(response);
    if (message) failures.push(`${new URL(page.url()).pathname} -> ${message}`);
  });

  const screens = [
    "/select-context",
    "/inbox",
    "/me/settings/notifications",
    "/hq/betoya/dashboard",
    "/hq/betoya/allergens",
    "/hq/betoya/categories",
    "/hq/betoya/coupons",
    "/hq/betoya/coupons/new",
    "/hq/betoya/customers",
    "/hq/betoya/devices",
    "/hq/betoya/iam/members",
    "/hq/betoya/iam/permissions",
    "/hq/betoya/material-lots",
    "/hq/betoya/materials",
    "/hq/betoya/materials/new",
    "/hq/betoya/menus",
    "/hq/betoya/notifications",
    "/hq/betoya/notifications/audiences",
    "/hq/betoya/notifications/compose",
    "/hq/betoya/notifications/email-health",
    "/hq/betoya/notifications/routing",
    "/hq/betoya/notifications/rules",
    "/hq/betoya/notifications/schedules",
    "/hq/betoya/notifications/templates",
    "/hq/betoya/orders",
    "/hq/betoya/product-types",
    "/hq/betoya/products",
    "/hq/betoya/products/new",
    `/hq/betoya/products/${PRODUCT_ID}`,
    `/hq/betoya/products/${PRODUCT_ID}/skus/019f6ed5-4d7d-7112-b8df-15e36fcdf49b`,
    "/hq/betoya/promotions",
    "/hq/betoya/recalls",
    "/hq/betoya/recalls/drills",
    "/hq/betoya/recalls/new",
    "/hq/betoya/recipes",
    "/hq/betoya/recipes/new",
    "/hq/betoya/reports/supplier-yield",
    "/hq/betoya/settings",
    "/hq/betoya/settings/cart-timeout",
    "/hq/betoya/settings/payment-methods",
    "/hq/betoya/settings/payment-methods/new",
    "/hq/betoya/settings/reverb",
    "/hq/betoya/settings/table-status",
    "/hq/betoya/settings/takeaway-payment",
    "/hq/betoya/shops",
    "/hq/betoya/tax-types",
    "/hq/betoya/topping-groups",
    "/hq/betoya/trace",
    "/shop/ningyocho/customers",
    "/shop/ningyocho/customers/new",
    "/shop/ningyocho/dashboard",
    "/shop/ningyocho/devices",
    "/shop/ningyocho/material-lots",
    "/shop/ningyocho/material-lots/receive",
    "/shop/ningyocho/menus",
    `/shop/ningyocho/menus/${MENU_ID}`,
    `/shop/ningyocho/menus/${MENU_ID}/schedules`,
    "/shop/ningyocho/notifications",
    "/shop/ningyocho/notifications/audiences",
    "/shop/ningyocho/notifications/compose",
    "/shop/ningyocho/notifications/routing",
    "/shop/ningyocho/notifications/templates",
    "/shop/ningyocho/orders",
    "/shop/ningyocho/orders/new",
    "/shop/ningyocho/production/batches",
    "/shop/ningyocho/production/batches/new",
    "/shop/ningyocho/production/calculator",
    "/shop/ningyocho/production/orders",
    "/shop/ningyocho/production/orders/new",
    "/shop/ningyocho/promotions",
    "/shop/ningyocho/promotions/new",
    "/shop/ningyocho/settings",
    "/shop/ningyocho/settings/order",
    "/shop/ningyocho/stock/alerts",
    "/shop/ningyocho/stock/counts",
    "/shop/ningyocho/stock/disposals",
    "/shop/ningyocho/stock/disposals/new",
    "/shop/ningyocho/stock/disposals/waste-report",
    "/shop/ningyocho/stock/levels",
    "/shop/ningyocho/stock/transactions",
    "/shop/ningyocho/stock/transactions/new",
    "/shop/ningyocho/stock/transfers",
    "/shop/ningyocho/stock/transfers/new",
    "/shop/ningyocho/tables",
    "/shop/ningyocho/till",
    "/shop/ningyocho/till/sessions",
    "/shop/ningyocho/warehouses",
    "/shop/ningyocho/warehouses/new",
  ];

  for (const path of screens) {
    await visitScreen(page, path, failures);
  }

  await visitScreen(page, `/shop/ningyocho/menus/${MENU_ID}`, failures);
  await expect(page.getByRole("heading", { name: "人形町店 メニュー" })).toBeVisible();
  await expect(page.locator("[data-testid^='shop-menu-product-toggle-']")).toHaveCount(99);
  await expect(page.getByText("(無題)", { exact: true })).not.toBeVisible();

  const productImage = page
    .locator(`img[src*='/storage/products/${PRODUCT_ID}/image.jpg']`)
    .first();
  await expect(productImage).toBeVisible();
  await expect
    .poll(() => productImage.evaluate((image) => (image as HTMLImageElement).naturalWidth))
    .toBeGreaterThan(0);

  expect(pageErrors, pageErrors.join("\n")).toEqual([]);
  expect(failures, failures.join("\n")).toEqual([]);
});
