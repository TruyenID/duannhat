import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the api layer + sentry BEFORE importing the helpers under test.
vi.mock('./api', () => ({ apiFetch: vi.fn() }));
vi.mock('./error-reporter', () => ({ reportError: vi.fn() }));

import { apiFetch } from './api';
import { reportError } from './error-reporter';
import {
  recordAudit,
  auditPaymentInitiated,
  auditPaymentConfirmed,
  auditCrash,
} from './audit-log';

const fetchMock = vi.mocked(apiFetch);
const reportErrorMock = vi.mocked(reportError);

describe('audit-log client', () => {
  beforeEach(() => {
    fetchMock.mockReset();
    reportErrorMock.mockReset();
    fetchMock.mockResolvedValue({ data: { id: 'log-1', recorded_at: '2026-05-28T10:00:00Z' } });
  });

  it('recordAudit POSTs to /api/v1/kiosk/audit-logs with the event payload', async () => {
    await recordAudit({
      event: 'payment.initiated',
      auditable_type: 'Payment',
      auditable_id: 'pay-123',
      metadata: { amount: 1500 },
    });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/kiosk/audit-logs', expect.objectContaining({
      method: 'POST',
      // suppressUnauthorizedHandler MUST stay wired — without it, a 401
      // bubbling out of the audit endpoint would re-enter the unauthorized
      // handler chain and cascade into a logout mid-payment. See C-2.
      suppressUnauthorizedHandler: true,
      body: JSON.stringify({
        event: 'payment.initiated',
        auditable_type: 'Payment',
        auditable_id: 'pay-123',
        metadata: { amount: 1500 },
      }),
    }));
  });

  it('recordAudit swallows network failures and forwards to reportError', async () => {
    fetchMock.mockRejectedValueOnce(new Error('network down'));

    await recordAudit({
      event: 'payment.crash',
      auditable_type: 'Payment',
      auditable_id: 'pay-456',
    });

    expect(reportErrorMock).toHaveBeenCalledWith('audit-log-post', expect.any(Error));
    // MUST NOT re-throw — payment flow continues regardless of audit-log failure.
  });

  it('auditPaymentInitiated builds the canonical event shape', async () => {
    auditPaymentInitiated({
      payment_id: 'pay-1',
      order_id: 'ord-1',
      amount: 2000,
      method: 'card',
    });

    // Helpers are fire-and-forget — give the microtask queue one tick to drain.
    await Promise.resolve();
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const body = JSON.parse((fetchMock.mock.calls[0]![1] as RequestInit).body as string);
    expect(body.event).toBe('payment.initiated');
    expect(body.auditable_type).toBe('Payment');
    expect(body.auditable_id).toBe('pay-1');
    expect(body.metadata).toMatchObject({ order_id: 'ord-1', amount: 2000, method: 'card' });
  });

  it('auditPaymentInitiated falls back to order_id when payment_id is absent', async () => {
    auditPaymentInitiated({
      order_id: 'ord-2',
      amount: 500,
      method: 'cash',
    });
    await Promise.resolve();
    const body = JSON.parse((fetchMock.mock.calls[0]![1] as RequestInit).body as string);
    expect(body.auditable_id).toBe('ord-2');
  });

  it('auditPaymentConfirmed includes reference_no in metadata', async () => {
    auditPaymentConfirmed({ payment_id: 'pay-7', reference_no: 'REF-99' });
    await Promise.resolve();
    const body = JSON.parse((fetchMock.mock.calls[0]![1] as RequestInit).body as string);
    expect(body.event).toBe('payment.confirmed');
    expect(body.metadata.reference_no).toBe('REF-99');
  });

  it('auditCrash uses "unknown" as auditable_id when no payment_id present', async () => {
    auditCrash({ error_message: 'OOM' });
    await Promise.resolve();
    const body = JSON.parse((fetchMock.mock.calls[0]![1] as RequestInit).body as string);
    expect(body.auditable_id).toBe('unknown');
    expect(body.metadata.error_message).toBe('OOM');
  });
});
