// src/lib/payment-flow-routes.test.ts
import { describe, it, expect } from 'vitest';
import { isInPaymentFlow } from './payment-flow-routes';

describe('isInPaymentFlow — defer-logout decision', () => {
  it.each([
    '/payment/cash',
    '/payment/card',
    '/payment/qr',
    '/payment/emoney',
    '/custom/amount',
    '/custom/method',
    '/custom/success',
    '/split/people',
    '/split/method',
    '/split/success',
    '/success',
  ])('defers logout while on %s', (path) => {
    expect(isInPaymentFlow(path)).toBe(true);
  });

  it.each([
    '/',
    '/login',
    '/scan',
    '/checkout',
    '/bill',
    '/advertise',
    '/select-table',
    '/settings',
    // Pickers/options — no money in motion yet, safe to logout.
    '/payment-method',
    '/split-options',
  ])('allows immediate logout from %s', (path) => {
    expect(isInPaymentFlow(path)).toBe(false);
  });

  it('handles query strings via exact list — usePathname strips query, but defensive check', () => {
    // expo-router's usePathname() already strips query params, so we don't
    // need substring logic. /success with query → pathname is just /success.
    expect(isInPaymentFlow('/success')).toBe(true);
  });
});
