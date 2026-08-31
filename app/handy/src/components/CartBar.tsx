import { router } from 'expo-router';
import { Pressable, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ThemedText } from '@/components/ThemedText';
import { Layout } from '@/constants/theme';
import { useT } from '@/i18n';
import { useTheme } from '@/hooks/use-theme';
import { useCartStore } from '@/store/cart-store';
import { formatMoney } from '@/lib/format-money';

interface CartBarProps {
  currencyCode?: string;
}

export function CartBar({ currencyCode = 'JPY' }: CartBarProps) {
  const t = useT();
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const orderId = useCartStore((s) => s.orderId);
  const items = useCartStore((s) => s.items);

  const totalQty = items.reduce((sum, i) => sum + i.quantity, 0);
  const subtotal = items.reduce((sum, i) => sum + i.selling_price * i.quantity, 0);

  if (totalQty === 0) return null;

  return (
    <Pressable
      style={({ pressed }) => [
        styles.bar,
        { bottom: 12 + insets.bottom, backgroundColor: theme.primary },
        pressed && styles.barPressed,
      ]}
      onPress={() => router.push(`/cart?orderId=${orderId}`)}
    >
      <View style={styles.badge}>
        <ThemedText style={[styles.badgeText, { color: theme.primary }]}>{totalQty}</ThemedText>
      </View>
      <ThemedText type="label" style={{ color: theme.background, flex: 1 }}>{t.cartBar.viewCart(totalQty)}</ThemedText>
      <ThemedText type="smallBold" style={{ color: theme.background }}>
        {t.cartBar.subtotal(formatMoney(subtotal, currencyCode))}
      </ThemedText>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  bar: {
    position: 'absolute',
    left: 12,
    right: 12,
    height: Layout.cartBarHeight,
    borderRadius: 8,
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 16,
    gap: 12,
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.35,
    shadowRadius: 10,
    elevation: 8,
  },
  barPressed: {
    opacity: 0.85,
  },
  badge: {
    width: 28,
    height: 28,
    borderRadius: 14,
    backgroundColor: 'rgba(255,255,255,0.9)',
    justifyContent: 'center',
    alignItems: 'center',
  },
  badgeText: {
    fontSize: 13,
    fontWeight: '700',
  },
});
