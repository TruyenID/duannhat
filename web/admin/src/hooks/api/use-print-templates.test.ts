/**
 * API error decoding — plan-053 M4 (#1171), TR-08 / TR-09 / TR-10 + DESIGN §4.
 *
 * The editor's whole conflict story is built on telling these three apart:
 *   422 PRINT_TEMPLATE_INVALID  → list EVERY violation, let the author fix all
 *                                 of them in one pass
 *   409 PRINT_TEMPLATE_*        → do NOT auto-merge, tell the loser to reload
 *   anything else               → a plain error message
 */
import { describe, expect, it } from "vitest";
import { ApiError } from "@/lib/api";
import { conflictCodeOf, violationsOf } from "./use-print-templates";

describe("violationsOf", () => {
  it("returns every violation of a 422 publish rejection", () => {
    const error = new ApiError(422, {
      message: "The print template definition is not publishable.",
      code: "PRINT_TEMPLATE_INVALID",
      errors: [
        {
          code: "RENDER_TRIAL_FAILED",
          path: "blocks.footer_text.i18n.ja",
          message: "…is 40 columns wide and cannot be wrapped.",
        },
        {
          code: "REQUIRED_BLOCK_DISABLED",
          path: "blocks.registration_number.enabled",
          message: "…the seller has a registration number and it must be printed.",
        },
      ],
    });

    const violations = violationsOf(error);

    expect(violations).toHaveLength(2);
    expect(violations?.map((violation) => violation.code)).toEqual([
      "RENDER_TRIAL_FAILED",
      "REQUIRED_BLOCK_DISABLED",
    ]);
  });

  it("returns null for a 409 — a conflict is not a validation failure", () => {
    expect(violationsOf(new ApiError(409, { code: "PRINT_TEMPLATE_DRAFT_STALE" }))).toBeNull();
  });

  it("returns null for a non-ApiError and for a 422 with no error list", () => {
    expect(violationsOf(new Error("network down"))).toBeNull();
    expect(violationsOf(new ApiError(422, { message: "nope" }))).toBeNull();
  });
});

describe("conflictCodeOf", () => {
  it.each([
    "PRINT_TEMPLATE_DRAFT_STALE",
    "PRINT_TEMPLATE_REBASE_REQUIRED",
    "PRINT_TEMPLATE_IMMUTABLE",
    "PRINT_TEMPLATE_NO_DRAFT",
  ])("passes through the backend code %s", (code) => {
    expect(conflictCodeOf(new ApiError(409, { code }))).toBe(code);
  });

  it("falls back to a generic conflict code when the body omits one", () => {
    expect(conflictCodeOf(new ApiError(409, {}))).toBe("PRINT_TEMPLATE_CONFLICT");
  });

  it("returns null for anything that is not a 409", () => {
    expect(conflictCodeOf(new ApiError(422, { code: "PRINT_TEMPLATE_INVALID" }))).toBeNull();
    expect(conflictCodeOf(new Error("boom"))).toBeNull();
  });
});
