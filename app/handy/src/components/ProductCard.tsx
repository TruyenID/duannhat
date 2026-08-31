import * as Haptics from 'expo-haptics';
import { Pressable, StyleSheet, View } from 'react-native';

import { Badge } from '@/components/ui/badge';
import { ThemedText } from '@/components/ThemedText';
import { ThemedView } from '@/components/ThemedView';
import { Layout, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { formatMoney } from '@/lib/format-money';
import { cartKey, useCartStore } from '@/store/cart-store';
import type { ShopMenuProduct } from '@/types/pos';

interface Props {
  item: ShopMenuProduct;
  index?: number;
  hidePrice?: boolean;
  currencyCode?: string;
  onOpenDetail: (item: ShopMenuProduct) => void;
}

export function ProductCard({ item, index = 0, hidePrice = false, currencyCode = 'JPY', onOpenDetail }: Props) {
  const theme = useTheme();
  const addItem = useCartStore((s) => s.addItem);
  const updateQty = useCartStore((s) => s.updateQty);
  const cartItems = useCartStore((s) => s.items);

  const product = item.product;
  const activeSkus = (item.skus ?? []).filter((s) => s.is_active);
  const singleSku = activeSkus.length === 1 ? activeSkus[0] : undefined;
  const hasMultiSku = activeSkus.length > 1;

  const allSkuIds = new Set(activeSkus.map((s) => s.id));
  const inCartQty = cartItems
    .filter((c) => allSkuIds.has(c.menu_product_sku_id))
    .reduce((s, c) => s + c.quantity, 0);

  // For a single-SKU product with no toppings, there is only one possible cart line.
  const singleSkuCartItem = singleSku
    ? cartItems.find((c) => c.menu_product_sku_id === singleSku.id)
    : undefined;
  const singleSkuQty = singleSkuCartItem?.quantity ?? 0;

  const promoPrice = item.active_promotion?.discounted_price;
  const minPrice = activeSkus.length
    ? Math.min(...activeSkus.map((s) => s.selling_price))
    : 0;
  const displayPrice = promoPrice ?? (singleSku?.selling_price ?? minPrice);
  const isHighlighted = inCartQty > 0;
  const disabled = !item.is_active || activeSkus.length === 0;

  const hasToppings = (item.product?.topping_groups?.length ?? 0) > 0;

  function handleAdd() {
    if (disabled) return;
    Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
    if (hasMultiSku || hasToppings) { onOpenDetail(item); return; }
    if (!singleSku || !product) return;
    addItem({
      product_sku_id: singleSku.product_sku_id,
      menu_product_sku_id: singleSku.id,
      name: product.name,
      selling_price: promoPrice ?? singleSku.selling_price,
      quantity: 1,
    });
  }

  function handleSub() {
    if (!singleSkuCartItem || singleSkuQty <= 0) return;
    Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
    updateQty(cartKey(singleSkuCartItem), singleSkuQty - 1);
  }

  return (
    <ThemedView
      type={isHighlighted ? 'primarySoft' : 'card'}
      style={[styles.row, index > 0 && { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: theme.borderSoft }]}
    >
      <ThemedText type="caption" themeColor="textMuted" style={styles.code} numberOfLines={1}>
        {item.product_id?.slice(0, 4).toUpperCase() ?? '—'}
      </ThemedText>

      <Pressable style={styles.info} onPress={() => !disabled && (hasMultiSku ? onOpenDetail(item) : handleAdd())} disabled={disabled}>
        <ThemedText type="small" numberOfLines={1}>
          {product?.name ?? '—'}
        </ThemedText>
        {hasMultiSku && (
          <ThemedText type="caption" themeColor="textSecondary" numberOfLines={1}>
            {activeSkus.map((s) => s.product_sku?.name ?? s.product_sku?.sku ?? '').filter(Boolean).join(' · ')}
          </ThemedText>
        )}
      </Pressable>

      {!hasMultiSku && (
        <View style={styles.priceWrap}>
          {hidePrice ? null : promoPrice != null ? (
            <>
              <ThemedText type="smallBold" style={{ color: theme.primary }}>{formatMoney(promoPrice, currencyCode)}</ThemedText>
              <ThemedText type="caption" themeColor="textSecondary" style={styles.strikePrice}>{formatMoney(minPrice, currencyCode)}</ThemedText>
            </>
          ) : (
            <ThemedText type="smallBold" numberOfLines={1}>
              {formatMoney(displayPrice, currencyCode)}
            </ThemedText>
          )}
        </View>
      )}

      {hasMultiSku || hasToppings ? (
        <Pressable style={styles.multiBtn} onPress={() => !disabled && onOpenDetail(item)} disabled={disabled}>
          {inCartQty > 0 && (
            <Badge variant="info" label={`×${inCartQty}`} style={styles.cartBadge} />
          )}
          <ThemedText themeColor="textSecondary" style={styles.chevron}>›</ThemedText>
        </Pressable>
      ) : singleSkuQty <= 0 ? (
        <Pressable
          style={({ pressed }) => [styles.addBtn, { backgroundColor: theme.primary }, (pressed || disabled) && styles.addBtnPressed]}
          onPress={handleAdd}
          disabled={disabled}
        >
          <ThemedText style={[styles.addBtnText, { color: theme.background }]}>＋</ThemedText>
        </Pressable>
      ) : (
        <View style={styles.stepper}>
          <Pressable style={[styles.stepBtn, { borderColor: theme.primary }]} onPress={handleSub}>
            <ThemedText style={[styles.stepIcon, { color: theme.primary }]}>−</ThemedText>
          </Pressable>
          <ThemedText type="smallBold" style={styles.stepQty}>{singleSkuQty}</ThemedText>
          <Pressable style={[styles.stepBtn, styles.stepBtnFilled, { backgroundColor: theme.primary, borderColor: theme.primary }]} onPress={handleAdd} disabled={disabled}>
            <ThemedText style={[styles.stepIcon, { color: theme.background }]}>＋</ThemedText>
          </Pressable>
        </View>
      )}
    </ThemedView>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    paddingHorizontal: Layout.screenPaddingH,
    paddingVertical: 7,
    minHeight: 44,
  },
  code: {
    width: 38,
    letterSpacing: 0.5,
    flexShrink: 0,
  },
  info: { flex: 1, minWidth: 0 },
  priceWrap: { alignItems: 'flex-end', flexShrink: 0 },
  strikePrice: {
    textDecorationLine: 'line-through',
  },
  multiBtn: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    flexShrink: 0,
    paddingLeft: 4,
  },
  cartBadge: {
    borderRadius: 4,
  },
  chevron: {
    fontSize: 18,
    lineHeight: 22,
  },
  addBtn: {
    width: 32,
    height: 32,
    borderRadius: 16,
    justifyContent: 'center',
    alignItems: 'center',
    flexShrink: 0,
  },
  addBtnPressed: { opacity: 0.6 },
  addBtnText: {
    fontSize: 18,
    lineHeight: 22,
    fontWeight: '400',
    textAlign: 'center',
    marginTop: -1,
  },
  stepper: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.xs,
    flexShrink: 0,
  },
  stepBtn: {
    width: 28,
    height: 28,
    borderRadius: 14,
    borderWidth: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
  stepBtnFilled: {},
  stepIcon: {
    fontSize: 16,
    lineHeight: 20,
  },
  stepQty: {
    minWidth: 26,
    textAlign: 'center',
  },
});
