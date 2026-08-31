import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type * as ResolverModule from "@/services/workstation/base-url-resolver";
import type * as PairingModule from "@/services/auth/pairing";

/**
 * The pairing screen is the only surface a terminal has before it has a token,
 * so it is also the only place the two failure modes from #2431 can be
 * explained: the WORKSTATION is unpaired (branch_id empty → every LAN call
 * fails closed, and no POS code will fix that), or the CODE is for another
 * device type (Cloud says so precisely; a generic "invalid code" sends staff
 * to fetch another code that fails identically).
 */
const resolver = vi.hoisted(() => ({
  servedByWorkstation: true,
  workstationUrl: "http://192.168.1.50:6969",
}));
const pairMock = vi.hoisted(() => vi.fn());
const setPairedMock = vi.hoisted(() => vi.fn());

vi.mock("@/services/workstation/base-url-resolver", async (importOriginal) => {
  const actual =
    await importOriginal<typeof ResolverModule>();
  return {
    ...actual,
    isServedByWorkstation: () => resolver.servedByWorkstation,
    getWorkstationUrl: () => resolver.workstationUrl,
  };
});
vi.mock("@/services/auth/pairing", async (importOriginal) => {
  const actual = await importOriginal<typeof PairingModule>();
  return { ...actual, pairDevice: pairMock };
});
vi.mock("@/providers/app-provider", () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}));
vi.mock("@/providers/use-auth", () => ({
  useAuth: () => ({ setPaired: setPairedMock }),
}));
vi.mock("@/help/help-button", () => ({ HelpButton: () => null }));

const { PairingPage } = await import("./page");
const { PairError } = await import("@/services/auth/pairing");

const originalFetch = global.fetch;

function mockHealth(body: unknown, ok = true) {
  global.fetch = vi.fn().mockResolvedValue({ ok, json: async () => body } as Response);
}

function typeCode(value: string) {
  fireEvent.change(screen.getByRole("textbox"), { target: { value } });
}

function submit() {
  // Lowercase in, uppercase out — the input's own onChange normalizes.
  typeCode("abc123");
  fireEvent.click(screen.getByRole("button", { name: "pairing.submit" }));
}

beforeEach(() => {
  resolver.servedByWorkstation = true;
  resolver.workstationUrl = "http://192.168.1.50:6969";
  pairMock.mockReset();
  setPairedMock.mockReset();
  mockHealth({ branch_id: "branch-1" });
});

afterEach(() => {
  global.fetch = originalFetch;
});

