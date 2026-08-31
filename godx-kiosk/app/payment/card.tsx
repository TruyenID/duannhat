// app/payment/card.tsx
import { useEffect, useCallback, useRef, useState } from 'react';
import { View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { Button, Text } from '@godxjp/ui-native';
import { usePayment } from '@/hooks/use-payment';
import { useTerminalCancel } from '@/hooks/use-terminal-cancel';
import { useTerminal } from '@/providers/terminal-provider';
import { useLocale } from '@/providers/app-provider';
import { usePaymentFlow } from '@/providers/payment-flow-provider';
import { getTerminalStatusText, getTerminalErrorMessage } from '@/lib/terminal-utils';
import { reportError } from '@/lib/error-reporter';
import { buildSplitMetadata, isMultiSlipSplit, splitSuccessRoute } from '@/lib/payment-metadata';
import { PaymentTimeoutBanner } from '@/components/payment-timeout-banner';
import type { PaymentMethod } from '@/types/kiosk';

export default function CardPaymentScreen() {
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
  const [paymentReady, setPaymentReady] = useState(false);

  const sendToTerminal = useCallback(() => {
    console.log('[Card] sendToTerminal called, pending:', pendingPaymentRef.current, 'initiated:', paymentInitiated.current);
    if (!pendingPaymentRef.current || paymentInitiated.current) return;
    paymentInitiated.current = true;
    requestPayment({
      AuthorizeSales: {
        SequenceNumber: 100,
        CurrentService: 'Credit',
        Amount: Math.round(Number(amount ?? 0)),
        TaxOthers: 0,
        TrainingMode: false,
        AdditionalSecurityInformation: { lang: 'ja', apStatusOption: 1, printOption: 0 },
      },
    });
  }, [requestPayment, amount]);

  const initPayment = useCallback(async () => {
    reset();
    paymentInitiated.current = false;
    pendingPaymentRef.current = null;
    try {
      const metadata = buildSplitMetadata({
        splitMode, splitBillIndex, splitTotalBills, splitLabel, totalAmount,
        itemAllocations: paymentFlowState.itemAllocations,
      });
      const r = await submit({
        order_id: orderId ?? '', method: 'card', amount: Number(amount ?? 0),
        idempotency_key: paymentFlowState.idempotencyKey ?? undefined,
        ...(metadata && { metadata }),
      });
      if (r.status === 'pending') {
        pendingPaymentRef.current = r.payment_id;
        setPaymentReady(true);
      }
    } catch (err) {
      // usePayment.onError already sets the paymentError banner; logging the
      // raw error here gives diagnostics for cases where the failure happens
      // before the mutation reports it (e.g. abort signal, network drop
      // pre-flight). Sentry will pick this up once wired in sprint C.
      reportError('payment-init-card', err);
    }
  }, [
    submit,
    orderId,
    amount,
    reset,
    paymentFlowState.idempotencyKey,
    paymentFlowState.itemAllocations,
    splitMode,
    splitBillIndex,
    splitTotalBills,
    splitLabel,
    totalAmount,
  ]);

  // Intentional mount-only run. initPayment() has full deps for closure
  // correctness, but RE-RUNNING this effect on every dep change would
  // submit() a SECOND payment row — params (orderId/amount/splitMode/etc.)
  // are read from useLocalSearchParams and are stable per nav. The single
  // useEffect-on-mount semantics is the payment-flow invariant.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { initPayment(); }, []);

  // Single place that sends to terminal — no duplicate
  useEffect(() => {
    if (hasTerminal && paymentReady && pendingPaymentRef.current && !paymentInitiated.current) {
      sendToTerminal();
    }
  }, [hasTerminal, paymentReady, sendToTerminal]);

  useEffect(() => {
    if (terminalStatus === 'success' && terminalResult && paymentInitiated.current) {
      paymentInitiated.current = false;
      confirm(terminalResult.OutputCompleteEvent);
    }
  }, [terminalStatus, terminalResult, confirm]);

  const navigateSuccess = useCallback(() => {
    // Record the payment on EVERY path (not just multi-slip) so the flow's
    // paidAmount reflects this payment — otherwise the /success sidebar tracker
    // shows 0% after a successful full payment.
    recordPayment({
      payment_id: paymentResult?.payment_id ?? '',
      reference_no: paymentResult?.reference_no ?? '',
      method: 'card' as PaymentMethod,
      amount: Number(amount ?? 0),
      timestamp: new Date().toISOString(),
    });
    if (isMultiSlipSplit(splitMode)) {
      router.replace(splitSuccessRoute(splitMode));
    } else {
      router.replace({
        pathname: '/success',
        params: {
          tableId,
          amountPaid: amount,
          paymentMethod: 'card',
          referenceNo: paymentResult?.reference_no ?? '',
          currency,
        },
      });
    }
  }, [splitMode, paymentResult, amount, tableId, currency, recordPayment, router]);

  useEffect(() => {
    if (paymentStatus === 'succeeded') {
      navigateSuccess();
    }
  }, [paymentStatus, navigateSuccess]);

  useEffect(() => { return () => reset(); }, [reset]);

  // Cancel payment-status polling on unmount so a back-navigation doesn't
  // leave a polling subscription alive against an obsolete paymentId.
  useEffect(() => { return () => resetPayment(); }, [resetPayment]);

  const handleRetry = useCallback(() => {
    reset();
    if (hasTerminal) {
      requestPayment({
        AuthorizeSales: {
          SequenceNumber: 100,
          CurrentService: 'Credit',
          Amount: Math.round(Number(amount ?? 0)),
          TaxOthers: 0,
          TrainingMode: false,
          AdditionalSecurityInformation: { lang: 'ja', apStatusOption: 1, printOption: 0 },
        },
      });
    }
  }, [reset, hasTerminal, requestPayment, amount]);

  const { execute: handleCancel } = useTerminalCancel({
    cancel,
    fail,
    checkStatus,
    terminalStatus,
    terminalError,
    onSuccess: navigateSuccess,
    onCancelled: () => router.back(),
  });

  if (!hasTerminal && !isLoadingConfig) {
    return (
      <View className="flex-1 px-8 py-8 items-center justify-center gap-4">
        <Text className="text-6xl">💳</Text>
        <Text variant="h3" className="text-center">{t('terminal.error.not_configured')}</Text>
        <Button variant="outline" onPress={() => router.back()}>
          <Text>{t('common.back')}</Text>
        </Button>
      </View>
    );
  }

  if (terminalStatus === 'error' && terminalError) {
    return (
      <View className="flex-1 px-8 py-8 items-center justify-center gap-4">
        <Text className="text-5xl">⚠️</Text>
        <Text variant="h3" className="text-center">
          {getTerminalErrorMessage(terminalError, t)}
        </Text>
        <View className="flex-row gap-3">
          <Button variant="outline" onPress={handleCancel}>
            <Text>{t('terminal.cancel_payment')}</Text>
          </Button>
          <Button onPress={handleRetry}>
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
      <Text variant="h2" className="mb-1 self-start">{t('kiosk.card_title')}</Text>
      <Text variant="muted" className="mb-10 self-start">{t('kiosk.card_sub')}</Text>

      <View className="w-44 h-44 rounded-full bg-accent border-4 border-dashed border-border items-center justify-center mb-8">
        <Text className="text-6xl">💳</Text>
      </View>

      <View className="bg-card border border-border rounded-2xl px-6 py-4 items-center mb-4 w-full gap-1">
        <View className="flex-row items-center gap-2">
          <View className="w-2 h-2 rounded-full bg-warning" />
          <Text className="text-foreground font-medium">
            {isSubmitting
              ? t('kiosk.terminal_connecting')
              : statusEvent
                ? getTerminalStatusText(statusEvent, t)
                : t('kiosk.card_awaiting')}
          </Text>
        </View>
      </View>

      {paymentError && (
        <View className="bg-destructive/10 rounded-xl px-3 py-2.5 w-full mb-4">
          <Text className="text-destructive font-medium text-sm text-center">{paymentError}</Text>
        </View>
      )}

      <PaymentTimeoutBanner paymentStatus={paymentStatus} />

      <Button variant="outline" onPress={handleCancel}>
        <Text>{t('common.cancel')}</Text>
      </Button>
    </View>
  );
}
