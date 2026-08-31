// src/components/ui/payment-method-grid.test.tsx
import { describe, it, expect, vi } from 'vitest';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { PaymentMethodGrid } from './payment-method-grid';
import type { PaymentOptionTile } from '../../lib/payment-option-utils';

vi.mock('react-native', () => ({
  Pressable: ({ children }: { children: React.ReactNode }) =>
    createElement('div', { 'data-testid': 'pressable' }, children),
  View: ({ children }: { children: React.ReactNode }) =>
    createElement('div', null, children),
}));

vi.mock('@godxjp/ui-native', () => ({
  Text: ({ children }: { children: React.ReactNode }) =>
    createElement('span', null, children),
}));

vi.mock('../../providers/app-provider', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}));

const PAYPAY_TILE: PaymentOptionTile = {
  option: {
    id: 'opt-paypay-qr',
    display_name: 'PayPay QR',
    provider: 'paypay',
    rail: 'qr',
    effective: true,
    source: 'effective',
    reason: 'allowed',
    error_code: null,
    connection_id: 'conn-1',
    connection_option_id: 'conn-opt-1',
    shop_option_id: 'shop-opt-1',
    owner_scope: 'hq',
    shop_preference: 'inherit',
    device_preference: 'inherit',
    trace: [],
  },
  route: 'qr',
  icon: '📱',
  labelKey: 'kiosk.payment_method.qr',
  subKey: 'kiosk.payment_method.qr_sub',
  displayName: 'PayPay QR',
};

describe('PaymentMethodGrid (Plan 047 F7)', () => {
  it('renders only supplied effective tiles (no hard-coded cash/card/qr/emoney source)', () => {
    const html = renderToStaticMarkup(
      createElement(PaymentMethodGrid, {
        tiles: [PAYPAY_TILE],
        selectedOptionId: null,
        onSelect: vi.fn(),
      }),
    );

    expect(html).toContain('PayPay QR');
    expect(html).not.toContain('kiosk.payment_method.cash');
    expect(html).not.toContain('kiosk.payment_method.card');
    expect(html).not.toContain('kiosk.payment_method.emoney');
    expect(html.match(/pressable/g)?.length).toBe(1);
  });

  it('does not render secret-shaped fields from option rows (F12 partial)', () => {
    const html = renderToStaticMarkup(
      createElement(PaymentMethodGrid, {
        tiles: [
          {
            ...PAYPAY_TILE,
            option: {
              ...PAYPAY_TILE.option,
              // @ts-expect-error — simulate a leaky payload the UI must not echo
              api_key: 'sk_live_leak',
              secret: 'whsec_leak',
            },
          },
        ],
        selectedOptionId: 'opt-paypay-qr',
        onSelect: vi.fn(),
      }),
    );

    expect(html).not.toContain('sk_live_leak');
    expect(html).not.toContain('whsec_leak');
    expect(html).not.toContain('api_key');
  });
});
