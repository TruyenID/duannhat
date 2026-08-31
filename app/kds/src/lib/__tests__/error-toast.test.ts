import { describe, it, expect } from "vitest";
import { intentForError, type ToastIntent } from "../error-toast";
import { ApiError } from "../api";

// Stub translator that returns the key — keeps assertions readable + decoupled
// from the JSON dictionaries (those are i18n team's responsibility, not this
// mapping's). Tests for actual copy live in their own suite.
const t = (key: string) => key;

function withCode(code: string, detail = "boom"): ApiError {
  return new ApiError(409, { code, detail }, detail);
}

describe("intentForError → toast variant + i18n key per RFC 7807 code", () => {
  it.each<[string, ToastIntent]>([
    ["KDS_E001", { variant: "error", title: "errors.kds.order_finalized", description: "boom" }],
    ["KDS_E002", { variant: "error", title: "errors.kds.invalid_transition", description: "boom" }],
    ["KDS_E003", { variant: "warning", title: "errors.kds.anti_misclick", description: "boom" }],
    ["KDS_E004", { variant: "info", title: "errors.kds.toppings_not_ready", description: "boom" }],
    ["KDS_E006", { variant: "error", title: "errors.kds.branch_forbidden", description: "boom" }],
    ["KDS_E007", { variant: "error", title: "errors.kds.item_not_found", description: "boom" }],
  ])("%s maps to expected intent", (code, want) => {
    expect(intentForError(withCode(code), t)).toEqual(want);
  });

  it("KDS_E005 is silent (anti-misclick throttle, by design)", () => {
    expect(intentForError(withCode("KDS_E005"), t)).toEqual({ variant: "silent", title: "" });
  });

  it("KDS_E008 is silent (idempotency replay is a 200, not an error path)", () => {
    expect(intentForError(withCode("KDS_E008"), t)).toEqual({ variant: "silent", title: "" });
  });
});

describe("intentForError fallback paths", () => {
  it("ApiError with no code (workstation {message} envelope) → generic error toast using detail", () => {
    const err = new ApiError(404, { message: "Item not found" }, "Item not found");
    expect(intentForError(err, t)).toEqual({
      variant: "error",
      title: "Item not found",
    });
  });

  it("ApiError with unknown code → generic error toast using detail (forward compatible)", () => {
    const err = new ApiError(409, { code: "KDS_E999", detail: "future code" }, "future code");
    expect(intentForError(err, t)).toEqual({
      variant: "error",
      title: "future code",
    });
  });

  it("non-ApiError Error → generic error toast using err.message", () => {
    const err = new Error("connection lost");
    expect(intentForError(err, t)).toEqual({ variant: "error", title: "connection lost" });
  });

  it("non-Error throwable → generic error toast using i18n unknown key", () => {
    expect(intentForError("string thrown", t)).toEqual({
      variant: "error",
      title: "errors.kds.unknown",
    });
  });

  it("null / undefined → generic error toast using i18n unknown key", () => {
    expect(intentForError(null, t)).toEqual({ variant: "error", title: "errors.kds.unknown" });
    expect(intentForError(undefined, t)).toEqual({ variant: "error", title: "errors.kds.unknown" });
  });
});
