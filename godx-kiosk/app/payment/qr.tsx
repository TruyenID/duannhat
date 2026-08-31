// app/payment/qr.tsx
import { useState, useEffect, useCallback, useRef } from 'react';
import { View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { Button, Text } from '@godxjp/ui-native';
import { usePayment } from '@/hooks/use-payment';
import { useTerminalCancel } from '@/hooks/use-terminal-cancel';
import { useTerminal } from '@/providers/terminal-provider';
import { useLocale } from '@/providers/app-provider';
import { usePaymentFlow } from '@/providers/payment-flow-provider';
import { formatCountdown } from '@/lib/format';
import { getTerminalStatusText, getTerminalErrorMessage } from '@/lib/terminal-utils';
import { reportError } from '@/lib/error-reporter';
import { buildSplitMetadata, isMultiSlipSplit, splitSuccessRoute } from '@/lib/payment-metadata';
import { PaymentTimeoutBanner } from '@/components/payment-timeout-banner';
import type { PaymentMethod } from '@/types/kiosk';

const QR_TIMEOUT_SECONDS = 299;

export default function QRPaymentScreen() {
  const { tableId, orderId, amount, currency, splitMode, splitLabel, splitBillIndex, splitTotalBills, totalAmount } = useLocalSearchParams<{
    tableId: string; orderId: string; amount: string; currency: string; splitMode?: string;
    splitLabel?: string; splitBillIndex?: string; splitTotalBills?: string; totalAmount?: string;
  }>();
  const router = useRouter();
  const { t } = useLocale();
  const { recordPayment, state: paymentFlowState } = usePaymentFlow();
  const { submit, confirm, fail, checkStatus, reset: resetPayment, isSubmitting, paymentStatus, paymentResult, error: paymentError } = usePayment();
  const { status: terminalStatus, statusEvent, result: terminalResult, error: terminalError, requestPayment, cancel, reset, hasTerminal, isLoadingConfig } = useTerminal();
  const paymentInitiated = useRef(false);
  const pendingPaymentRef = useRef<string | null>(null);

  // QR-only state (used when no terminal)
  const [qrUrl, setQrUrl] = useState<string | null>(null);
  const [secondsLeft, setSecondsLeft] = useState(QR_TIMEOUT_SECONDS);
  const [isExpired, setIsExpired] = useState(false);

  const sendToTerminal = useCallback(() => {
    console.log('[QR] sendToTerminal called, pending:', pendingPaymentRef.current, 'initiated:', paymentInitiated.current);
    if (!pendingPaymentRef.current || paymentInitiated.current) return;
    paymentInitiated.current = true;
    requestPayment({
      SubtractValue: {
        SequenceNumber: 100,
        CurrentService: 'QRPayment',
        Amount: Math.round(Number(amount ?? 0)),
        TrainingMode: false,
        AdditionalSecurityInformation: { qrCodeMode: 'MPM', qrCode: '', validTime: 5, printOption: 0 },
      },
    });
  }, [requestPayment, amount]);

  // Terminal flow init
  const initTerminalPayment = useCallback(async () => {
    reset();
    paymentInitiated.current = false;
    pendingPaymentRef.current = null;
    try {
      const metadata = buildSplitMetadata({
        splitMode, splitBillIndex, splitTotalBills, splitLabel, totalAmount,
        itemAllocations: paymentFlowState.itemAllocations,
      });
      const r = await submit({
        order_id: orderId ?? '', method: 'qr', amount: Number(amount ?? 0),
        idempotency_key: paymentFlowState.idempotencyKey ?? undefined,
        ...(metadata && { metadata }),
      });
      if (r.status === 'pending') {
        pendingPaymentRef.current = r.payment_id;
        sendToTerminal();
      }
    } catch (err) {
      reportError('payment-init-qr-terminal', err);
    }
  }, [
    submit,
    orderId,
    amount,
    sendToTerminal,
    reset,
    paymentFlowState.idempotencyKey,
    paymentFlowState.itemAllocations,
    splitMode,
    splitBillIndex,
    splitTotalBills,
    splitLabel,
    totalAmount,
  ]);

  // QR flow init (fallback)
  const initQrPayment = useCallback(async () => {
    setIsExpired(false);
    setSecondsLeft(QR_TIMEOUT_SECONDS);
    try {
      const metadata = buildSplitMetadata({
        splitMode, splitBillIndex, splitTotalBills, splitLabel, totalAmount,
        itemAllocations: paymentFlowState.itemAllocations,
      });
      const r = await submit({
        order_id: orderId ?? '', method: 'qr', amount: Number(amount ?? 0),
        idempotency_key: paymentFlowState.idempotencyKey ?? undefined,
        ...(metadata && { metadata }),
      });
      if (r.qr_url) setQrUrl(r.qr_url);
    } catch (err) {
      reportError('payment-init-qr-fallback', err);
    }
  }, [
    submit,
    orderId,
    amount,
    paymentFlowState.idempotencyKey,
    paymentFlowState.itemAllocations,
    splitMode,
    splitBillIndex,
    splitTotalBills,
    splitLabel,
    totalAmount,
  ]);

  const initialized = useRef(false);
  // Intentional: fire ONCE per (hasTerminal, isLoadingConfig) edge — even
  // though initTerminalPayment/initQrPayment now carry full deps, re-running
  // them on every dep change would submit() a duplicate payment row.
  // `initialized` ref enforces the single-shot semantics; missing deps from
  // the init callbacks are by design.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (initialized.current || isLoadingConfig) return;
    initialized.current = true;
    if (hasTerminal) {
      initTerminalPayment();
    } else {
      initQrPayment();
    }
  }, [hasTerminal, isLoadingConfig]);

  // Send to terminal once it becomes ready (handles race condition)
  useEffect(() => {
    console.log('[QR] useEffect check: hasTerminal:', hasTerminal, 'pending:', pendingPaymentRef.current, 'initiated:', paymentInitiated.current);
    if (hasTerminal && pendingPaymentRef.current && !paymentInitiated.current) {
      sendToTerminal();
    }
  }, [hasTerminal, sendToTerminal]);

  // Terminal success -> confirm
  useEffect(() => {
    if (hasTerminal && terminalStatus === 'success' && terminalResult && paymentInitiated.current) {
      paymentInitiated.current = false;
      confirm(terminalResult.OutputCompleteEvent);
    }
  }, [hasTerminal, terminalStatus, terminalResult, confirm]);

  // QR countdown (only when no terminal)
  useEffect(() => {
    if (hasTerminal || isExpired || paymentStatus === 'succeeded') return;
    if (secondsLeft <= 0) { setIsExpired(true); return; }
    const interval = setInterval(() => setSecondsLeft((s) => s - 1), 1000);
    return () => clearInterval(interval);
  }, [hasTerminal, secondsLeft, isExpired, paymentStatus]);

  const navigateSuccess = useCallback(() => {
    // Record the payment on EVERY path (not just multi-slip) so the flow's
    // paidAmount reflects this payment — otherwise the /success sidebar tracker
    // shows 0% after a successful full payment.
    recordPayment({
      payment_id: paymentResult?.payment_id ?? '',
      reference_no: paymentResult?.reference_no ?? '',
      method: 'qr' as PaymentMethod,
      amount: Number(amount ?? 0),
      timestamp: new Date().toISOString(),
    });
    if (isMultiSlipSplit(splitMode)) {
      router.replace(splitSuccessRoute(splitMode));
    } else {
      router.replace({
        pathname: '/success',
        params: { tableId, amountPaid: amount, paymentMethod: 'qr', referenceNo: paymentResult?.reference_no ?? '', currency },
      });
    }
  }, [splitMode, paymentResult, amount, tableId, currency, recordPayment, router]);

  // Navigate on success
  useEffect(() => {
    if (paymentStatus === 'succeeded') {
      navigateSuccess();
    }
  }, [paymentStatus, navigateSuccess]);

  useEffect(() => { return () => reset(); }, [reset]);

  // Cancel payment-status polling on unmount so a back-navigation doesn't
  // leave a polling subscription alive against an obsolete paymentId.
  useEffect(() => { return () => resetPayment(); }, [resetPayment]);

  const handleTerminalRetry = useCallback(() => {
    reset();
    paymentInitiated.current = false;
    pendingPaymentRef.current = 'retry';
    sendToTerminal();
  }, [reset, sendToTerminal]);

  const { execute: handleTerminalCancel } = useTerminalCancel({
    cancel,
    fail,
    checkStatus,
    terminalStatus,
    terminalError,
    onSuccess: navigateSuccess,
    onCancelled: () => router.back(),
  });

  // ===== TERMINAL UI =====
  if (hasTerminal) {
    if (terminalStatus === 'error' && terminalError) {
      return (
        <View className="flex-1 px-8 py-8 items-center justify-center gap-4">
          <Text className="text-5xl">⚠️</Text>
          <Text variant="h3" className="text-center">
            {getTerminalErrorMessage(terminalError, t)}
          </Text>
          <View className="flex-row gap-3">
            <Button variant="outline" onPress={handleTerminalCancel}>
              <Text>{t('terminal.cancel_payment')}</Text>
            </Button>
            <Button onPress={handleTerminalRetry}>
              <Text>{t('terminal.retry')}</Text>
            </Button>
          </View>
        </View>
      );
    }

    return (
      <View className="flex-1 px-8 py-8 items-center justify-center">
        <Text className="text-xs font-semibold text-primary tracking-widest mb-2 self-start">
          {t('kiosk.terminal_active')}
        </Text>
        <Text variant="h2" className="mb-1 self-start">{t('kiosk.qr_title')}</Text>
        <Text variant="muted" className="mb-10 self-start">{t('kiosk.qr_sub')}</Text>

        <View className="w-44 h-44 rounded-full bg-accent border-4 border-dashed border-border items-center justify-center mb-8">
          <Text className="text-6xl">📱</Text>
        </View>

        <View className="bg-card border border-border rounded-2xl px-6 py-4 items-center mb-4 w-full gap-1">
          <View className="flex-row items-center gap-2">
            <View className="w-2 h-2 rounded-full bg-warning" />
            <Text className="text-foreground font-medium">
              {isSubmitting
                ? t('kiosk.terminal_connecting')
                : statusEvent
                  ? getTerminalStatusText(statusEvent, t)
                  : t('kiosk.qr_awaiting')}
            </Text>
          </View>
        </View>

        {paymentError && (
          <View className="bg-destructive/10 rounded-xl px-3 py-2.5 w-full mb-4">
            <Text className="text-destructive font-medium text-sm text-center">{paymentError}</Text>
          </View>
        )}

        <PaymentTimeoutBanner paymentStatus={paymentStatus} />

        <Button variant="outline" onPress={handleTerminalCancel}>
          <Text>{t('common.cancel')}</Text>
        </Button>
      </View>
    );
  }

  // ===== QR UI (existing flow, no terminal) =====
  return (
    <View className="flex-1 px-8 py-8 items-center justify-center">
      <Text variant="h2" className="mb-1 self-start">{t('kiosk.qr_title')}</Text>
      <Text variant="muted" className="mb-8 self-start">{t('kiosk.qr_sub')}</Text>

      {isExpired ? (
        <View className="items-center gap-4">
          <View className="bg-destructive/10 rounded-xl px-4 py-3 mb-2">
            <Text className="text-destructive font-medium text-center">{t('kiosk.qr_expired')}</Text>
          </View>
          <Button onPress={initQrPayment} disabled={isSubmitting}>
            <Text>{t('kiosk.qr_regenerate')}</Text>
          </Button>
        </View>
      ) : (
        <View className="items-center gap-4 w-full">
          <View className="w-52 h-52 bg-foreground rounded-2xl items-center justify-center border-4 border-gray-200">
            {qrUrl ? (
              <Text className="text-white/60 text-xs text-center px-4">{t('kiosk.qr_ready')}</Text>
            ) : (
              <Text className="text-white/40 text-sm">
                {isSubmitting ? t('kiosk.qr_generating') : '—'}
              </Text>
            )}
          </View>

          <Text variant="muted" className="text-sm">
            {t('kiosk.qr_expiry', { time: formatCountdown(secondsLeft) })}
          </Text>

          <View className="bg-muted rounded-full px-4 py-2">
            <Text variant="muted" className="text-sm">{t('kiosk.qr_awaiting')}</Text>
          </View>

          {paymentError && (
            <View className="bg-destructive/10 rounded-xl px-3 py-2.5 w-full">
              <Text className="text-destructive font-medium text-sm text-center">{paymentError}</Text>
            </View>
          )}

          <PaymentTimeoutBanner paymentStatus={paymentStatus} />
        </View>
      )}
    </View>
  );
}
