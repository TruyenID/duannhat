import { expect, test } from "@playwright/test";

/**
 * Prod probe — Stripe wallet buttons + backend/domain prerequisites.
 * Does NOT submit a payment. Read-only smoke for Apple Pay / Google Pay setup.
 */
test("prod stripe wallets probe (menu.vietorigin.jp)", async ({ page }) => {
  const apiChecks: Record<string, unknown> = {};

  page.on("response", async (response) => {
    const url = response.url();
    if (url.includes("/api/v1/customer/stripe/config")) {
      apiChecks.stripeConfig = {
        status: response.status(),
        body: await response.json().catch(() => null),
      };
    }
  });

  await page.goto("/ja/takeaway/jimbocho", { waitUntil: "domcontentloaded" });

  const products = page.locator("main h3:visible");
  await expect(products.first()).toBeVisible({ timeout: 15_000 });
  await products.first().click();

  const dialog = page.getByRole("dialog").first();
  await expect(dialog).toBeVisible();
  const addButton = dialog
    .locator("button")
    .filter({ hasText: /カートに追加|Add to cart|Thêm vào giỏ/ })
    .last();
  await expect(addButton).toBeEnabled();
  await addButton.click();
  await expect(dialog).toBeHidden();

  const cartButton = page
    .locator("button")
    .filter({ hasText: /カートを見る|View cart|Xem giỏ hàng/ })
    .last();
  await expect(cartButton).toBeVisible();
  await cartButton.click();

  const cartDialog = page.getByRole("dialog");
  await expect(cartDialog).toBeVisible();
  await cartDialog
    .getByRole("button", { name: /注文を確定|Confirm order|Xác nhận/i })
    .click();

  await expect(page.getByRole("heading", { name: /注文確認|Order|Checkout/i })).toBeVisible({
    timeout: 15_000,
  });

  await page.getByPlaceholder(/お名前|name/i).fill("Wallet Probe");
  await page.getByPlaceholder(/電話|phone/i).fill("09012345678");

  const cardRadio = page.getByRole("radio", { name: /クレジット|デビット|Card|Thẻ/i });
  await cardRadio.check();

  // Stripe mounts inside iframes — outer page may not expose #payment-element.
  await expect(page.locator("iframe").first()).toBeVisible({ timeout: 30_000 });
  await page.waitForTimeout(4_000);

  const frameTexts: string[] = [];
  for (const frame of page.frames()) {
    try {
      const text = await frame.locator("body").innerText({ timeout: 2_000 });
      if (text) frameTexts.push(text.slice(0, 500));
    } catch {
      // cross-origin or empty
    }
  }

  const bodyText = await page.locator("body").innerText();
  const walletHints = [
    "Apple Pay",
    "Google Pay",
    "Pay with Link",
    "Link",
    "ウォレット",
  ];
  const foundHints = walletHints.filter(
    (hint) =>
      bodyText.includes(hint) ||
      frameTexts.some((t) => t.includes(hint)),
  );

  const deployedBundle = await page.evaluate(async () => {
    const urls = new Set<string>();
    for (const s of document.querySelectorAll("script[src]")) {
      urls.add((s as HTMLScriptElement).src);
    }
    if ("webpackChunk_N_E" in window || "__NEXT_DATA__" in window) {
      // Best-effort: also scan already-fetched module scripts from performance API.
      for (const entry of performance.getEntriesByType("resource")) {
        const name = (entry as PerformanceResourceTiming).name;
        if (name.includes("_next/static") && name.endsWith(".js")) urls.add(name);
      }
    }
    for (const src of urls) {
      try {
        // Chạy TRONG trình duyệt qua page.evaluate: tải file JS tĩnh đã deploy
        // để soi nội dung bundle. Không phải lời gọi API, nên `apiFetch` (auth
        // header + Accept-Language + redirect 401) vừa thừa vừa không tồn tại
        // trong ngữ cảnh này — nó là module của app, không phải của trang đích.
        // eslint-disable-next-line no-restricted-globals -- browser-context asset fetch, not an API call
        const js = await (await fetch(src)).text();
        if (!js.includes("applePay")) continue;
        if (js.includes("applePay:\"never\"") || js.includes('applePay:"never"')) {
          return "applePay:never (deployed)";
        }
        if (js.includes("applePay:\"auto\"") || js.includes('applePay:"auto"')) {
          return "applePay:auto (deployed)";
        }
        return "applePay:present-but-minified";
      } catch {
        // ignore
      }
    }
    return "applePay:not-found-in-loaded-scripts";
  });

  const report = {
    apiChecks,
    deployedBundle,
    foundWalletHints: foundHints,
    frameCount: page.frames().length,
    note:
      "Wallet buttons only render on eligible devices/browsers even when configured correctly.",
  };

  console.log(JSON.stringify(report, null, 2));

  expect(apiChecks.stripeConfig).toBeTruthy();
  expect((apiChecks.stripeConfig as { status: number }).status).toBe(200);
  expect(
    ((apiChecks.stripeConfig as { body: { data: { publishable_key: string } } }).body
      ?.data?.publishable_key ?? "") as string,
  ).toMatch(/^pk_live_/);
});
