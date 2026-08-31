import { expect, test, type Route } from "@playwright/test";
import { installEchoStub } from "../../fixtures/echo";
import { defaultNotificationHandlers, mockApi } from "../../fixtures/msw";
import { signInAs } from "../../fixtures/session";

const BRAND = "betoya";

test("locale switch refetches translated backend data despite a stale HttpOnly cookie", async ({
  page,
}) => {
  await installEchoStub(page);
  await signInAs(page, { role: "brand_admin", locale: "en" });
  const configuredBaseURL = process.env.PLAYWRIGHT_BASE_URL ?? "http://127.0.0.1:5430";
  await page.context().addCookies([
    {
      name: "app_locale",
      value: "en",
      url: new URL(configuredBaseURL).origin,
      sameSite: "Lax",
      httpOnly: true,
    },
  ]);

  const productRequestLocales: string[] = [];
  await mockApi(page, [
    {
      path: `**/api/v1/hq/${BRAND}`,
      json: {
        data: {
          id: "brand-1",
          slug: BRAND,
          name: "Betoya",
          description: null,
          logo_url: null,
          primary_color: "#009444",
          secondary_color: "#FFC20E",
          accent_color: "#00B856",
          text_color: "#171614",
        },
      },
    },
    {
      path: `**/api/v1/hq/${BRAND}/products**`,
      json: (route: Route) => {
        const locale = route.request().headers()["accept-language"] ?? "missing";
        productRequestLocales.push(locale);
        const name = locale === "vi" ? "Phở bò thử nghiệm" : "Test Beef Pho";
        return {
          data: [
            {
              id: "product-1",
              organization_id: "org-1",
              brand_id: "brand-1",
              product_type_id: null,
              productType: null,
              tax_type_id: null,
              taxType: null,
              sku: "PHO-1",
              name,
              slug: "test-beef-pho",
              description: null,
              status: "active",
              is_hidden: false,
              skus_count: 1,
              review_up_count: "0",
              review_total_count: "0",
              created_at: "2026-07-22T00:00:00Z",
              updated_at: "2026-07-22T00:00:00Z",
              deleted_at: null,
            },
          ],
          meta: {
            current_page: 1,
            last_page: 1,
            total: 1,
            per_page: 25,
            from: 1,
            to: 1,
          },
        };
      },
    },
    { path: `**/api/v1/hq/${BRAND}/product-types/lookup`, json: { data: [] } },
    { path: `**/api/v1/hq/${BRAND}/categories/lookup`, json: { data: [] } },
    {
      path: "**/api/v1/me/context",
      json: {
        user: { id: "test-user-1" },
        context: { brand: { id: "brand-1", slug: BRAND }, branch: null },
      },
    },
    {
      path: "**/api/v1/me/brands",
      json: { data: [{ id: "brand-1", slug: BRAND, name: "Betoya" }] },
    },
    { path: "**/api/v1/locale", method: "POST", json: { locale: "vi" } },
    ...defaultNotificationHandlers,
  ]);

  await page.goto(`/hq/${BRAND}/products`);
  await expect(page.getByRole("link", { name: "Test Beef Pho" })).toBeVisible();
  await expect
    .poll(() => page.evaluate(() => document.cookie.includes("app_locale=")))
    .toBe(false);

  const languageButton = page.getByRole("button", { name: "Language" });
  await expect(languageButton).toBeVisible();
  await expect(languageButton).toHaveCSS("width", "44px");
  await languageButton.click();
  await page.getByRole("menuitem", { name: "Tiếng Việt" }).click();

  await expect(page.getByRole("link", { name: "Phở bò thử nghiệm" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Test Beef Pho" })).toHaveCount(0);
  await expect.poll(() => productRequestLocales.at(-1)).toBe("vi");
  await expect.poll(() => page.evaluate(() => localStorage.getItem("app_locale"))).toBe("vi");
});
