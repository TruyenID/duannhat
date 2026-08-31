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

vi.mock('react-native', () => ({
  Pressable: () => null,
  View: () => null,
  ActivityIndicator: () => null,
}));

vi.mock('@godxjp/ui-native', () => ({
  Text: () => null,
}));

vi.mock('../src/components/ui/split-screen-shell', () => ({
  SplitScreenShell: () => null,
}));

vi.mock('../src/components/ui/payment-options-panel', () => ({
  PaymentOptionsPanel: () => null,
  usePaymentOptionsCheckoutBlocked: () => false,
}));

const mockPush = vi.fn();
vi.mock('expo-router', () => ({
  useRouter: () => ({ push: mockPush, replace: vi.fn(), back: vi.fn() }),
}));

import { PaymentFlowProvider, usePaymentFlow } from '../src/providers/payment-flow-provider';
import { usePaymentMethodSubmit } from './payment-method';
import type { SelectedPaymentOption } from '../src/types/effective-payment-options';

const SELECTED: SelectedPaymentOption = {
  optionId: 'opt-card',
  connectionId: 'conn-1',
  connectionOptionId: 'conn-opt-1',
  policyRevision: 3,
  rail: 'card',
  route: 'card',
  displayName: 'Card',
  provider: 'stripe',
};

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
      const { state, setOrder, setTable, setSelectedOption } = usePaymentFlow();
      stateRef = state;
      submit = usePaymentMethodSubmit();

      useEffect(() => {
        setTable('table-1');
        setOrder({ id: 'order-1', total: 1000, currency: 'JPY' } as never);
        setSelectedOption(SELECTED);
      }, [setTable, setOrder, setSelectedOption]);

      return null;
    }

    const { unmount } = renderInProvider(createElement(Harness));
    await act(async () => {});

    expect(stateRef!.idempotencyKey).toBeNull();

    await act(async () => { submit!(); });

    expect(stateRef!.idempotencyKey).not.toBeNull();
    expect(stateRef!.idempotencyKey).toMatch(/^[0-9a-f-]{36}$/);
    expect(mockPush).toHaveBeenCalledTimes(1);
    expect(mockPush.mock.calls[0][0].pathname).toBe('/payment/card');

    unmount();
  });

  it('does NOT navigate when option is not selected', async () => {
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
