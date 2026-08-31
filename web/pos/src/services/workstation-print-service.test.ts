import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@/lib/api";
import { workstationPrintService } from "./workstation-print-service";

const originalFetch = global.fetch;
const fetchMock = vi.fn();

function mockJson(status: number, body: unknown): void {
  fetchMock.mockResolvedValueOnce({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  } as Response);
}

beforeEach(() => {
  global.fetch = fetchMock;
  fetchMock.mockReset();
});

afterEach(() => {
  global.fetch = originalFetch;
});

describe("workstationPrintService.printKitchenTicket", () => {
  it("POSTs to /api/lan/print/kitchen-ticket with order_id body", async () => {
    mockJson(200, { status: "ok", printed: 3, groups: [] });
    const res = await workstationPrintService.printKitchenTicket({
      orderId: "ord-1",
    });
    expect(res.status).toBe("ok");
    expect(res.printed).toBe(3);
    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toMatch(/\/api\/lan\/print\/kitchen-ticket$/);
    expect((init as RequestInit).method).toBe("POST");
    const body = JSON.parse((init as RequestInit).body as string);
    expect(body).toEqual({ order_id: "ord-1", idempotency_key: undefined });
  });

  it("throws ApiError(503) with no_printer status when no printer configured", async () => {
    mockJson(503, { status: "no_printer", detail: "no_printer:kitchen_printer" });
    const err = (await workstationPrintService
      .printKitchenTicket({ orderId: "ord-1" })
      .catch((e) => e)) as ApiError;
    expect(err).toBeInstanceOf(ApiError);
    expect(err.status).toBe(503);
    expect(err.body.status).toBe("no_printer");
  });

  it("throws ApiError(504) with retry_after_ms on force-pull timeout", async () => {
    mockJson(504, { message: "force-pull timed out", retry_after_ms: 1500 });
    const err = (await workstationPrintService
      .printKitchenTicket({ orderId: "ord-1" })
      .catch((e) => e)) as ApiError;
    expect(err.status).toBe(504);
    expect(err.body.retry_after_ms).toBe(1500);
  });

  it("wraps network errors as ApiError(0)", async () => {
    fetchMock.mockRejectedValueOnce(new TypeError("Failed to fetch"));
    const err = (await workstationPrintService
      .printKitchenTicket({ orderId: "ord-1" })
      .catch((e) => e)) as ApiError;
    expect(err).toBeInstanceOf(ApiError);
    expect(err.status).toBe(0);
    expect((err.body.message as string)).toMatch(/workstation unreachable/);
  });
});

describe("workstationPrintService.printPaymentReceipt", () => {
  it("POSTs body with payment_id + reprint_reason when set", async () => {
    mockJson(200, {
      status: "ok",
      slips_printed: 1,
      reprint_no: 2,
      remaining_amount: "0",
    });
    const res = await workstationPrintService.printPaymentReceipt({
      orderId: "ord-1",
      paymentId: "pay-1",
      reprintReason: "manual reprint",
    });
    expect(res.reprint_no).toBe(2);
    const [, init] = fetchMock.mock.calls[0];
    const body = JSON.parse((init as RequestInit).body as string);
    expect(body.order_id).toBe("ord-1");
    expect(body.payment_id).toBe("pay-1");
    expect(body.reprint_reason).toBe("manual reprint");
  });

  it("throws ApiError(409) when payment not confirmed", async () => {
    mockJson(409, { message: "payment not confirmed" });
    const err = (await workstationPrintService
      .printPaymentReceipt({ orderId: "ord-1", paymentId: "pay-1" })
      .catch((e) => e)) as ApiError;
    expect(err.status).toBe(409);
  });
});

