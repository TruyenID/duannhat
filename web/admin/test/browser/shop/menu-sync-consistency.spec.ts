import { expect, test, type Page, type Route } from "@playwright/test";
import { installEchoStub } from "../../fixtures/echo";
import { defaultNotificationHandlers, mockApi, type RouteHandler } from "../../fixtures/msw";
import { signInAs } from "../../fixtures/session";

const SHOP_SLUG = "ningyocho";
const MENU_ID = "019f6efa-2f83-71a8-b061-2c8f9435718a";
const MASTER_MENU_ID = "019f6efa-2f83-71a8-b061-2c8f9435718b";

function placement(index: number, productNumber: number, sectionId: string) {
  return {
    id: `menu-product-${index}`,
    menu_id: MENU_ID,
    product_id: `product-${productNumber}`,
    menu_section_id: sectionId,
    is_active: true,
    display_order: index,
    master_menu_product_id: `master-menu-product-${index}`,
    section: { id: sectionId, name: sectionId === "recommended" ? "Recommended" : "Main" },
    skus: [],
    product: {
      id: `product-${productNumber}`,
      name: `Product ${productNumber}`,
      description: null,
      image_url: null,
    },
    created_at: "2026-07-20T00:00:00.000Z",
    updated_at: "2026-07-20T00:00:00.000Z",
  };
}

// 73 distinct products plus two legitimate duplicate placements in the
// Recommended section reproduces the original 75-row / 73-product report.
const placements = [
  ...Array.from({ length: 73 }, (_, index) => placement(index + 1, index + 1, "main")),
  placement(74, 1, "recommended"),
  placement(75, 2, "recommended"),
];

function branchMenu(serviceType: "Both" | "DineIn" = "Both") {
  return {
    id: MENU_ID,
    name: "Dinner & weekends",
    description: null,
    status: "Active",
    priority: 1,
    valid_from: null,
    valid_to: null,
    is_master: false,
    master_menu_id: MASTER_MENU_ID,
    branch_id: "branch-ningyocho",
    menu_products_count: 73,
    menu_products: placements,
    menuSections: [
      { id: "recommended", name: "Recommended", display_order: 0 },
      { id: "main", name: "Main", display_order: 1 },
    ],
    hq_service_type: "DineIn",
    shop_service_type: serviceType,
    effective_service_type: serviceType,
    hq_brand_timeout_minutes: null,
    hq_menu_timeout_minutes: null,
    shop_default_timeout_minutes: null,
    shop_menu_timeout_minutes: null,
    effective_timeout_minutes: null,
    has_schedules: false,
    created_at: "2026-07-20T00:00:00.000Z",
    updated_at: "2026-07-20T00:00:00.000Z",
  };
}

async function setup(page: Page, extraHandlers: RouteHandler[]) {
  await installEchoStub(page);
  await signInAs(page, { role: "shop_manager", locale: "en" });
  await mockApi(page, [
    ...extraHandlers,
    ...defaultNotificationHandlers,
    {
      path: "**/api/v1/me/context",
      json: {
        user: { id: "test-user-1" },
        context: { brand: null, branch: { id: "branch-ningyocho", slug: SHOP_SLUG } },
      },
    },
    {
      path: `**/api/v1/shops/${SHOP_SLUG}`,
      json: {
        data: {
          id: "branch-ningyocho",
          slug: SHOP_SLUG,
          name: "Ningyocho",
          code: "NINGYOCHO",
          is_headquarters: false,
          console_brand_id: "brand-betoya",
          brand_slug: "betoya",
        },
      },
    },
  ]);
}

test("keeps list/detail at 73 distinct products and mirrors HQ service type after confirmed sync", async ({ page }) => {
  let synced = false;
  let syncCalls = 0;
  const currentMenu = () => branchMenu(synced ? "DineIn" : "Both");

  await setup(page, [
    {
      path: `**/api/v1/shops/${SHOP_SLUG}/menus?**`,
      json: () => ({
        data: [currentMenu()],
        meta: { current_page: 1, last_page: 1, total: 1, per_page: 25, from: 1, to: 1 },
      }),
    },
    {
      path: `**/api/v1/shops/${SHOP_SLUG}/menus/${MENU_ID}?compact=1`,
      json: () => ({ data: currentMenu() }),
    },
    {
      path: `**/api/v1/shops/${SHOP_SLUG}/menus/${MENU_ID}/sync`,
      method: "POST",
      json: (route: Route) => {
        expect(route.request().postData()).toBeNull();
        syncCalls += 1;
        synced = true;
        return { data: currentMenu() };
      },
    },
  ]);

  await page.goto(`/shop/${SHOP_SLUG}/menus`);
  const card = page.getByTestId(`shop-menu-card-${MENU_ID}`);
  await expect(card).toContainText("73 items");

  // Cancel is side-effect free.
  await page.getByTestId(`shop-menu-card-sync-${MENU_ID}`).click();
  await expect(page.getByRole("dialog", { name: "Sync from HQ?" })).toBeVisible();
  await page.getByRole("button", { name: "Cancel", exact: true }).click();
  expect(syncCalls).toBe(0);

  await card.getByRole("link", { name: "Dinner & weekends" }).click();
  await expect(page).toHaveURL(new RegExp(`/shop/${SHOP_SLUG}/menus/${MENU_ID}$`));
  const title = page.getByRole("heading", { name: "Dinner & weekends" });
  await expect(title.locator("..")).toContainText("73 products · 2 sections");
  await expect(page.getByRole("button", { name: "Both", exact: true })).toBeVisible();

  await page.getByTestId("shop-menu-sync").click();
  await page.getByTestId("shop-menu-sync-confirm").click();

  await expect.poll(() => syncCalls).toBe(1);
  await expect(page.getByText("Synced from master menu.", { exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Dine-in", exact: true })).toBeVisible();
  await expect(title.locator("..")).toContainText("73 products · 2 sections");
  await expect(page.getByText("Product 73", { exact: true })).toBeVisible();

  await page.getByRole("link", { name: "Menus", exact: true }).click();
  await expect(page).toHaveURL(new RegExp(`/shop/${SHOP_SLUG}/menus$`));
  await expect(page.getByTestId(`shop-menu-card-${MENU_ID}`)).toContainText("73 items");
});

test("shows sync failure without applying the HQ service type", async ({ page }) => {
  let syncCalls = 0;

  await setup(page, [
    {
      path: `**/api/v1/shops/${SHOP_SLUG}/menus/${MENU_ID}?compact=1`,
      json: { data: branchMenu("Both") },
    },
    {
      path: `**/api/v1/shops/${SHOP_SLUG}/menus/${MENU_ID}/sync`,
      method: "POST",
      status: 500,
      json: () => {
        syncCalls += 1;
        return { message: "Sync failed safely" };
      },
    },
  ]);

  await page.goto(`/shop/${SHOP_SLUG}/menus/${MENU_ID}`);
  await expect(page.getByRole("button", { name: "Both", exact: true })).toBeVisible();
  await page.getByTestId("shop-menu-sync").click();
  await page.getByTestId("shop-menu-sync-confirm").click();

  await expect.poll(() => syncCalls).toBe(1);
  await expect(page.getByText("Sync failed safely", { exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Both", exact: true })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Dinner & weekends" }).locator(".."))
    .toContainText("73 products · 2 sections");
});
