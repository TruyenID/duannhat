import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { createElement, act } from 'react';
import { createRoot } from 'react-dom/client';

vi.mock('react-native', () => ({
  View: (props: { children?: React.ReactNode }) => createElement('div', null, props.children),
}));

vi.mock('@godxjp/ui-native', () => ({
  Text: (props: { children?: React.ReactNode }) => createElement('span', null, props.children),
}));

vi.mock('../providers/app-provider', () => ({
  useLocale: () => ({ t: (k: string) => `T:${k}` }),
}));

import { PaymentTimeoutBanner } from './payment-timeout-banner';

describe('<PaymentTimeoutBanner />', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  function render(props: { paymentStatus: 'idle' | 'pending' | 'succeeded' | 'failed' | 'refunded' }) {
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    act(() => {
      root.render(createElement(PaymentTimeoutBanner, props));
    });
    return { container, root, unmount: () => { root.unmount(); container.remove(); } };
  }

  it('does NOT render when status is not pending', () => {
    const { container, unmount } = render({ paymentStatus: 'idle' });
    expect(container.textContent).not.toContain('T:kiosk.payment_timeout_warning');
    unmount();
  });

  it('does NOT render within the first 60 seconds of pending', () => {
    const { container, unmount } = render({ paymentStatus: 'pending' });
    act(() => { vi.advanceTimersByTime(59_000); });
    expect(container.textContent).not.toContain('T:kiosk.payment_timeout_warning');
    unmount();
  });

  it('renders the warning after 60 seconds of pending', () => {
    const { container, unmount } = render({ paymentStatus: 'pending' });
    act(() => { vi.advanceTimersByTime(60_500); });
    expect(container.textContent).toContain('T:kiosk.payment_timeout_warning');
    unmount();
  });

  it('hides the warning when status flips to succeeded', () => {
    const { container, root, unmount } = render({ paymentStatus: 'pending' });
    act(() => { vi.advanceTimersByTime(70_000); });
    expect(container.textContent).toContain('T:kiosk.payment_timeout_warning');
    act(() => {
      root.render(createElement(PaymentTimeoutBanner, { paymentStatus: 'succeeded' }));
    });
    expect(container.textContent).not.toContain('T:kiosk.payment_timeout_warning');
    unmount();
  });
});
