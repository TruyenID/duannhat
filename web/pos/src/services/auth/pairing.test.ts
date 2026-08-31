import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// Control the resolver so we can assert which ORIGIN pairDevice targets.
vi.mock("@/services/workstation/base-url-resolver", () => ({
  CLOUD_URL: "https://cloud.example",
  isServedByWorkstation: vi.fn(),
  getWorkstationUrl: vi.fn(() => "http://192.168.1.11:6868"),
}));

import { pairDevice, PairError } from "./pairing";
import { isServedByWorkstation } from "@/services/workstation/base-url-resolver";

const originalFetch = global.fetch;
const fetchMock = vi.fn();

const req = {
  pairing_code: "ABC123",
  device_info: { user_agent: "test", app_version: "1.0" },
};

beforeEach(() => {
  fetchMock.mockReset();
  fetchMock.mockResolvedValue({
    ok: true,
    json: async () => ({
      device_token: "tok",
      device: { id: "d1", name: "POS 1", type: "pos", branch_id: "b1" },
    }),
  });
  global.fetch = fetchMock as unknown as typeof fetch;
});

afterEach(() => {
  global.fetch = originalFetch;
  vi.clearAllMocks();
});

describe("pairDevice base selection (#1481)", () => {
  it("pairs SAME-ORIGIN through the workstation when pos-web is served at /pos", async () => {
    // The whole point of #1481: served from the workstation, the page origin is
    // the workstation's LAN IP, so a direct cross-origin call to CLOUD_URL is
    // CORS-blocked. Pairing must go to the workstation origin (which relays).
    vi.mocked(isServedByWorkstation).mockReturnValue(true);

    await pairDevice(req);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledWith(
      "http://192.168.1.11:6868/api/v1/devices/pair",
      expect.objectContaining({ method: "POST" }),
    );
  });

  it("pairs against Cloud directly when served from Amplify/dev", async () => {
    vi.mocked(isServedByWorkstation).mockReturnValue(false);

    await pairDevice(req);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledWith(
      "https://cloud.example/api/v1/devices/pair",
      expect.objectContaining({ method: "POST" }),
    );
  });
});

/**
 * #2431 — the request body. Cloud must refuse a TMS/kiosk/workstation code
 * instead of burning it into a POS session that the workstation's
 * `policyPosWeb` ("pos" only) would then reject on every LAN call.
 */
describe("pairDevice request body", () => {
  function sentBody(): Record<string, unknown> {
    return JSON.parse(fetchMock.mock.calls[0][1].body as string);
  }

  beforeEach(() => {
    vi.mocked(isServedByWorkstation).mockReturnValue(false);
  });

  it("always sends expected_type: pos alongside the caller's fields", async () => {
    await pairDevice(req);

    expect(sentBody()).toEqual({
      pairing_code: "ABC123",
      device_info: { user_agent: "test", app_version: "1.0" },
      expected_type: "pos",
    });
  });

  it("expected_type cannot be overridden by the caller", async () => {
    await pairDevice({ ...req, expected_type: "workstation" } as never);

    expect(sentBody().expected_type).toBe("pos");
  });

  it("declares JSON in and JSON out", async () => {
    await pairDevice(req);

    expect(fetchMock.mock.calls[0][1].headers).toMatchObject({
      "Content-Type": "application/json",
      Accept: "application/json",
    });
  });

  it("returns the parsed pair response on success", async () => {
    await expect(pairDevice(req)).resolves.toMatchObject({
      device_token: "tok",
      device: { type: "pos" },
    });
  });
});

/**
 * #2431 — the error string. A generic "invalid code" for a device-type
 * mismatch sends staff hunting for a new code that will fail identically, so
 * the most specific message the envelope carries must win, and it must never
 * come out empty.
 */
describe("pairDevice error detail", () => {
  function respond(status: number, body: unknown, opts?: { invalidJson?: boolean }) {
    fetchMock.mockResolvedValue({
      ok: false,
      status,
      json: async () => {
        if (opts?.invalidJson) throw new SyntaxError("Unexpected token < in JSON");
        return body;
      },
    });
  }

  async function pairErr(): Promise<PairError> {
    return (await pairDevice(req).catch((e) => e)) as PairError;
  }

  beforeEach(() => {
    vi.mocked(isServedByWorkstation).mockReturnValue(false);
  });

  it("prefers the field error over the generic Laravel message", async () => {
    respond(422, {
      message: "The given data was invalid.",
      errors: { pairing_code: ['This pairing code belongs to a "ワークステーション" device.'] },
    });

    const err = await pairErr();
    expect(err).toBeInstanceOf(PairError);
    expect(err.status).toBe(422);
    expect(err.message).toBe('This pairing code belongs to a "ワークステーション" device.');
  });

  it("surfaces the expected_type validation error verbatim", async () => {
    respond(422, {
      message: "The given data was invalid.",
      errors: { expected_type: ["Invalid expected device type."] },
    });

    expect((await pairErr()).message).toBe("Invalid expected device type.");
  });

  it("accepts a string-valued field error, not just an array", async () => {
    respond(422, { errors: { pairing_code: "Code already used." } });

    expect((await pairErr()).message).toBe("Code already used.");
  });

  it("skips an empty field entry and keeps looking", async () => {
    respond(422, { errors: { pairing_code: [""], expected_type: ["must be pos"] } });

    expect((await pairErr()).message).toBe("must be pos");
  });

  it("uses the top-level message when there are no field errors", async () => {
    respond(410, { message: "Pairing code has expired." });

    const err = await pairErr();
    expect(err.status).toBe(410);
    expect(err.message).toBe("Pairing code has expired.");
  });

  it("uses RFC 7807 `detail` when there is no message", async () => {
    respond(409, { detail: "Device already paired." });

    expect((await pairErr()).message).toBe("Device already paired.");
  });

  it("never throws an empty message when the body is unparseable HTML", async () => {
    // A LAN proxy — or a workstation build with no relay route — answers HTML.
    // A blank pairing screen leaves staff with nothing to report.
    respond(502, null, { invalidJson: true });

    const err = await pairErr();
    expect(err.message).toBe("Pair failed: HTTP 502");
    expect(err.status).toBe(502);
    expect(err.body).toEqual({});
  });

  it("never throws an empty message for an empty JSON envelope", async () => {
    respond(422, {});

    expect((await pairErr()).message).toBe("Pair failed: HTTP 422");
  });

  it("keeps the raw body so the pairing screen can format status + code", async () => {
    const body = { message: "nope", code: "PAIRING_INVALID" };
    respond(422, body);

    expect((await pairErr()).body).toEqual(body);
  });
});

describe("pairDevice locale", () => {
  it("sends Accept-Language so Cloud answers in the cashier's language", async () => {
    localStorage.setItem("pos_locale", "ja");
    vi.mocked(isServedByWorkstation).mockReturnValue(false);

    await pairDevice(req);

    expect(fetchMock.mock.calls[0][1].headers).toMatchObject({
      "Accept-Language": "ja",
    });
  });

  it("defaults to ja when the operator has picked no locale yet", async () => {
    localStorage.removeItem("pos_locale");
    vi.mocked(isServedByWorkstation).mockReturnValue(false);

    await pairDevice(req);

    expect(fetchMock.mock.calls[0][1].headers).toMatchObject({
      "Accept-Language": "ja",
    });
  });
});
