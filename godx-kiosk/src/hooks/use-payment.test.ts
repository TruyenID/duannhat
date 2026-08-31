// src/hooks/use-payment.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createElement, act, useRef } from 'react';
import { createRoot } from 'react-dom/client';

// audit-log transitively imports @sentry/react-native (Flow-annotated
// JS that rolldown can't parse). Mock the whole audit-log module so
// the import graph stays pure ESM.
vi.mock('../lib/audit-log', () => ({
  auditPaymentInitiated: vi.fn(),
  auditPaymentSubmitted: vi.fn(),
  auditPaymentConfirmed: vi.fn(),
  auditPaymentFailed: vi.fn(),
  auditCrash: vi.fn(),
  recordAudit: vi.fn(),
}));

import { usePayment } from './use-payment';

vi.mock('../lib/api', () => ({
  apiFetch: vi.fn(),
  confirmKioskPayment: vi.fn(),
  failKioskPayment: vi.fn(),
}));
import { apiFetch, confirmKioskPayment } from '../lib/api';
const mockApiFetch = vi.mocked(apiFetch);
const mockConfirm = vi.mocked(confirmKioskPayment);

// React 19-compatible renderHook (replaces @testing-library/react-hooks which uses legacy ReactDOM.render)
function renderHook<T>(
  hookFn: () => T,
  options?: { wrapper?: React.ComponentType<{ children: React.ReactNode }> },
): { result: { current: T } } {
  const result: { current: T } = { current: undefined as unknown as T };

  const container = document.createElement('div');
  document.body.appendChild(container);

  const Wrapper = options?.wrapper;

  function HookHarness() {
    const ref = useRef<T>(undefined as unknown as T);
    ref.current = hookFn();
    result.current = ref.current;
    return null;
  }

  const root = createRoot(container);
  act(() => {
    const element = Wrapper
      ? createElement(Wrapper, { children: createElement(HookHarness) })
      : createElement(HookHarness);
    root.render(element);
  });

  return { result };
}

async function waitFor(assertion: () => void, timeout = 3000): Promise<void> {
  const start = Date.now();
  while (true) {
    try {
      await act(async () => {});
      assertion();
      return;
    } catch (err) {
      if (Date.now() - start > timeout) throw err;
      await new Promise((r) => setTimeout(r, 20));
    }
  }
}

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return createElement(QueryClientProvider, { client: qc }, children);
}

