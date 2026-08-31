import assert from "node:assert/strict";
import test from "node:test";
import {
  classifyMenuError,
  formatMenuAvailability,
  parseMenuAvailability,
} from "./menu-availability.ts";

/**
 * Stands in for `ApiError` — `lib/api.ts` cannot be imported under
 * `node --test`. Mirrors the only part the classifier reads: `.body`.
 */
function apiError(status: number, body: Record<string, unknown>) {
  return Object.assign(new Error(String(body.message ?? status)), { status, body });
}

test("parses a structured outside-hours response", () => {
  const availability = parseMenuAvailability({
    code: "menu_outside_service_hours",
    availability: {
      branch_name: "人形町店",
      menu_name: "人形町店 メニュー",
      timezone: "Asia/Tokyo",
      next_opens_at: "2026-07-22T07:00:00+09:00",
      next_closes_at: "2026-07-22T22:00:00+09:00",
    },
  });

  assert.equal(availability?.branch_name, "人形町店");
  assert.equal(availability?.next_opens_at, "2026-07-22T07:00:00+09:00");
});

test("does not misclassify a technical menu error as outside hours", () => {
  assert.equal(parseMenuAvailability({ code: "menu_unavailable" }), null);
  assert.equal(parseMenuAvailability({ code: "menu_outside_service_hours" }), null);
});

// A shop with no published menu is not a broken shop. Getting this wrong told
// the customer their connection had failed, under a Retry that could never
// succeed — so each backend code must land on its own screen.
test("a shop with no menu published is not reported as a fault", () => {
  const classified = classifyMenuError(
    apiError(404, {
      message: "No menu is currently available for online ordering.",
      code: "menu_unavailable",
    }),
  );

  assert.deepEqual(classified, { kind: "unavailable" });
});

test("an outside-hours response keeps its schedule payload", () => {
  const classified = classifyMenuError(
    apiError(404, {
      code: "menu_outside_service_hours",
      availability: {
        branch_name: "人形町店",
        menu_name: "人形町店 メニュー",
        timezone: "Asia/Tokyo",
        next_opens_at: "2026-07-22T07:00:00+09:00",
        next_closes_at: null,
      },
    }),
  );

  assert.equal(classified.kind, "outside-hours");
});

test("network faults and unrecognised errors stay technical", () => {
  assert.deepEqual(classifyMenuError(new TypeError("Failed to fetch")), { kind: "technical" });
  assert.deepEqual(classifyMenuError(apiError(500, { message: "Server Error" })), {
    kind: "technical",
  });
  // Outside-hours code but a malformed payload — no schedule to show, so it
  // must not fall through to a screen that would render `undefined`.
  assert.deepEqual(classifyMenuError(apiError(404, { code: "menu_outside_service_hours" })), {
    kind: "technical",
  });
});

test("formats the opening window in the branch timezone", () => {
  const formatted = formatMenuAvailability({
    branch_name: "Ningyocho",
    menu_name: "Menu",
    timezone: "Asia/Tokyo",
    next_opens_at: "2026-07-22T07:00:00+09:00",
    next_closes_at: "2026-07-22T22:00:00+09:00",
  }, "en");

  assert.equal(formatted.opensAt, "07:00");
  assert.equal(formatted.closesAt, "22:00");
  assert.match(formatted.date, /Wednesday/);
});
