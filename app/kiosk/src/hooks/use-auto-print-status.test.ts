// src/hooks/use-auto-print-status.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createElement, act, useRef } from 'react';
import { createRoot } from 'react-dom/client';

// Capture the handler that the hook registers with the workstation socket so
// the test can drive `print_status` events at it. vi.hoisted lets the mock
// factory (hoisted above imports) share state with the test body.
const sub = vi.hoisted(() => ({ handler: null as ((p: unknown) => void) | null }));

vi.mock('../providers/workstation-provider', () => ({
  useWorkstationSubscribe: (_type: string, handler: (p: unknown) => void) => {
    sub.handler = handler;
  },
}));

import { useAutoPrintStatus } from './use-auto-print-status';

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

/** Fire a print_status payload at the hook's registered handler. */
function emit(payload: unknown) {
  act(() => sub.handler?.(payload));
}

describe('useAutoPrintStatus', () => {
  beforeEach(() => {
    sub.handler = null;
  });

  it('starts not-failed', () => {
    const { result } = renderHook(() => useAutoPrintStatus('order-1'));
    expect(result.current.failed).toBe(false);
    expect(result.current.reason).toBeNull();
  });

  it('flags failed + reason when the workstation reports a failed payment receipt', () => {
    const { result } = renderHook(() => useAutoPrintStatus('order-1'));
    emit({
      order_id: 'order-1',
      kind: 'payment_receipt',
      status: 'failed',
      reason: 'printer_offline',
    });
    expect(result.current.failed).toBe(true);
    expect(result.current.reason).toBe('printer_offline');
  });

  it('ignores events for a different order', () => {
    const { result } = renderHook(() => useAutoPrintStatus('order-1'));
    emit({ order_id: 'order-2', kind: 'payment_receipt', status: 'failed' });
    expect(result.current.failed).toBe(false);
  });

  it('ignores non payment_receipt slip kinds', () => {
    const { result } = renderHook(() => useAutoPrintStatus('order-1'));
    emit({ order_id: 'order-1', kind: 'remaining_slip', status: 'failed' });
    expect(result.current.failed).toBe(false);
  });

  it('clears the warning when a later success arrives (workstation retried)', () => {
    const { result } = renderHook(() => useAutoPrintStatus('order-1'));
    emit({ order_id: 'order-1', kind: 'payment_receipt', status: 'failed', reason: 'paper_out' });
    expect(result.current.failed).toBe(true);
    emit({ order_id: 'order-1', kind: 'payment_receipt', status: 'success' });
    expect(result.current.failed).toBe(false);
    expect(result.current.reason).toBeNull();
  });

  it('does not flag for an empty orderId', () => {
    const { result } = renderHook(() => useAutoPrintStatus(''));
    emit({ order_id: '', kind: 'payment_receipt', status: 'failed' });
    expect(result.current.failed).toBe(false);
  });
});
