import { expect, test } from "@playwright/test";
import { installEchoStub } from "../../fixtures/echo";
import { mockApi } from "../../fixtures/msw";
import { signInAs } from "../../fixtures/session";

const SHOP_SLUG = "ningyocho";
const MENU_ID = "019f6efa-2f83-71a8-b061-2c8f9435718a";

test("renders all products from the shop menu detail response", async ({ page }) => {
  await installEchoStub(page);
  await signInAs(page, { role: "shop_manager", locale: "ja" });

  const products = Array.from({ length: 175 }, (_, index) => ({
    id: `menu-product-${index + 1}`,
    menu_id: MENU_ID,
    product_id: `product-${index + 1}`,
    menu_section_id: "section-main",
    is_active: true,
    display_order: index,
    master_menu_product_id: null,
    section: { id: "section-main", name: "メイン" },
    skus: [],
    product: {
      id: `product-${index + 1}`,
      name: `商品 ${index + 1}`,
      description: null,
      image_url: null,
    },
    created_at: "2026-07-21T00:00:00.000Z",
    updated_at: "2026-07-21T00:00:00.000Z",
  }));

  await mockApi(page, [
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
          name: "人形町店",
          code: "NINGYOCHO",
          is_headquarters: false,
          console_brand_id: "brand-betoya",
          brand_slug: "betoya",
        },
      },
    },
    {
      path: `**/api/v1/shops/${SHOP_SLUG}/menus/${MENU_ID}?compact=1`,
      json: {
        data: {
          id: MENU_ID,
          name: "人形町店 メニュー",
          description: null,
          status: "Active",
          priority: 201,
          valid_from: null,
          valid_to: null,
          is_master: false,
          master_menu_id: null,
          branch_id: "branch-ningyocho",
          menu_products: products,
          menuSections: [{ id: "section-main", name: "メイン", display_order: 0 }],
          hq_brand_timeout_minutes: null,
          hq_menu_timeout_minutes: null,
          shop_default_timeout_minutes: null,
          shop_menu_timeout_minutes: null,
          effective_timeout_minutes: null,
          has_schedules: false,
          created_at: "2026-07-21T00:00:00.000Z",
          updated_at: "2026-07-21T00:00:00.000Z",
        },
      },
    },
  ]);

  await page.goto(`/shop/${SHOP_SLUG}/menus/${MENU_ID}`);

  await expect(page.getByRole("heading", { name: "人形町店 メニュー" })).toBeVisible();
  await expect(page.getByText(/175/).first()).toBeVisible();
  await expect(page.getByText("商品 1", { exact: true })).toBeVisible();
  await expect(page.getByText("商品 175", { exact: true })).toBeVisible();
  await expect(page.getByText("このセクションに商品はありません")).not.toBeVisible();
});
