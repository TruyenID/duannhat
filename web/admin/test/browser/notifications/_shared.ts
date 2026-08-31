/**
 * Shared beforeEach + page-object helpers for the 9 notification browser
 * specs (plan-023 M2 T2.3). Each spec imports `setupBrandAdmin()` to
 * land on the brand-scoped admin shell with a stubbed session, stubbed
 * Echo, and mocked notification background queries.
 */
import type { Page } from "@playwright/test";
import { installEchoStub } from "../../fixtures/echo";
import { signInAs } from "../../fixtures/session";
import { defaultNotificationHandlers, mockApi, type RouteHandler } from "../../fixtures/msw";

export const TEST_BRAND_SLUG = "playwright-brand";

export async function setupBrandAdmin(page: Page, extraHandlers: RouteHandler[] = []): Promise<void> {
  await installEchoStub(page);
  await signInAs(page, { role: "brand_admin", orgId: "00000000-0000-0000-0000-000000000001", userId: "test-user-1" });
  await mockApi(page, [
    ...extraHandlers,
    ...defaultNotificationHandlers,
    {
      path: `**/api/v1/me/brands`,
      json: {
        data: [
          {
            id: "brand-1",
            slug: TEST_BRAND_SLUG,
            name: "Playwright Brand",
            console_organization_id: "00000000-0000-0000-0000-000000000001",
          },
        ],
      },
    },
  ]);
}
