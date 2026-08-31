// src/hooks/use-payment.ts
import { useState, useCallback } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { apiFetch, confirmKioskPayment, failKioskPayment } from '../lib/api';
import {
  auditPaymentInitiated,
  auditPaymentSubmitted,
  auditPaymentConfirmed,
  auditPaymentFailed,
} from '../lib/audit-log';
import { paymentKeys } from './query-keys';
import type { PaymentPayload, PaymentResult, PaymentStatus, PaymentUiStatus } from '../types/kiosk';

// Map kiosk UI method names to backend PaymentMethod codes
const METHOD_TO_BACKEND: Record<string, string> = {
  card: 'card',
  qr: 'transfer',
  emoney: 'e_wallet',
  cash: 'cash',
};

async function submitPayment(payload: PaymentPayload): Promise<PaymentResult> {
  // Pull `method` out of the spread so the wire body carries ONLY the backend's
  // field name (`payment_method`), not the kiosk-internal `method`. All payment
  // screens (cash/card/qr/emoney + splits) funnel through here, so this single
  // mapping fixes the field name for every method at once.
  const { idempotency_key, metadata, method, ...body } = payload;
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
  };
  if (idempotency_key) {
    headers['Idempotency-Key'] = idempotency_key;
  }
  const requestBody = JSON.stringify({
    ...body,
    // Backend (Go) requires `payment_method`, not `method`.
    payment_method: METHOD_TO_BACKEND[method] ?? method,
    amount: Math.round(body.amount),
    ...(body.tendered_amount != null && {
      tendered_amount: Math.round(body.tendered_amount),
    }),
    // Backend (Go) `CreatePaymentInput.metadata` is typed `string`, so send a
    // JSON-encoded string (sending an object fails: "cannot unmarshal object
    // into ... metadata of type string"). The backend MUST `json.Unmarshal`
    // this and consume `item_allocations` to record per-item claims so the
    // split-by-items preview can decrement `units_remaining`.
    ...(metadata != null && { metadata: JSON.stringify(metadata) }),
  });
  console.log(`[payment-submit] POST /api/v1/kiosk/payments body →`, requestBody);
  const res = await apiFetch<{ data: PaymentResult }>('/api/v1/kiosk/payments', {
    method: 'POST',
    headers,
    body: requestBody,
  });
  console.log(`[payment-submit] response → ${JSON.stringify(res?.data)}`);
  // The WS returns a raw payment row (id / amount / payment_method) instead of
  // the kiosk PaymentResult contract. Normalize defensively so the flow doesn't
  // break on field-name drift:
  //  - payment_id ← id (else confirm/status polling gets an undefined id)
  //  - reference_no ← id when the WS doesn't send a transaction code (so the
  //    "mã giao dịch" isn't blank). WS should return a real reference_no.
  //  - amount_paid ← amount.
  const raw = (res?.data ?? {}) as unknown as Record<string, unknown>;
  return {
    payment_id: String(raw.payment_id ?? raw.id ?? ""),
    reference_no: String(raw.reference_no ?? raw.id ?? ""),
    status: raw.status === "succeeded" ? "succeeded" : "pending",
    method: (raw.method ?? raw.payment_method) as PaymentResult["method"],
    qr_url: raw.qr_url as string | undefined,
    amount_paid: Number(raw.amount_paid ?? raw.amount ?? 0),
    expires_at: raw.expires_at as string | undefined,
    confirm_type: (raw.confirm_type as PaymentResult["confirm_type"]) ?? "manual",
  };
}

// Terminal (settled) statuses — polling stops and the flow advances on these.
const TERMINAL_STATUSES: readonly PaymentStatus[] = ['succeeded', 'failed', 'refunded'];

function isTerminalStatus(s: PaymentStatus | undefined): boolean {
  return s != null && TERMINAL_STATUSES.includes(s);
}

async function fetchPaymentStatus(paymentId: string): Promise<PaymentStatus> {
  const res = await apiFetch<{ data: { status: PaymentStatus } }>(
    `/api/v1/kiosk/payments/${paymentId}/status`
  );
  return res.data.status;
}

