import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// @sentry/react-native ships Flow-annotated JS that vitest+rolldown
// can't parse natively. Mock the module so the import graph stays
// pure ESM for tests.
vi.mock('./sentry', () => ({
  captureException: vi.fn(),
  captureMessage: vi.fn(),
  addBreadcrumb: vi.fn(),
}));

import { reportError, reportErrorWithContext } from './error-reporter';

describe('error-reporter', () => {
  let errorSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
  });
  afterEach(() => {
    errorSpy.mockRestore();
  });

  it('reportError prefixes the scope and forwards the raw error', () => {
    const err = new Error('boom');
    reportError('payment-init-card', err);
    expect(errorSpy).toHaveBeenCalledWith('[kiosk:payment-init-card]', err);
  });

  it('reportError works with non-Error values (string / null / object)', () => {
    reportError('qr-resolve', 'string failure');
    reportError('qr-resolve', null);
    reportError('qr-resolve', { code: 'E_NET' });
    expect(errorSpy).toHaveBeenCalledTimes(3);
    expect(errorSpy.mock.calls[0]).toEqual(['[kiosk:qr-resolve]', 'string failure']);
    expect(errorSpy.mock.calls[1]).toEqual(['[kiosk:qr-resolve]', null]);
    expect(errorSpy.mock.calls[2]).toEqual(['[kiosk:qr-resolve]', { code: 'E_NET' }]);
  });

  it('reportErrorWithContext appends the context object', () => {
    const err = new Error('timeout');
    reportErrorWithContext('payment-submit', err, { order_id: 'ord-42' });
    expect(errorSpy).toHaveBeenCalledWith(
      '[kiosk:payment-submit]',
      err,
      { order_id: 'ord-42' },
    );
  });
});