describe('usePayment', () => {
  beforeEach(() => vi.clearAllMocks());

  it('initial state is idle', () => {
    const { result } = renderHook(() => usePayment(), { wrapper });
    expect(result.current.isSubmitting).toBe(false);
    expect(result.current.error).toBeNull();
    expect(result.current.paymentStatus).toBe('idle');
  });

  it('submit returns PaymentResult on success', async () => {
    mockApiFetch.mockResolvedValueOnce({
      data: { payment_id: 'pay-1', reference_no: 'REF-001', status: 'succeeded', amount_paid: 100000 },
    });
    const { result } = renderHook(() => usePayment(), { wrapper });
    let res: Awaited<ReturnType<typeof result.current.submit>>;

    await act(async () => {
      res = await result.current.submit({ order_id: 'order-1', method: 'cash', amount: 100000 });
    });

    expect(res!.reference_no).toBe('REF-001');
    expect(result.current.error).toBeNull();
  });

  it('sets error on submit failure', async () => {
    mockApiFetch.mockRejectedValueOnce(new Error('Payment failed'));
    const { result } = renderHook(() => usePayment(), { wrapper });

    await act(async () => {
      try { await result.current.submit({ order_id: 'order-1', method: 'cash', amount: 100000 }); }
      catch {}
    });

    expect(result.current.error).toBe('Payment failed');
  });

  it('sets paymentStatus to succeeded immediately on auto-confirm (status=succeeded)', async () => {
    mockApiFetch.mockResolvedValueOnce({
      data: {
        payment_id: 'pay-2', reference_no: 'REF-002', status: 'succeeded',
        amount_paid: 2400, confirm_type: 'auto',
      },
    });
    const { result } = renderHook(() => usePayment(), { wrapper });

    await act(async () => {
      await result.current.submit({ order_id: 'order-1', method: 'card', amount: 2400 });
    });

    expect(result.current.paymentStatus).toBe('succeeded');
    expect(result.current.paymentResult?.reference_no).toBe('REF-002');
  });

  it('sets paymentStatus to pending and starts polling on manual-confirm', async () => {
    mockApiFetch.mockResolvedValueOnce({
      data: {
        payment_id: 'pay-3', reference_no: 'REF-003', status: 'pending',
        amount_paid: 2400, confirm_type: 'manual',
      },
    });
    const { result } = renderHook(() => usePayment(), { wrapper });

    await act(async () => {
      await result.current.submit({ order_id: 'order-1', method: 'qr', amount: 2400 });
    });

    expect(result.current.paymentStatus).toBe('pending');
    expect(result.current.paymentResult?.reference_no).toBe('REF-003');
  });

  it('sends Idempotency-Key header when provided', async () => {
    mockApiFetch.mockResolvedValueOnce({
      data: {
        payment_id: 'pay-idem', reference_no: 'REF-IDEM', status: 'pending',
        amount_paid: 1000, confirm_type: 'manual',
      },
    });
    const { result } = renderHook(() => usePayment(), { wrapper });

    await act(async () => {
      await result.current.submit({
        order_id: 'order-1',
        method: 'card',
        amount: 1000,
        idempotency_key: 'test-uuid-123',
      });
    });

    const call = mockApiFetch.mock.calls[0];
    const headers = call[1]?.headers as Record<string, string>;
    expect(headers['Idempotency-Key']).toBe('test-uuid-123');
    const body = JSON.parse(call[1]?.body as string);
    expect(body.idempotency_key).toBeUndefined();
  });

  it('reuses Idempotency-Key across retries within same attempt', async () => {
    const sharedKey = 'persistent-uuid-789';

    // First call: network error
    mockApiFetch.mockRejectedValueOnce(new Error('Network timeout'));

    // Second call (retry): success
    mockApiFetch.mockResolvedValueOnce({
      data: {
        payment_id: 'pay-retry', reference_no: 'REF-RETRY', status: 'pending',
        amount_paid: 1000, confirm_type: 'manual',
      },
    });

    const { result } = renderHook(() => usePayment(), { wrapper });

    // First submit fails
    await act(async () => {
      try {
        await result.current.submit({
          order_id: 'order-1',
          method: 'card',
          amount: 1000,
          idempotency_key: sharedKey,
        });
      } catch {
        // expected
      }
    });

    // Retry with SAME key
    await act(async () => {
      await result.current.submit({
        order_id: 'order-1',
        method: 'card',
        amount: 1000,
        idempotency_key: sharedKey,
      });
    });

    // Both /payments POST calls should carry the same Idempotency-Key header.
    // (After the successful 2nd submit returns status=pending, the hook also
    // starts polling /payments/{id}/status — filter those out.)
    const submitCalls = mockApiFetch.mock.calls.filter(
      (call) => call[0] === '/api/v1/kiosk/payments',
    );
    expect(submitCalls).toHaveLength(2);
    const firstHeaders = submitCalls[0][1]?.headers as Record<string, string>;
    const secondHeaders = submitCalls[1][1]?.headers as Record<string, string>;
    expect(firstHeaders['Idempotency-Key']).toBe(sharedKey);
    expect(secondHeaders['Idempotency-Key']).toBe(sharedKey);
  });

  it('rounds amount to integer before submitting', async () => {
    mockApiFetch.mockResolvedValueOnce({
      data: {
        payment_id: 'pay-4', reference_no: 'REF-004', status: 'succeeded',
        amount_paid: 1901, confirm_type: 'auto',
      },
    });
    const { result } = renderHook(() => usePayment(), { wrapper });

    await act(async () => {
      await result.current.submit({ order_id: 'order-1', method: 'cash', amount: 1900.5 });
    });

    const call = mockApiFetch.mock.calls[0];
    const body = JSON.parse(call[1]?.body as string);
    expect(body.amount).toBe(1901);
    expect(Number.isInteger(body.amount)).toBe(true);
  });

  it('sends the backend field `payment_method` (mapped), not `method`', async () => {
    mockApiFetch.mockResolvedValueOnce({
      data: {
        payment_id: 'pay-m', reference_no: 'REF-M', status: 'pending',
        amount_paid: 2400, confirm_type: 'manual',
      },
    });
    const { result } = renderHook(() => usePayment(), { wrapper });

    await act(async () => {
      await result.current.submit({ order_id: 'order-1', method: 'qr', amount: 2400 });
    });

    const body = JSON.parse(mockApiFetch.mock.calls[0][1]?.body as string);
    expect(body.payment_method).toBe('transfer'); // qr → transfer (METHOD_TO_BACKEND)
    expect(body.method).toBeUndefined();
  });

  it('sends split `metadata` as a JSON string (Go field is string; backend unmarshals it)', async () => {
    mockApiFetch.mockResolvedValueOnce({
      data: {
        payment_id: 'pay-md', reference_no: 'REF-MD', status: 'pending',
        amount_paid: 1000, confirm_type: 'manual',
      },
    });
    const { result } = renderHook(() => usePayment(), { wrapper });

    await act(async () => {
      await result.current.submit({
        order_id: 'order-1',
        method: 'cash',
        amount: 1000,
        metadata: {
          split_mode: 'by_items',
          bill_index: 0,
          item_allocations: [{ item_id: 'it-1', units: 2 }],
        },
      });
    });

    const body = JSON.parse(mockApiFetch.mock.calls[0][1]?.body as string);
    expect(typeof body.metadata).toBe('string');
    expect(JSON.parse(body.metadata)).toEqual({
      split_mode: 'by_items',
      bill_index: 0,
      item_allocations: [{ item_id: 'it-1', units: 2 }],
    });
  });

  it('checkStatus() fetches current payment status from backend', async () => {
    // First submit() to set internal paymentId state
    mockApiFetch.mockResolvedValueOnce({
      data: {
        payment_id: 'p1', reference_no: 'REF-CS', status: 'pending',
        amount_paid: 100, confirm_type: 'manual',
      },
    });

    const { result } = renderHook(() => usePayment(), { wrapper });

    await act(async () => {
      await result.current.submit({ order_id: 'o1', method: 'card', amount: 100 });
    });

    // Mock the status fetch response (backend vocabulary: 'succeeded')
    mockApiFetch.mockResolvedValueOnce({ data: { status: 'succeeded' } });

    let status;
    await act(async () => {
      status = await result.current.checkStatus();
    });

    expect(status).toBe('succeeded');
    // Verify the status endpoint was called
    const statusCalls = mockApiFetch.mock.calls.filter((call) =>
      typeof call[0] === 'string' && call[0].includes('/status'),
    );
    expect(statusCalls.length).toBeGreaterThanOrEqual(1);
  });

  it('backend `succeeded` is a terminal status that drives the card flow (hang regression)', async () => {
    // The Go backend returns `succeeded` (the kiosk now speaks the SAME
    // vocabulary — no client renaming). Before aligning, the kiosk compared
    // against an invented `paid`, so `succeeded` never matched: polling never
    // stopped and the card screen hung forever after the terminal approved.
    mockApiFetch.mockResolvedValueOnce({
      data: {
        payment_id: 'p1', reference_no: 'REF', status: 'pending',
        amount_paid: 2400, confirm_type: 'manual',
      },
    });

    const { result } = renderHook(() => usePayment(), { wrapper });

    await act(async () => {
      await result.current.submit({ order_id: 'o1', method: 'card', amount: 2400 });
    });

    mockApiFetch.mockResolvedValueOnce({ data: { status: 'succeeded' } });

    let status;
    await act(async () => {
      status = await result.current.checkStatus();
    });

    expect(status).toBe('succeeded');
    expect(result.current.paymentStatus).toBe('succeeded');
  });

  it('checkStatus() returns null when no paymentId yet', async () => {
    const { result } = renderHook(() => usePayment(), { wrapper });
    let status;
    await act(async () => {
      status = await result.current.checkStatus();
    });
    expect(status).toBeNull();
  });

  it('submit() fires auditPaymentInitiated + auditPaymentSubmitted (PCI Req 10.2)', async () => {
    const audit = await import('../lib/audit-log');
    const initiatedMock = vi.mocked(audit.auditPaymentInitiated);
    const submittedMock = vi.mocked(audit.auditPaymentSubmitted);
    initiatedMock.mockClear();
    submittedMock.mockClear();

    const { result } = renderHook(() => usePayment(), { wrapper });

    mockApiFetch.mockResolvedValueOnce({
      data: { payment_id: 'pay-99', status: 'pending', reference_no: 'REF-99' },
    });
    await act(async () => {
      await result.current.submit({
        order_id: 'order-1',
        method: 'card',
        amount: 1500,
        idempotency_key: 'idk-1',
      });
    });
    expect(initiatedMock).toHaveBeenCalledWith(expect.objectContaining({
      order_id: 'order-1',
      method: 'card',
      amount: 1500,
      idempotency_key: 'idk-1',
    }));
    expect(submittedMock).toHaveBeenCalledWith({ payment_id: 'pay-99', status: 'pending' });
  });

  it('reset() clears paymentId and stops polling', async () => {
    mockApiFetch.mockResolvedValueOnce({
      data: { payment_id: 'pay-reset', reference_no: 'REF-R', status: 'pending', amount_paid: 100, confirm_type: 'manual' },
    });
    const { result } = renderHook(() => usePayment(), { wrapper });

    await act(async () => {
      await result.current.submit({ order_id: 'o1', method: 'card', amount: 100 });
    });
    expect(result.current.paymentStatus).toBe('pending');

    const callsBeforeReset = mockApiFetch.mock.calls.length;
    await act(async () => { result.current.reset(); });

    expect(result.current.paymentStatus).toBe('idle');

    // No new status fetch occurs after reset (TanStack will not fire a new
    // poll because paymentId is null).
    await new Promise((r) => setTimeout(r, 50));
    await act(async () => {});
    expect(mockApiFetch.mock.calls.length).toBe(callsBeforeReset);
  });

  // Helper: submit a manual (pending) payment so the hook has a paymentId for
  // confirm() to act on.
  async function submitPending(result: { current: ReturnType<typeof usePayment> }) {
    mockApiFetch.mockResolvedValueOnce({
      data: { payment_id: 'pay-c', reference_no: 'REF-C', status: 'pending', amount_paid: 2400, confirm_type: 'manual' },
    });
    await act(async () => {
      await result.current.submit({ order_id: 'o1', method: 'card', amount: 2400 });
    });
  }

  it('confirm() succeeds on first try → status succeeded', async () => {
    const { result } = renderHook(() => usePayment(), { wrapper });
    await submitPending(result);
    mockConfirm.mockResolvedValueOnce({ id: 'pay-c', status: 'succeeded' });
    await act(async () => {
      await result.current.confirm({ approval: 'OK' }, 'TERM-REF-1');
    });
    expect(result.current.paymentStatus).toBe('succeeded');
    expect(mockConfirm).toHaveBeenCalledTimes(1);
    expect(result.current.error).toBeNull();
  });

  it('confirm() retries a transient failure then succeeds (idempotent endpoint)', async () => {
    const { result } = renderHook(() => usePayment(), { wrapper });
    await submitPending(result);
    mockConfirm
      .mockRejectedValueOnce(new Error('network blip'))
      .mockResolvedValueOnce({ id: 'pay-c', status: 'succeeded' });
    await act(async () => {
      await result.current.confirm({ approval: 'OK' }, 'TERM-REF-1');
    });
    expect(result.current.paymentStatus).toBe('succeeded');
    expect(mockConfirm).toHaveBeenCalledTimes(2);
    expect(result.current.error).toBeNull();
  });
});
