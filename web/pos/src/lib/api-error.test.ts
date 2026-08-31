import { describe, expect, it } from "vitest";
import { AmbiguousMutationError, ApiError } from "./api";
import { formatErrorDetail, getApiErrorDetail, getApiErrorMessage } from "./api-error";

const FALLBACK = "不明なエラー";

describe("formatErrorDetail", () => {
  it("includes HTTP status and code alongside the message", () => {
    expect(
      formatErrorDetail(
        422,
        {
          message: "Invalid or expired pairing code.",
          code: "PAIRING_INVALID",
          errors: { pairing_code: ["Invalid or expired pairing code."] },
        },
        FALLBACK,
      ),
    ).toBe(
      "Invalid or expired pairing code. (HTTP 422 · code=PAIRING_INVALID)",
    );
  });

  it("lists extra field errors when they differ from the primary message", () => {
    expect(
      formatErrorDetail(
        422,
        {
          message: "The given data was invalid.",
          errors: {
            pairing_code: ["This pairing code belongs to a \"POSレジ\" device."],
            expected_type: ["must be workstation"],
          },
        },
        FALLBACK,
      ),
    ).toBe(
      'This pairing code belongs to a "POSレジ" device. expected_type: must be workstation (HTTP 422)',
    );
  });
});

/**
 * Toast surface. Returns the backend envelope VERBATIM — it is already
 * locale-negotiated by apiFetch's Accept-Language, so appending untranslated
 * `(HTTP nnn · code=…)` here would staple debug metadata onto every money-path
 * toast in the app. The diagnostic form lives in getApiErrorDetail.
 */
describe("getApiErrorMessage", () => {
  it("returns the ApiError envelope message with NO technical suffix", () => {
    const err = new ApiError(422, { message: "在庫が不足しています", code: "out_of_stock" });
    expect(getApiErrorMessage(err, FALLBACK)).toBe("在庫が不足しています");
  });

  it("falls back to the ApiError status message when the envelope has no message", () => {
    const err = new ApiError(500, { code: "server_error" });
    expect(getApiErrorMessage(err, FALLBACK)).toBe("API Error 500");
  });

  it("uses the fallback for a whitespace-only envelope message", () => {
    const err = new ApiError(400, { message: "   " });
    expect(getApiErrorMessage(err, FALLBACK)).toBe(FALLBACK);
  });

  it("returns the message of a plain Error", () => {
    expect(getApiErrorMessage(new Error("boom"), FALLBACK)).toBe("boom");
  });

  it("surfaces the reconcile instruction for an ambiguous mutation", () => {
    const err = new AmbiguousMutationError(
      "POST",
      "/api/v1/pos/orders",
      "vi",
      new DOMException("timeout", "AbortError"),
    );
    expect(getApiErrorMessage(err, FALLBACK)).toContain("tải lại dữ liệu");
    expect(err.reconcileRequired).toBe(true);
  });

  it("returns the message of a TypeError (fetch failure)", () => {
    expect(getApiErrorMessage(new TypeError("Failed to fetch"), FALLBACK)).toBe(
      "Failed to fetch",
    );
  });

  it("uses the fallback for an Error with an empty message", () => {
    expect(getApiErrorMessage(new Error("   "), FALLBACK)).toBe(FALLBACK);
  });

  it("uses the fallback for a thrown string (never swallowed, never shown raw)", () => {
    // A bare thrown string is an internal sentinel ("AbortError"), not copy
    // written for a cashier.
    expect(getApiErrorMessage("boom", FALLBACK)).toBe(FALLBACK);
  });

  it("uses the fallback for undefined", () => {
    expect(getApiErrorMessage(undefined, FALLBACK)).toBe(FALLBACK);
  });

  it("uses the fallback for a non-Error object", () => {
    expect(getApiErrorMessage({ status: 500 }, FALLBACK)).toBe(FALLBACK);
  });
});

/**
 * Diagnostic surface — the pairing screen only. Here the status/code/field
 * detail IS the message: an installer needs to know Cloud refused the code as
 * the wrong device type, not merely that "pairing failed".
 */
describe("getApiErrorDetail", () => {
  it("adds status and code to an ApiError", () => {
    const err = new ApiError(422, { message: "在庫が不足しています", code: "out_of_stock" });
    expect(getApiErrorDetail(err, FALLBACK)).toBe(
      "在庫が不足しています (HTTP 422 · code=out_of_stock)",
    );
  });

  it("formats duck-typed PairError-like objects with status + body", () => {
    const err = Object.assign(new Error("pair failed"), {
      status: 422,
      body: {
        message: "Invalid or expired pairing code.",
        errors: { pairing_code: ["Invalid or expired pairing code."] },
      },
    });
    expect(getApiErrorDetail(err, FALLBACK)).toBe(
      "Invalid or expired pairing code. (HTTP 422)",
    );
  });

  it("ignores a `body` that is not an object (duck-typing must not crash)", () => {
    const err = Object.assign(new Error("boom"), { status: 500, body: "nope" });
    expect(getApiErrorDetail(err, FALLBACK)).toBe("boom (HTTP 500)");
  });

  it("falls back to Error.message when the duck-typed status is not a number", () => {
    const err = Object.assign(new Error("boom"), { status: "422", body: {} });
    expect(getApiErrorDetail(err, FALLBACK)).toBe("boom");
  });

  it("uses the fallback for a non-Error value", () => {
    expect(getApiErrorDetail({ status: 500 }, FALLBACK)).toBe(FALLBACK);
  });
});