describe("PairingPage — workstation health probe", () => {
  it("warns when the workstation itself is unpaired (empty branch_id)", async () => {
    mockHealth({ status: "ok", branch_id: "" });
    render(<PairingPage />);

    expect(await screen.findByRole("status")).toHaveTextContent(
      "pairing.ws_unpaired_title",
    );
  });

  it("warns when branch_id is absent from the health payload (old build)", async () => {
    mockHealth({ status: "ok" });
    render(<PairingPage />);

    expect(await screen.findByRole("status")).toBeInTheDocument();
  });

  it("stays silent when the workstation is paired", async () => {
    mockHealth({ status: "ok", branch_id: "branch-1" });
    render(<PairingPage />);

    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("probes the workstation LAN health endpoint, same-origin", async () => {
    render(<PairingPage />);

    await waitFor(() =>
      expect(global.fetch).toHaveBeenCalledWith(
        "http://192.168.1.50:6969/api/lan/health",
        expect.objectContaining({ headers: { Accept: "application/json" } }),
      ),
    );
  });

  it("does NOT probe on the Amplify build — there is no workstation to ask", async () => {
    resolver.servedByWorkstation = false;
    render(<PairingPage />);

    await Promise.resolve();
    expect(global.fetch).not.toHaveBeenCalled();
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });

  it("is advisory only: a failed probe never blocks pairing", async () => {
    global.fetch = vi.fn().mockRejectedValue(new TypeError("Failed to fetch"));
    render(<PairingPage />);

    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
    expect(screen.getByRole("textbox")).toBeEnabled();
  });

  it("ignores a non-2xx health response instead of crying unpaired", async () => {
    mockHealth({ branch_id: "" }, false);
    render(<PairingPage />);

    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    expect(screen.queryByRole("status")).not.toBeInTheDocument();
  });
});

describe("PairingPage — error surfacing", () => {
  it("leads with the LOCALIZED reason and keeps the Cloud detail under it", async () => {
    // The cashier reads the first line in their own language; the installer
    // reads the second. Dropping the first is what shipped English to a JP shop.
    pairMock.mockRejectedValue(
      new PairError(422, { message: "Invalid or expired pairing code." }, "Invalid or expired pairing code."),
    );
    render(<PairingPage />);

    submit();

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent("pairing.error_code_invalid");
    expect(alert).toHaveTextContent("Invalid or expired pairing code.");
  });

  it("uses the expired headline for 410", async () => {
    pairMock.mockRejectedValue(new PairError(410, { message: "Expired." }, "Expired."));
    render(<PairingPage />);

    submit();

    expect(await screen.findByRole("alert")).toHaveTextContent("pairing.error_code_expired");
  });

  it("shows the Cloud field error for a device-type mismatch, not a generic string", async () => {
    pairMock.mockRejectedValue(
      new PairError(
        422,
        {
          message: "The given data was invalid.",
          errors: { pairing_code: ['This code belongs to a "workstation" device.'] },
        },
        'This code belongs to a "workstation" device.',
      ),
    );
    render(<PairingPage />);

    submit();

    const alert = await screen.findByRole("alert");
    expect(alert).toHaveTextContent('This code belongs to a "workstation" device.');
    expect(alert).toHaveTextContent("HTTP 422");
    expect(alert).not.toHaveTextContent("pairing.error_generic");
  });

  it("keeps the status + code visible for a non-validation failure", async () => {
    pairMock.mockRejectedValue(
      new PairError(410, { message: "Code expired.", code: "PAIRING_EXPIRED" }, "Code expired."),
    );
    render(<PairingPage />);

    submit();

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Code expired. (HTTP 410 · code=PAIRING_EXPIRED)",
    );
  });

  it("falls back to the generic i18n line for a thrown non-Error", async () => {
    pairMock.mockRejectedValue({ weird: true });
    render(<PairingPage />);

    submit();

    expect(await screen.findByRole("alert")).toHaveTextContent("pairing.error_generic");
  });

  it("shows the network failure verbatim when the workstation relay is down", async () => {
    pairMock.mockRejectedValue(new TypeError("Failed to fetch"));
    render(<PairingPage />);

    submit();

    expect(await screen.findByRole("alert")).toHaveTextContent("Failed to fetch");
  });

  it("uppercases + trims the code and hands the session to AuthProvider on success", async () => {
    pairMock.mockResolvedValue({
      device_token: "tok",
      device: {
        id: "d1",
        name: "POS 1",
        type: "pos",
        branch_id: "b1",
        branch: { id: "b1", name: "Hongo", slug: "hongo" },
      },
    });
    render(<PairingPage />);

    submit();

    await waitFor(() =>
      expect(pairMock).toHaveBeenCalledWith(
        expect.objectContaining({ pairing_code: "ABC123" }),
      ),
    );
    expect(setPairedMock).toHaveBeenCalledWith(
      "tok",
      expect.objectContaining({ branch_slug: "hongo", type: "pos" }),
    );
  });

  it("clears a stale error as soon as the operator edits the code", async () => {
    pairMock.mockRejectedValue(new PairError(422, { message: "nope" }, "nope"));
    render(<PairingPage />);

    submit();
    expect(await screen.findByRole("alert")).toBeInTheDocument();

    typeCode("abc12");
    expect(screen.queryByRole("alert")).not.toBeInTheDocument();
  });
});
