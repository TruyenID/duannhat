import { Alert, FlatList, Pressable, StyleSheet, View } from 'react-native';
import { useRef } from 'react';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';

import * as Haptics from 'expo-haptics';
import { router, useLocalSearchParams } from 'expo-router';
import Feather from '@expo/vector-icons/Feather';

import { CartItemRow } from '@/components/CartItemRow';
import { Button } from '@/components/ui/button';
import { ThemedText } from '@/components/ThemedText';
import { ThemedView } from '@/components/ThemedView';
import { Layout, Spacing } from '@/constants/theme';
import { useT } from '@/i18n';
import { useTheme } from '@/hooks/use-theme';
import { useAddItems } from '@/hooks/use-add-items';
import { useFireOrder } from '@/hooks/use-fire-order';
import { useOrder } from '@/hooks/use-order';
import { useShopOrderSettings } from '@/hooks/use-shop-order-settings';
import { useDevice } from '@/lib/device-context';
import { formatMoney } from '@/lib/format-money';
import { cartKey, useCartStore } from '@/store/cart-store';
import type { CartItem } from '@/types/pos';

export default function CartScreen() {
  const t = useT();
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const { orderId } = useLocalSearchParams<{ orderId: string }>();
  const { shopSlug } = useDevice();

  const submitted = useRef(false);
  const items = useCartStore((s) => s.items);
  const clearCart = useCartStore((s) => s.clear);
  const { data: order } = useOrder(orderId);
  const { mutate: addItems, isPending } = useAddItems();
  const { mutate: fireOrder } = useFireOrder();
  const { data: settings } = useShopOrderSettings(shopSlug);
  const currencyCode = settings?.currency_code ?? 'JPY';
  const tableCode = order?.tables?.[0]?.code ?? '—';
  const subtotal = items.reduce((sum, i) => sum + i.selling_price * i.quantity, 0);


  function handleSubmit() {
    if (items.length === 0) return;
    addItems(
      {
        orderId,
        items: items.map((i) => ({
          product_sku_id: i.product_sku_id,
          menu_product_sku_id: i.menu_product_sku_id,
          sku_variant_name: i.sku_variant_name || undefined,
          quantity: i.quantity,
          selling_price: i.selling_price,
          note: i.note || undefined,
          toppings: i.toppings,
        })),
      },
      {
        onSuccess: () => {
          submitted.current = true;
          Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
          router.back();
          // Clear cart after navigating so the empty-state flash is never visible.
          clearCart();
          // Fire to kitchen after items are saved — workstation prints the ticket.
          fireOrder(orderId, {
            onSuccess: (res) => {
              if (res.status === 'partial') {
                Alert.alert(t.error, t.cart.firePartial);
              }
            },
            onError: () => {
              // Items were saved — warn about print failure.
              Alert.alert(t.error, t.cart.fireError);
            },
          });
        },
        onError: (err) => Alert.alert(t.error, err.message ?? t.cart.submitError),
      },
    );
  }

  return (
    <SafeAreaView style={[styles.root, { backgroundColor: theme.background }]} edges={['top', 'left', 'right']}>
      {/* Header */}
      <ThemedView type="card" style={[styles.header, { borderBottomColor: theme.border }]}>
        <Pressable onPress={() => router.back()} style={styles.closeBtn} hitSlop={8}>
          <Feather name="x" size={20} color={theme.textSecondary} />
        </Pressable>
        <ThemedText type="subtitle">{t.cart.title(tableCode)}</ThemedText>
      </ThemedView>

      {items.length === 0 && !isPending && !submitted.current ? (
        <View style={styles.empty}>
          <ThemedText type="small" themeColor="textSecondary">{t.cart.empty}</ThemedText>
          <Button variant="primary" label={t.cart.addMore} onPress={() => router.back()} style={{ alignSelf: 'center' }} />
        </View>
      ) : (
        <>
          <FlatList<CartItem>
            data={items}
            keyExtractor={(i) => cartKey(i)}
            renderItem={({ item }) => <CartItemRow item={item} />}
            contentContainerStyle={[styles.list, { backgroundColor: theme.card }]}
            ItemSeparatorComponent={() => <View style={[styles.separator, { backgroundColor: theme.divider }]} />}
          />
          <ThemedView type="card" style={[styles.summaryRow, { borderTopColor: theme.divider }]}>
            <ThemedText type="small" themeColor="textSecondary">{t.cart.subtotalLabel}</ThemedText>
            <ThemedText type="subtitle">{formatMoney(subtotal, currencyCode)}</ThemedText>
          </ThemedView>
        </>
      )}

      {(items.length > 0 || isPending) && (
        <ThemedView type="background" style={[styles.footerWrap, { paddingBottom: Spacing.md + insets.bottom }]}>
          <Button
            variant="primary"
            size="lg"
            label={isPending ? t.cart.submitting : t.cart.submit}
            onPress={handleSubmit}
            loading={isPending}
            fullWidth
            style={styles.submitBtn}
          />
        </ThemedView>
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1 },
  header: {
    height: Layout.headerHeight,
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 4,
    borderBottomWidth: 1,
  },
  closeBtn: { width: 44, height: 44, justifyContent: 'center', alignItems: 'center' },
  list: { paddingHorizontal: Layout.screenPaddingH, paddingTop: Spacing.sm, paddingBottom: Spacing.lg },
  separator: { height: 1 },
  summaryRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: Layout.screenPaddingH,
    paddingVertical: Spacing.md,
    borderTopWidth: 1,
    marginTop: Spacing.sm,
  },
  footerWrap: { paddingHorizontal: Layout.screenPaddingH, paddingTop: Spacing.md },
  submitBtn: {
    shadowOffset: { width: 0, height: 6 },
    shadowOpacity: 0.35,
    shadowRadius: 10,
    elevation: 6,
  },
  empty: { flex: 1, justifyContent: 'center', alignItems: 'center', gap: Spacing.lg },
});
