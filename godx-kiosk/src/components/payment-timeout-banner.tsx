import { useEffect, useState } from 'react';
import { View } from 'react-native';
import { Text } from '@godxjp/ui-native';
import { useLocale } from '../providers/app-provider';
import type { PaymentUiStatus } from '../types/kiosk';

const TIMEOUT_MS = 60_000;

interface Props {
  paymentStatus: PaymentUiStatus;
}

/**
 * Surfaces a warning when a payment has been in `pending` status for >60s,
 * which usually means the terminal or backend is slow / hung. Backend
 * auto-expires pending payments at 15min via ExpireStalePendingPayments cron,
 * so this is a UX nudge — not a hard timeout.
 */
export function PaymentTimeoutBanner({ paymentStatus }: Props) {
  const { t } = useLocale();
  const [show, setShow] = useState(false);

  useEffect(() => {
    if (paymentStatus !== 'pending') {
      setShow(false);
      return;
    }
    const id = setTimeout(() => setShow(true), TIMEOUT_MS);
    return () => clearTimeout(id);
  }, [paymentStatus]);

  if (!show) return null;

  return (
    <View className="bg-warning/10 border border-warning rounded-xl px-4 py-3 mt-4">
      <Text className="text-warning font-medium text-sm text-center">
        {t('kiosk.payment_timeout_warning')}
      </Text>
    </View>
  );
}