/**
 * Envelope shapes the POS actually receives: Laravel validation, RFC 7807
 * problem+json, workstation `{message, code}`, and the malformed bodies a
 * proxy/CDN can inject. None of them may produce an empty banner — see the
 * money rationale in api-error.ts.
 */
describe("formatErrorDetail envelope shapes", () => {
  it("uses RFC 7807 `detail` when there is no `message`", () => {
    expect(formatErrorDetail(409, { detail: "Order already paid." }, FALLBACK)).toBe(
      "Order already paid. (HTTP 409)",
    );
  });

  it("falls back to `title` when neither message nor detail is present", () => {
    expect(formatErrorDetail(409, { title: "Conflict" }, FALLBACK)).toBe(
      "Conflict (HTTP 409)",
    );
  });

  it("prefers a field error over the generic Laravel top-level message", () => {
    expect(
      formatErrorDetail(
        422,
        {
          message: "The given data was invalid.",
          errors: { pairing_code: ["This pairing code belongs to a workstation."] },
        },
        FALLBACK,
      ),
    ).toBe("This pairing code belongs to a workstation. (HTTP 422)");
  });

  it("accepts a string-valued field error, not just an array", () => {
    expect(
      formatErrorDetail(422, { errors: { expected_type: "must be pos" } }, FALLBACK),
    ).toBe("must be pos (HTTP 422)");
  });

  it("skips field entries whose value is neither string nor string[]", () => {
    expect(
      formatErrorDetail(
        422,
        { message: "Invalid.", errors: { code: 42, other: null, real: ["gone"] } },
        FALLBACK,
      ),
    ).toBe("gone (HTTP 422)");
  });

  it("ignores a non-object `errors` payload", () => {
    expect(formatErrorDetail(422, { message: "Invalid.", errors: "bad" }, FALLBACK)).toBe(
      "Invalid. (HTTP 422)",
    );
  });

  it("ignores an array `errors` payload", () => {
    expect(formatErrorDetail(422, { message: "Invalid.", errors: ["bad"] }, FALLBACK)).toBe(
      "Invalid. (HTTP 422)",
    );
  });

  it("treats a null / array / primitive body as empty", () => {
    expect(formatErrorDetail(500, null, FALLBACK)).toBe(`${FALLBACK} (HTTP 500)`);
    expect(formatErrorDetail(500, ["boom"], FALLBACK)).toBe(`${FALLBACK} (HTTP 500)`);
    expect(formatErrorDetail(500, "boom", FALLBACK)).toBe(`${FALLBACK} (HTTP 500)`);
  });

  it("omits the HTTP meta when the status is unknown (synthetic fetch failures use 0)", () => {
    expect(formatErrorDetail(0, { message: "workstation unreachable" }, FALLBACK)).toBe(
      "workstation unreachable",
    );
    expect(formatErrorDetail(null, { message: "workstation unreachable" }, FALLBACK)).toBe(
      "workstation unreachable",
    );
  });

  it("keeps the code in the meta when there is no status", () => {
    expect(formatErrorDetail(null, { message: "nope", code: "NOT_PAIRED" }, FALLBACK)).toBe(
      "nope (code=NOT_PAIRED)",
    );
  });

  it("never returns an empty string, even with no body and no fallback", () => {
    expect(formatErrorDetail(undefined, {}, "")).toBe("Unknown error");
  });

  it("does not repeat the primary message when its field carries several", () => {
    // Dedup runs per message, not per joined line — otherwise the cashier
    // reads "Invalid. pairing_code: Invalid.; Expired."
    expect(
      formatErrorDetail(
        422,
        { errors: { pairing_code: ["Invalid.", "Expired."] } },
        FALLBACK,
      ),
    ).toBe("Invalid. pairing_code: Expired. (HTTP 422)");
  });

  it("drops a field line entirely when it held only the primary message", () => {
    expect(
      formatErrorDetail(422, { errors: { pairing_code: ["Invalid."] } }, FALLBACK),
    ).toBe("Invalid. (HTTP 422)");
  });

  it("keeps a second field's duplicate of the primary message out of the line", () => {
    expect(
      formatErrorDetail(
        422,
        {
          message: "The given data was invalid.",
          errors: { pairing_code: ["Bad code."], expected_type: ["Bad code.", "must be pos"] },
        },
        FALLBACK,
      ),
    ).toBe("Bad code. expected_type: must be pos (HTTP 422)");
  });

  it("keeps the BRANCH_MISMATCH code visible — it is what tells staff to re-pair", () => {
    expect(
      formatErrorDetail(403, { message: "device branch mismatch", code: "BRANCH_MISMATCH" }, FALLBACK),
    ).toBe("device branch mismatch (HTTP 403 · code=BRANCH_MISMATCH)");
  });
});
