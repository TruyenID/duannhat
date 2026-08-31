// src/components/ui/order-summary.tsx
import { View, ScrollView, Image } from 'react-native';
import { Text } from '@godxjp/ui-native';
import { useLocale } from '../../providers/app-provider';
import { formatCurrency } from '../../lib/format';
import type { Order } from '../../types/kiosk';

interface OrderSummaryProps {
  order: Order;
}

export function OrderSummary({ order }: OrderSummaryProps) {
  const { t } = useLocale();

  return (
    <View className="flex-1 bg-card px-5 py-6">
      <Text variant="h4" className="mb-4">{t('kiosk.order_summary_title')}</Text>

      <ScrollView className="flex-1 mb-4" showsVerticalScrollIndicator={false}>
        {order.items.map((item) => (
          <View key={item.id} className="flex-row items-center gap-3 mb-4">
            {item.image_url ? (
              <Image
                source={{ uri: item.image_url }}
                className="w-10 h-10 rounded-xl"
                resizeMode="cover"
              />
            ) : (
              <View className="w-10 h-10 rounded-xl bg-muted items-center justify-center">
                <Text className="text-lg">🍽️</Text>
              </View>
            )}
            <View className="flex-1">
              <Text className="text-sm font-medium text-foreground">{item.name}</Text>
              <Text variant="muted">x{item.quantity}</Text>
            </View>
            <Text className="text-sm font-semibold text-foreground">
              {formatCurrency(item.unit_price * item.quantity, order.currency)}
            </Text>
          </View>
        ))}
      </ScrollView>

      <View className="border-t border-border pt-4 gap-2">
        <View className="flex-row justify-between">
          <Text variant="muted">{t('kiosk.order_subtotal')}</Text>
          <Text className="text-sm text-foreground">
            {formatCurrency(order.subtotal, order.currency)}
          </Text>
        </View>

        {order.discount > 0 && (
          <View className="flex-row justify-between">
            <Text className="text-sm text-destructive">{t('kiosk.order_discount')}</Text>
            <Text className="text-sm text-destructive">
              -{formatCurrency(order.discount, order.currency)}
            </Text>
          </View>
        )}

        <View className="flex-row justify-between items-center mt-2">
          <Text className="text-sm font-bold text-foreground uppercase tracking-wide">
            {t('kiosk.order_total')}
          </Text>
          <Text className="text-2xl font-extrabold text-foreground">
            {formatCurrency(order.total, order.currency)}
          </Text>
        </View>
      </View>
    </View>
  );
}
