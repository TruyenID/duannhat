/**
 * Plan-023 M2 T2.2 — backend mocks for Playwright specs.
 *
 * Rather than pull in `msw`, we use Playwright's native `page.route()`
 * which is purpose-built for this. The shape mirrors MSW so swapping in
 * the real package later is trivial.
 *
 * Each fixture exposes a small set of route handlers — pick the ones
 * the spec needs and pass them to `mockApi(page, [...])`. Defaults
 * answer the common boilerplate (user context, notification summary,
 * reverb config) so a spec can land on a page without erroring out on
 * background queries.
 */
import type { Page, Route } from "@playwright/test";

export type RouteHandler = {
  /** Glob path relative to baseURL, e.g. "**\/api/v1/me/notifications/summary". */
  path: string;
  /** HTTP method to match. Defaults to GET. */
  method?: "GET" | "POST" | "PATCH" | "DELETE" | "PUT";
  /** Static JSON body OR a fn that produces one from the Route. */
  json: unknown | ((route: Route) => unknown | Promise<unknown>);
  /** Optional HTTP status. Defaults to 200. */
  status?: number;
};

/**
 * Apply a list of handlers to the page. First match wins — order matters.
 */
export async function mockApi(page: Page, handlers: RouteHandler[]): Promise<void> {
  await page.route("**/api/v1/**", async (route) => {
    const req = route.request();
    const match = handlers.find(
      (h) =>
        (h.method ?? "GET") === req.method() &&
        matchGlob(req.url(), h.path),
    );
    if (match === undefined) {
      // Default to an empty 200 — beats letting the spec hang on an
      // unmocked background request.
      return route.fulfill({ status: 200, json: { data: [] } });
    }
    const body = typeof match.json === "function" ? await match.json(route) : match.json;
    return route.fulfill({
      status: match.status ?? 200,
      contentType: "application/json",
      body: JSON.stringify(body),
    });
  });
}

/**
 * The minimum set of handlers a notification-page spec needs to render
 * without background errors. Spread + override to customise per spec.
 */
export const defaultNotificationHandlers: RouteHandler[] = [
  {
    path: "**/api/v1/me",
    json: {
      data: {
        id: "test-user-1",
        name: "Playwright User",
        email: "playwright@example.com",
        locale: "ja",
        organization_id: "00000000-0000-0000-0000-000000000001",
        is_brand_admin: true,
      },
    },
  },
  {
    path: "**/api/v1/me/notifications/summary",
    json: { data: { unread_count: 0, priority_breakdown: {} } },
  },
  {
    path: "**/api/v1/me/notifications",
    json: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } },
  },
  {
    path: "**/api/v1/me/reverb-config",
    json: {
      data: {
        app_key: "stub-key",
        host: "127.0.0.1",
        port: 5470,
        scheme: "http",
        cluster: "mt1",
      },
    },
  },
];

/**
 * Lightweight glob matcher — supports `**` as a wildcard segment. Avoids
 * pulling in micromatch / minimatch for the test runtime.
 */
function matchGlob(url: string, glob: string): boolean {
  const pattern = glob
    .replace(/[.+?^${}()|[\]\\]/g, "\\$&")
    .replace(/\*\*/g, "::DOUBLESTAR::")
    .replace(/\*/g, "[^/]*")
    .replace(/::DOUBLESTAR::/g, ".*");
  return new RegExp(`^${pattern}$`).test(url);
}
