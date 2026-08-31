/**
 * Plan-023 M2 T2.2 — Sanctum session + locale stub for Playwright specs.
 *
 * `apiFetch` reads the bearer token from a `token` cookie (with a
 * `localStorage.token` fallback) and Accept-Language from `app_locale`
 * (see admin-web/src/lib/api.ts). This fixture writes both so a spec can
 * land on any authenticated page without going through the full SSO
 * callback. Backend calls are intercepted by `mockApi()` from `./msw.ts`
 * — no real HTTP traffic leaves the test runner.
 *
 * Usage:
 *   import { signInAs } from "../fixtures/session";
 *   test.beforeEach(async ({ page }) => {
 *     await signInAs(page, { role: "brand_admin", orgId: "org-1" });
 *   });
 */
import type { Page } from "@playwright/test";

export interface SessionFixture {
  /** Backend role slug the seeded user holds (brand_admin / shop_admin / …). */
  role: "brand_admin" | "shop_admin" | "warehouse_manager" | "shop_manager";
  /** Console organization id. Defaults to the canonical test org. */
  orgId?: string;
  /** App locale to stamp in the cookie. Defaults to ja. */
  locale?: "ja" | "en" | "vi";
  /** Bearer token written to the cookie. Default is a deterministic stub. */
  token?: string;
  /** User id consumed by `useNotificationRealtime` channel naming. */
  userId?: string | number;
}

export async function signInAs(page: Page, fixture: SessionFixture): Promise<void> {
  const orgId = fixture.orgId ?? "00000000-0000-0000-0000-000000000001";
  const locale = fixture.locale ?? "ja";
  const token = fixture.token ?? "playwright-stub-token";
  const userId = fixture.userId ?? "test-user-1";

  // Cookies must be installed BEFORE any navigation so apiFetch sees them on
  // the first request.
  const configuredBaseURL = process.env.PLAYWRIGHT_BASE_URL ?? "http://127.0.0.1:5430";
  const cookieURL = new URL(configuredBaseURL).origin;
  await page.context().addCookies([
    {
      name: "token",
      value: token,
      url: cookieURL,
      sameSite: "Lax",
    },
    {
      name: "app_locale",
      value: locale,
      url: cookieURL,
      sameSite: "Lax",
    },
  ]);

  // Mirror in localStorage so legacy code paths agree with the cookie source
  // of truth, and stash the fixture details so specs can pull them back out.
  await page.addInitScript(
    ([t, u, r, o]) => {
      try {
        window.localStorage.setItem("token", t);
        window.localStorage.setItem("__playwrightSession", JSON.stringify({ userId: u, role: r, orgId: o }));
      } catch {
        // localStorage may be unavailable in cross-origin frames — swallow.
      }
    },
    [token, String(userId), fixture.role, orgId] as const,
  );
}

/**
 * Read whatever signInAs stashed back out, from inside a spec. Useful when
 * a spec wants to assert against the same userId the realtime channel
 * subscription would target.
 */
export async function currentSession(page: Page): Promise<{ userId: string; role: string; orgId: string } | null> {
  return page.evaluate(() => {
    const raw = window.localStorage.getItem("__playwrightSession");
    return raw ? (JSON.parse(raw) as { userId: string; role: string; orgId: string }) : null;
  });
}
