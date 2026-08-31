// app/payment-method.test.tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createElement, act, useEffect } from 'react';
import { createRoot } from 'react-dom/client';

vi.mock('../src/lib/audit-log', () => ({
  auditPaymentInitiated: vi.fn(),
  auditPaymentSubmitted: vi.fn(),
  auditPaymentConfirmed: vi.fn(),
  auditPaymentFailed: vi.fn(),
  auditCrash: vi.fn(),
  recordAudit: vi.fn(),
}));

// react-native ships Flow-annotated JS that rolldown can't parse. Stub the
// surface payment-method.tsx imports (Pressable, View).
vi.mock('react-native', () => ({
  Pressable: () => null,
  View: () => null,
}));

vi.mock('@godxjp/ui-native', () => ({
  Text: () => null,
}));

// Stub the shell so the test doesn't pull in its order-view sidebar →
// useSplitByItemsPreview → api.ts (RN/expo) module graph, which this unit
// test (hook-only) neither renders nor mocks.
vi.mock('../src/components/ui/split-screen-shell', () => ({
  SplitScreenShell: () => null,
}));
vi.mock('../src/components/ui/payment-method-grid', () => ({
  PaymentMethodGrid: () => null,
}));

const mockPush = vi.fn();
vi.mock('expo-router', () => ({
  useRouter: () => ({ push: mockPush, replace: vi.fn(), back: vi.fn() }),
}));

import { PaymentFlowProvider, usePaymentFlow } from '../src/providers/payment-flow-provider';
import { usePaymentMethodSubmit } from './payment-method';

function renderInProvider(child: React.ReactElement) {
  const container = document.createElement('div');
  document.body.appendChild(container);
  const root = createRoot(container);
  act(() => {
    root.render(createElement(PaymentFlowProvider, { children: child }));
  });
  return { unmount: () => { root.unmount(); container.remove(); } };
}

describe('usePaymentMethodSubmit (C-1 wiring)', () => {
  beforeEach(() => mockPush.mockClear());

  it('mints a fresh idempotency key and navigates to /payment/{method}', async () => {
    let submit: (() => void) | null = null;
    let stateRef: ReturnType<typeof usePaymentFlow>['state'] | null = null;

    function Harness() {
      const { state, setOrder, setTable, setMethod } = usePaymentFlow();
      stateRef = state;
      submit = usePaymentMethodSubmit();

      useEffect(() => {
        setTable('table-1');
        setOrder({ id: 'order-1', total: 1000, currency: 'JPY' } as never);
        setMethod('card');
      }, [setTable, setOrder, setMethod]);

      return null;
    }

    const { unmount } = renderInProvider(createElement(Harness));
    await act(async () => {});  // flush setup effects

    // Pre-condition: key not minted yet
    expect(stateRef!.idempotencyKey).toBeNull();

    await act(async () => { submit!(); });

    // Post-condition: key minted AND navigation occurred
    expect(stateRef!.idempotencyKey).not.toBeNull();
    expect(stateRef!.idempotencyKey).toMatch(/^[0-9a-f-]{36}$/);  // UUID v4 shape
    expect(mockPush).toHaveBeenCalledTimes(1);

    unmount();
  });

  it('does NOT navigate when method is not selected', async () => {
    let submit: (() => void) | null = null;

    function Harness() {
      const { setOrder, setTable } = usePaymentFlow();
      submit = usePaymentMethodSubmit();

      useEffect(() => {
        setTable('table-1');
        setOrder({ id: 'order-1', total: 1000, currency: 'JPY' } as never);
      }, [setTable, setOrder]);

      return null;
    }

    const { unmount } = renderInProvider(createElement(Harness));
    await act(async () => {});
    await act(async () => { submit!(); });

    expect(mockPush).not.toHaveBeenCalled();
    unmount();
  });
});