describe("workstationPrintService.printShiftReport", () => {
  it("POSTs to /api/lan/print/shift-report with snake_case body", async () => {
    mockJson(200, { status: "ok", slips_printed: 1 });
    const res = await workstationPrintService.printShiftReport({
      shopSlug: "shop-a",
      sessionId: "sess-1",
    });
    expect(res.status).toBe("ok");
    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toMatch(/\/api\/lan\/print\/shift-report$/);
    expect((init as RequestInit).method).toBe("POST");
    const body = JSON.parse((init as RequestInit).body as string);
    expect(body).toEqual({
      shop_slug: "shop-a",
      session_id: "sess-1",
      report_kind: "settlement",
    });
  });

  it("resolves to no_printer (never throws) on 503", async () => {
    mockJson(503, { status: "no_printer", detail: "no receipt_printer configured" });
    const res = await workstationPrintService.printShiftReport({
      shopSlug: "shop-a",
      sessionId: "sess-1",
    });
    expect(res.status).toBe("no_printer");
  });

  it("resolves to unsupported on 404 (older workstation build)", async () => {
    mockJson(404, { message: "not found" });
    const res = await workstationPrintService.printShiftReport({
      shopSlug: "shop-a",
      sessionId: "sess-1",
    });
    expect(res.status).toBe("unsupported");
  });

  it("resolves to offline when the workstation is unreachable", async () => {
    fetchMock.mockRejectedValueOnce(new TypeError("Failed to fetch"));
    const res = await workstationPrintService.printShiftReport({
      shopSlug: "shop-a",
      sessionId: "sess-1",
    });
    expect(res.status).toBe("offline");
  });

  it("bubbles up genuine 5xx so the caller can warn", async () => {
    mockJson(500, { message: "boom" });
    const err = (await workstationPrintService
      .printShiftReport({ shopSlug: "shop-a", sessionId: "sess-1" })
      .catch((e) => e)) as ApiError;
    expect(err).toBeInstanceOf(ApiError);
    expect(err.status).toBe(500);
  });
});

describe("workstationPrintService.printShiftOpenReport", () => {
  it("POSTs to /api/lan/print/shift-open-report (NOT shift-report) with snake_case body", async () => {
    mockJson(200, { status: "ok", slips_printed: 1 });
    const res = await workstationPrintService.printShiftOpenReport({
      shopSlug: "shop-a",
      sessionId: "sess-1",
      deviceName: "POS-1",
    });
    expect(res.status).toBe("ok");
    const [url, init] = fetchMock.mock.calls[0];
    // Regression guard: opening a shift must hit the OPEN endpoint, not the
    // 精算/Z close endpoint (bug: open-page printed the close report).
    expect(String(url)).toMatch(/\/api\/lan\/print\/shift-open-report$/);
    expect(String(url)).not.toMatch(/\/api\/lan\/print\/shift-report$/);
    expect((init as RequestInit).method).toBe("POST");
    const body = JSON.parse((init as RequestInit).body as string);
    expect(body).toEqual({
      shop_slug: "shop-a",
      session_id: "sess-1",
      device_name: "POS-1",
    });
  });

  it("resolves to no_printer (never throws) on 503", async () => {
    mockJson(503, { status: "no_printer" });
    const res = await workstationPrintService.printShiftOpenReport({
      shopSlug: "shop-a",
      sessionId: "sess-1",
    });
    expect(res.status).toBe("no_printer");
  });

  it("resolves to unsupported on 404 (older workstation build)", async () => {
    mockJson(404, { message: "not found" });
    const res = await workstationPrintService.printShiftOpenReport({
      shopSlug: "shop-a",
      sessionId: "sess-1",
    });
    expect(res.status).toBe("unsupported");
  });
});

describe("workstationPrintService.getPrintStatus", () => {
  it("GETs /api/lan/print/status with optional order_id", async () => {
    mockJson(200, {
      printer_roles: {
        kitchen_printer: { configured: true, online: true },
        bar_printer: { configured: false },
        hall_printer: { configured: false },
        receipt_printer: { configured: true, online: true },
      },
      sync: { last_pulled_at: "2026-06-20T10:00:00Z" },
    });
    const res = await workstationPrintService.getPrintStatus({ orderId: "ord-1" });
    expect(res.printer_roles.kitchen_printer.configured).toBe(true);
    const [url] = fetchMock.mock.calls[0];
    expect(String(url)).toMatch(/order_id=ord-1$/);
  });
});

describe("workstationPrintService.enabled", () => {
  it("is true when WORKSTATION_URL is configured (default in test env)", () => {
    // base-url-resolver provides http://localhost:8080 as the default when
    // VITE_WORKSTATION_API_URL is unset (typical for Vitest).
    expect(workstationPrintService.enabled).toBe(true);
  });
});
