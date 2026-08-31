// app/payment/cash.tsx
import { useState, useCallback, useEffect, useMemo } from 'react';
import { Keyboard, TouchableWithoutFeedback, View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { Button, Input, Text } from '@godxjp/ui-native';
import { usePayment } from '@/hooks/use-payment';
import { useLocale } from '@/providers/app-provider';
import { usePaymentFlow } from '@/providers/payment-flow-provider';
import { formatCurrency, sanitizeAmountInput } from '@/lib/format';
import { buildSplitMetadata, isMultiSlipSplit, splitSuccessRoute } from '@/lib/payment-metadata';
import { PaymentTimeoutBanner } from '@/components/payment-timeout-banner';
import type { PaymentMethod } from '@/types/kiosk';

export default function CashPaymentScreen() {
  const { tableId, orderId, amount, currency, splitMode, splitLabel, splitBillIndex, splitTotalBills, totalAmount } = useLocalSearchParams<{
    tableId: string; orderId: string; amount: string; currency: string; splitMode?: string;
    splitLabel?: string; splitBillIndex?: string; splitTotalBills?: string; totalAmount?: string;
  }>();
  const router = useRouter();
  const { t } = useLocale();
  const { recordPayment, state: paymentFlowState } = usePaymentFlow();
  const { submit, reset: resetPayment, isSubmitting, paymentStatus, error } = usePayment();
  const [amountInserted, setAmountInserted] = useState('');

  // Cancel payment-status polling on unmount so a back-navigation doesn't
  // leave a polling subscription alive against an obsolete paymentId.
  useEffect(() => { return () => resetPayment(); }, [resetPayment]);

  const totalDue = useMemo(() => Number(amount ?? 0), [amount]);
  const inserted = useMemo(() => Number(amountInserted) || 0, [amountInserted]);
  const changeDue = useMemo(() => inserted - totalDue, [inserted, totalDue]);
  const canConfirm = inserted >= totalDue && !isSubmitting;

  const handleConfirm = useCallback(async () => {
    try {
      const metadata = buildSplitMetadata({
        splitMode, splitBillIndex, splitTotalBills, splitLabel, totalAmount,
        itemAllocations: paymentFlowState.itemAllocations,
      });
      const result = await submit({
        order_id: orderId ?? '', method: 'cash', amount: totalDue,
        tendered_amount: inserted,
        idempotency_key: paymentFlowState.idempotencyKey ?? undefined,
        ...(metadata && { metadata }),
      });
      // Record the payment on EVERY path (not just multi-slip) so the flow's
      // paidAmount reflects this payment — otherwise the /success sidebar
      // tracker shows 0% after a successful full payment.
      recordPayment({
        payment_id: result.payment_id ?? '',
        reference_no: result.reference_no,
        method: 'cash' as PaymentMethod,
        amount: totalDue,
        timestamp: new Date().toISOString(),
      });
      if (isMultiSlipSplit(splitMode)) {
        router.replace(splitSuccessRoute(splitMode));
      } else {
        router.replace({
          pathname: '/success',
          params: {
            tableId,
            amountPaid: String(totalDue),
            cashTendered: String(inserted),
            paymentMethod: 'cash',
            referenceNo: result.reference_no,
            currency,
          },
        });
      }
    } catch {}
  }, [submit, orderId, totalDue, inserted, tableId, currency, splitMode, splitBillIndex, splitTotalBills, splitLabel, totalAmount, paymentFlowState.itemAllocations, paymentFlowState.idempotencyKey, recordPayment, router]);

  return (
    <TouchableWithoutFeedback onPress={Keyboard.dismiss}>
    <View className="flex-1 px-8 py-8 justify-between">
      <View>
        <Text variant="h2" className="mb-1">{t('kiosk.cash_title')}</Text>
        <Text variant="muted" className="mb-8">{t('kiosk.cash_sub')}</Text>

        {/* Amount Inserted — Input component (Rule 11) */}
        <View className="bg-card rounded-2xl border border-border p-5 mb-4 gap-1.5">
          <Text className="text-sm font-medium text-gray-700">{t('kiosk.cash_amount_inserted')}</Text>
          <Input
            value={amountInserted}
            onChangeText={(text) => setAmountInserted(sanitizeAmountInput(text))}
            keyboardType="numeric"
            placeholder="0"
            className="text-3xl font-bold border-0 p-0 h-auto"
          />
        </View>

        {/* Total Due + Change Due */}
        <View className="flex-row gap-4 mb-4">
          <View className="flex-1 bg-card rounded-2xl border border-border p-4">
            <Text className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">
              {t('kiosk.cash_total_due')}
            </Text>
            <Text className="text-xl font-bold text-foreground">
              {formatCurrency(totalDue, currency)}
            </Text>
          </View>
          <View className={`flex-1 rounded-2xl border p-4 ${
            changeDue < 0 ? 'bg-destructive/10 border-destructive' : 'bg-card border-border'
          }`}>
            <Text className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">
              {t('kiosk.cash_change_due')}
            </Text>
            <Text className={`text-xl font-bold ${changeDue < 0 ? 'text-destructive' : 'text-success'}`}>
              {formatCurrency(Math.max(0, changeDue), currency)}
            </Text>
          </View>
        </View>

        {/* Success feedback — DESIGN_PATTERN.md section 5.10 */}
        {changeDue >= 0 && inserted > 0 && (
          <View className="flex-row items-center gap-2 bg-success/10 rounded-xl px-3 py-2.5 mb-4">
            <Text className="text-success font-medium text-sm">{t('kiosk.cash_sufficient')}</Text>
          </View>
        )}

        {/* Error feedback */}
        {error && (
          <View className="bg-destructive/10 rounded-xl px-3 py-2.5 mb-4">
            <Text className="text-destructive font-medium text-sm">{error}</Text>
          </View>
        )}

        <PaymentTimeoutBanner paymentStatus={paymentStatus} />
      </View>

      <Button size="lg" onPress={handleConfirm} disabled={!canConfirm} className="w-full rounded-xl h-14">
        <Text className="text-lg font-semibold">
          {isSubmitting ? t('kiosk.cash_processing') : t('kiosk.cash_confirm')}
        </Text>
      </Button>
    </View>
    </TouchableWithoutFeedback>
  );
}
