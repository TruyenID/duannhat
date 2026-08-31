// src/hooks/use-receipt-print.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createElement, act, useRef } from 'react';
// react-dom/client ships no bundled types here (same gap as use-payment.test.ts).
// @ts-expect-error -- runtime-only import for the test renderer.
import { createRoot } from 'react-dom/client';

vi.mock('../lib/api', () => ({ printKioskReceipt: vi.fn() }));
import { printKioskReceipt } from '../lib/api';
import { useReceiptPrint } from './use-receipt-print';

const mockPrint = vi.mocked(printKioskReceipt);

function renderHook<T>(hookFn: () => T): { result: { current: T } } {
  const result: { current: T } = { current: undefined as unknown as T };
  const container = document.createElement('div');
  document.body.appendChild(container);
  function Harness() {
    const ref = useRef<T>(undefined as unknown as T);
    ref.current = hookFn();
    result.current = ref.current;
    return null;
  }
  const root = createRoot(container);
  act(() => root.render(createElement(Harness)));
  return { result };
}

async function waitFor(assertion: () => void, timeout = 5000): Promise<void> {
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

describe('useReceiptPrint', () => {
  beforeEach(() => vi.clearAllMocks());

  it('prints once on success', async () => {
    mockPrint.mockResolvedValueOnce(undefined);
    const { result } = renderHook(() => useReceiptPrint());
    await act(async () => {
      await result.current.print('order-1');
    });
    expect(result.current.status).toBe('success');
    expect(result.current.error).toBeNull();
    expect(mockPrint).toHaveBeenCalledTimes(1);
  });

  it('auto-retries once and succeeds on a transient failure', async () => {
    mockPrint
      .mockRejectedValueOnce(new Error('printer_offline'))
      .mockResolvedValueOnce(undefined);
    const { result } = renderHook(() => useReceiptPrint());
    act(() => {
      void result.current.print('order-1');
    });
    await waitFor(() => expect(result.current.status).toBe('success'));
    expect(mockPrint).toHaveBeenCalledTimes(2);
    expect(result.current.error).toBeNull();
  });

  it('surfaces error after the retry also fails', async () => {
    mockPrint
      .mockRejectedValueOnce(new Error('printer_offline'))
      .mockRejectedValueOnce(new Error('printer_offline'));
    const { result } = renderHook(() => useReceiptPrint());
    act(() => {
      void result.current.print('order-1');
    });
    await waitFor(() => expect(result.current.status).toBe('error'));
    expect(mockPrint).toHaveBeenCalledTimes(2);
    expect(result.current.error).toBe('printer_offline');
  });

  it('rejects an empty orderId without calling the printer', async () => {
    const { result } = renderHook(() => useReceiptPrint());
    await act(async () => {
      await result.current.print('');
    });
    expect(result.current.status).toBe('error');
    expect(result.current.error).toBe('no_order');
    expect(mockPrint).not.toHaveBeenCalled();
  });
});
