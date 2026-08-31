// src/hooks/use-receipt-print.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createElement, act, useRef } from 'react';
import { createRoot } from 'react-dom/client';

// The error class has to be part of the mock (declared inside the factory —
// vi.mock is hoisted, so a top-level class is not in scope yet). The hook
// branches on `instanceof`, so tests must throw this exact constructor.
vi.mock('../lib/api', () => {
  class WorkstationUnavailableError extends Error {
    constructor(public path: string) {
      super(`No workstation reachable on the LAN for ${path}`);
      this.name = 'WorkstationUnavailableError';
    }
  }
  return { printKioskReceipt: vi.fn(), WorkstationUnavailableError };
});
import { printKioskReceipt, WorkstationUnavailableError } from '../lib/api';
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

describe('useReceiptPrint — no workstation on the LAN (issue #44)', () => {
  beforeEach(() => vi.clearAllMocks());

  it('fails fast with a translatable reason instead of retrying into the void', async () => {
    mockPrint.mockRejectedValue(new WorkstationUnavailableError('/api/lan/print/payment-receipt'));
    const { result } = renderHook(() => useReceiptPrint());
    act(() => {
      void result.current.print('order-1');
    });
    await waitFor(() => expect(result.current.status).toBe('error'));

    expect(result.current.errorKey).toBe('workstation.print_no_workstation');
    // No retry: a missing workstation is a configuration problem, and the
    // second attempt would find the same nothing 2s later.
    expect(mockPrint).toHaveBeenCalledTimes(1);
  });
});

