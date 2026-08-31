import { expect, test, type Page, type Response } from "@playwright/test";

const BRAND_SLUG = process.env.PLAYWRIGHT_BRAND_SLUG ?? "betoya";

function requiredEnvironment(
  name: "PLAYWRIGHT_PRODUCTION_EMAIL" | "PLAYWRIGHT_PRODUCTION_PASSWORD"
): string {
  const value = process.env[name];
  if (!value) {
    throw new Error(`${name} is required for the production SSO test.`);
  }

  return value;
}

async function responseFailure(response: Response): Promise<string | null> {
  const url = new URL(response.url());
  if (url.hostname !== "tempo.godx.jp" || !url.pathname.startsWith("/api/v1/")) {
    return null;
  }
  if (response.status() < 400) {
    return null;
  }

  const body = await response.text().catch(() => "<unreadable response body>");
  return `${response.status()} ${url.pathname}${url.search}: ${body.slice(0, 500)}`;
}

async function loginThroughPlatform(page: Page): Promise<void> {
  await page.goto(`/hq/${BRAND_SLUG}/dashboard`);
  await expect(page).toHaveURL(/^https:\/\/id\.godx\.jp\/login\?/);

  await page
    .locator('input[type="email"]')
    .fill(requiredEnvironment("PLAYWRIGHT_PRODUCTION_EMAIL"));
  await page
    .locator('input[type="password"]')
    .fill(requiredEnvironment("PLAYWRIGHT_PRODUCTION_PASSWORD"));
  await page.getByRole("button", { name: "ログイン", exact: true }).click();

  await expect(page).toHaveURL(new RegExp(`/hq/${BRAND_SLUG}/dashboard$`), { timeout: 30_000 });
  await expect(page.getByRole("heading", { name: "ダッシュボード" })).toBeVisible();
}

test("production SSO exposes the seeded Betoya catalog and menus without API errors", async ({
  page,
}) => {
  const apiFailures: string[] = [];
  const pageErrors: string[] = [];
  page.on("pageerror", (error) => pageErrors.push(error.message));
  page.on("response", async (response) => {
    const failure = await responseFailure(response);
    if (failure) apiFailures.push(failure);
  });

  await loginThroughPlatform(page);

  await page.goto("/hq");
  await expect(page).toHaveURL(new RegExp(`/hq/${BRAND_SLUG}/dashboard$`));

  await page.goto(`/hq/${BRAND_SLUG}/products`);
  await expect(page.getByRole("heading", { name: "商品" })).toBeVisible();
  await page.waitForTimeout(1_000);
  expect(apiFailures, apiFailures.join("\n")).toEqual([]);
  await expect(
    page.getByText("まだ商品がありません。NewまたはImportで追加してください。")
  ).not.toBeVisible();
  await expect(page.getByText(/total$/).first()).toHaveText("183 total");
  await page.getByRole("checkbox", { name: "削除済を表示" }).click();
  await expect(page.getByText(/total$/).first()).toHaveText("412 total");

  await page.goto(`/hq/${BRAND_SLUG}/categories`);
  await expect(page.getByRole("heading", { name: "カテゴリ" })).toBeVisible();
  await expect(page.getByText("フォー持ち帰り", { exact: true })).toBeVisible();
  await expect(page.getByText("ランチセット", { exact: true })).toBeVisible();

  await page.goto(`/hq/${BRAND_SLUG}/menus`);
  await expect(page.getByRole("heading", { name: "メニュー" })).toBeVisible();
  await expect(page.getByText("ディナー & 土日祝", { exact: true })).toBeVisible();
  await expect(page.getByText("お持ち帰り", { exact: true })).toBeVisible();
  await expect(page.getByText("ランチ", { exact: true })).toBeVisible();

  await page.goto("/shop/ningyocho/menus/019f6efa-2f83-71a8-b061-2c8f9435718a");
  await expect(page.getByRole("heading", { name: "人形町店 メニュー" })).toBeVisible();
  await expect(page.locator("[data-testid^='shop-menu-product-toggle-']")).toHaveCount(99);
  await expect(page.getByText("(無題)", { exact: true })).not.toBeVisible();

  await page.goto(`/hq/${BRAND_SLUG}/products/019f6ed5-4d4f-7020-b776-2ea6674e7580`);
  await expect(page.getByRole("heading", { name: "野菜フォー" })).toBeVisible();
  const productImage = page
    .locator("img[src*='/storage/products/019f6ed5-4d4f-7020-b776-2ea6674e7580/image.jpg']")
    .first();
  await expect(productImage).toBeVisible();
  await expect
    .poll(() => productImage.evaluate((image) => (image as HTMLImageElement).naturalWidth))
    .toBeGreaterThan(0);

  await expect.poll(() => apiFailures, { message: apiFailures.join("\n") }).toEqual([]);
  expect(pageErrors, pageErrors.join("\n")).toEqual([]);
});