export function usePayment() {
  const [paymentId, setPaymentId] = useState<string | null>(null);
  const [immediateStatus, setImmediateStatus] = useState<PaymentUiStatus>('idle');
  const [paymentResult, setPaymentResult] = useState<PaymentResult | null>(null);
  const [error, setError] = useState<string | null>(null);

  const { mutateAsync, isPending } = useMutation({
    mutationFn: submitPayment,
    onError: (err) => setError(err instanceof Error ? err.message : String(err)),
  });

  const { data: polledStatus } = useQuery({
    queryKey: paymentKeys.status(paymentId ?? ''),
    queryFn: () => fetchPaymentStatus(paymentId!),
    enabled: paymentId !== null,
    refetchInterval: (query) => {
      const s = query.state.data;
      if (isTerminalStatus(s)) return false;
      return 3000;
    },
  });

  const submit = async (payload: PaymentPayload): Promise<PaymentResult> => {
    setError(null);
    // Lifecycle audit (PCI Req 10.2). Fire-and-forget — never blocks
    // the payment flow even if the cloud audit endpoint is down.
    auditPaymentInitiated({
      order_id: payload.order_id,
      amount: payload.amount,
      method: payload.method,
      idempotency_key: payload.idempotency_key,
    });
    const result = await mutateAsync(payload);
    setPaymentResult(result);
    if (result.status === 'succeeded') {
      setImmediateStatus('succeeded');
    } else if (result.status === 'pending') {
      setPaymentId(result.payment_id);
      setImmediateStatus('pending');
    }
    auditPaymentSubmitted({ payment_id: result.payment_id, status: result.status });
    return result;
  };

  const confirm = useCallback(
    async (terminalData?: Record<string, unknown>, terminalRef?: string) => {
      if (!paymentId) return;
      // The terminal has already captured funds by the time we confirm, so a
      // transient WS/network blip here MUST NOT strand the customer on the
      // "processing" screen with money taken. `confirm` is idempotent
      // server-side (re-confirming an already-succeeded payment returns it
      // unchanged — no re-applied order, no duplicate slip), so retrying with
      // backoff is safe.
      const retryDelaysMs = [500, 1500, 3000];
      let lastErr: unknown;
      for (let attempt = 0; attempt <= retryDelaysMs.length; attempt++) {
        try {
          await confirmKioskPayment(paymentId, terminalData, terminalRef);
          setImmediateStatus('succeeded');
          auditPaymentConfirmed({ payment_id: paymentId, reference_no: terminalRef });
          return;
        } catch (err) {
          lastErr = err;
          const delayMs = retryDelaysMs[attempt];
          if (delayMs != null) {
            await new Promise((resolve) => setTimeout(resolve, delayMs));
          }
        }
      }
      // Every retry failed: funds may be captured but the backend never
      // confirmed. Surface the error (do NOT mark succeeded) and audit so the
      // payment can be reconciled.
      const reason = lastErr instanceof Error ? lastErr.message : String(lastErr);
      setError(reason);
      auditPaymentFailed({ payment_id: paymentId, reason });
    },
    [paymentId],
  );

  const fail = useCallback(
    async (reason?: string, errorCode?: string) => {
      if (!paymentId) return;
      try {
        await failKioskPayment(paymentId, reason, errorCode);
        setImmediateStatus('failed');
        auditPaymentFailed({ payment_id: paymentId, reason, error_code: errorCode });
      } catch (err) {
        setError(err instanceof Error ? err.message : String(err));
      }
    },
    [paymentId],
  );

  const checkStatus = useCallback(async (): Promise<PaymentStatus | null> => {
    if (!paymentId) return null;
    try {
      const status = await fetchPaymentStatus(paymentId);
      setImmediateStatus(status);
      return status;
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err));
      return null;
    }
  }, [paymentId]);

  const reset = useCallback(() => {
    setPaymentId(null);
    setImmediateStatus('idle');
    setPaymentResult(null);
    setError(null);
  }, []);

  // A terminal `immediateStatus` (succeeded/failed/refunded) — set the moment
  // confirm()/fail() resolves — MUST win. Otherwise an in-flight `polledStatus`
  // (still 'pending' between 3s polls) would mask the confirmed result and the
  // card/emoney flow would hang on the "processing" screen even after the
  // terminal approved and printed. For non-terminal flows (QR scan)
  // immediateStatus stays 'pending', so polledStatus drives the transition.
  const paymentStatus: PaymentUiStatus = isTerminalStatus(
    immediateStatus === 'idle' ? undefined : immediateStatus,
  )
    ? immediateStatus
    : polledStatus ?? immediateStatus;

  return {
    submit,
    confirm,
    fail,
    checkStatus,
    reset,
    isSubmitting: isPending,
    error,
    paymentStatus,
    paymentResult,
  };
}
