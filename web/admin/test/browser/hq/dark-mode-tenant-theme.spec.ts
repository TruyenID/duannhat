import { expect, test, type Page } from "@playwright/test";
import { installEchoStub } from "../../fixtures/echo";
import { defaultNotificationHandlers, mockApi } from "../../fixtures/msw";
import { signInAs } from "../../fixtures/session";

const BRAND = "betoya";

async function setup(page: Page, theme: "light" | "dark") {
  await installEchoStub(page);
  await signInAs(page, { role: "brand_admin", locale: "en" });
  await page.addInitScript((initialTheme) => {
    window.localStorage.setItem("app_theme", initialTheme);
  }, theme);
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
      path: `**/api/v1/hq/${BRAND}/dashboard/kpis**`,
      json: {
        data: {
          revenue: { value: 120000, delta_pct: 12 },
          orders: { value: 24, delta_pct: 5 },
          products: { value: 179, delta_pct: 0 },
          shops: { value: 2, delta_pct: 0 },
        },
      },
    },
    { path: `**/api/v1/hq/${BRAND}/dashboard/**`, json: { data: [] } },
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
    ...defaultNotificationHandlers,
  ]);
}

async function semanticContrast(page: Page, foreground: string, background: string) {
  return page.evaluate(
    ([foregroundToken, backgroundToken]) => {
      const probe = document.createElement("div");
      probe.style.color = `var(${foregroundToken})`;
      probe.style.backgroundColor = `var(${backgroundToken})`;
      document.body.append(probe);

      const styles = getComputedStyle(probe);
      const colors = [styles.color, styles.backgroundColor];
      probe.remove();

      const canvas = document.createElement("canvas");
      canvas.width = 1;
      canvas.height = 1;
      const context = canvas.getContext("2d", { willReadFrequently: true });
      if (!context) throw new Error("Canvas context unavailable");

      const luminance = (color: string) => {
        context.clearRect(0, 0, 1, 1);
        context.fillStyle = color;
        context.fillRect(0, 0, 1, 1);
        const [red, green, blue] = context.getImageData(0, 0, 1, 1).data;
        const linear = [red, green, blue].map((channel) => {
          const value = channel / 255;
          return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2];
      };

      const first = luminance(colors[0]);
      const second = luminance(colors[1]);
      return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
    },
    [foreground, background] as const
  );
}

async function expectAccessibleTheme(page: Page) {
  await expect(page.getByRole("heading", { name: "Dashboard" })).toBeVisible();
  await expect
    .poll(() => semanticContrast(page, "--foreground", "--background"))
    .toBeGreaterThanOrEqual(4.5);
  await expect
    .poll(() => semanticContrast(page, "--card-foreground", "--card"))
    .toBeGreaterThanOrEqual(4.5);
  await expect
    .poll(() => semanticContrast(page, "--muted-foreground", "--card"))
    .toBeGreaterThanOrEqual(4.5);
}

test("brand text color keeps accessible contrast when switching from light to dark", async ({
  page,
}) => {
  await setup(page, "light");
  await page.goto(`/hq/${BRAND}/dashboard`);

  await expectAccessibleTheme(page);
  await expect
    .poll(() => page.evaluate(() => document.body.style.getPropertyValue("--tenant-foreground")))
    .toBe("#171614");
  await expect
    .poll(() => page.evaluate(() => document.body.style.getPropertyValue("--foreground")))
    .toBe("");

  await page.locator("header button:has(svg.lucide-sun)").click();
  await expect(page.locator("html")).toHaveClass(/dark/);
  await expectAccessibleTheme(page);
  await expect
    .poll(() =>
      page.evaluate(() => getComputedStyle(document.body).getPropertyValue("--foreground").trim())
    )
    .not.toBe("#171614");
});

test("dark tenant dashboard remains readable at a mobile viewport", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await setup(page, "dark");
  await page.goto(`/hq/${BRAND}/dashboard`);

  await expect(page.locator("html")).toHaveClass(/dark/);
  await expectAccessibleTheme(page);
  await expect(page.getByText("Revenue", { exact: true }).first()).toBeVisible();
});
