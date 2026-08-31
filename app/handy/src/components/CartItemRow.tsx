import { Pressable, StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/ThemedText';
import { ThemedView } from '@/components/ThemedView';
import { useT } from '@/i18n';
import { useTheme } from '@/hooks/use-theme';
import { cartKey, useCartStore } from '@/store/cart-store';
import { formatMoney } from '@/lib/format-money';
import type { CartItem } from '@/types/pos';

interface Props {
  item: CartItem;
  disabled?: boolean;
  currencyCode?: string;
}

const LINE_H = 18;

export function CartItemRow({ item, disabled = false, currencyCode = 'JPY' }: Props) {
  const t = useT();
  const theme = useTheme();
  const updateQty = useCartStore((s) => s.updateQty);
  const removeItem = useCartStore((s) => s.removeItem);

  const subtotal = item.selling_price * item.quantity;

  const productName = item.name;
  const skuVariant = item.sku_variant_name ?? null;

  type ToppingRow = { label: string; price: number | null; color: string };
  const toppingRows: ToppingRow[] = (item.toppings ?? []).map((topping) => {
    const isRemove = topping.modifier_type === 'remove';
    const color = isRemove ? theme.error : theme.primary;
    const price = topping.unit_price != null ? Number(topping.unit_price) : null;
    const qty = Number(topping.quantity ?? 1);
    const prefix = isRemove ? '−' : '+';
    const label = `${prefix} ${topping.name ?? ''}${qty > 1 ? ` ×${qty}` : ''}`;
    return {
      label,
      price: !isRemove && price && price > 0 ? price * qty : null,
      color,
    };
  });

  const toppingSum = toppingRows.reduce((s, r) => s + (r.price ?? 0), 0);
  const baseSubtotal = subtotal - toppingSum;

  return (
    <ThemedView
      type="card"
      style={[styles.row, { borderBottomColor: theme.borderSoft }, disabled && styles.rowDisabled]}
    >
      {/* Two-column: names left, prices right */}
      <View style={styles.topRow}>
        <View style={styles.contentBlock}>
          {/* Product name row — price here only when no SKU variant */}
          <View style={styles.lineRow}>
            <ThemedText type="small" numberOfLines={2} style={styles.lineName}>
              {productName}{item.quantity > 1 ? `  ×${item.quantity}` : ''}
            </ThemedText>
            {!skuVariant && (
              <ThemedText type="smallBold" style={styles.linePrice}>
                {formatMoney(baseSubtotal, currencyCode)}
              </ThemedText>
            )}
          </View>

          {/* SKU variant row — price here when SKU exists */}
          {skuVariant && (
            <View style={styles.lineRow}>
              <ThemedText type="caption" themeColor="textSecondary" style={styles.lineName}>
                {skuVariant}
              </ThemedText>
              <ThemedText type="smallBold" style={styles.linePrice}>
                {formatMoney(baseSubtotal, currencyCode)}
              </ThemedText>
            </View>
          )}

          {/* Topping rows */}
          {toppingRows.map((r, i) => (
            <View key={i} style={styles.lineRow}>
              <ThemedText type="caption" numberOfLines={1} style={[styles.lineName, { color: r.color }]}>
                {r.label}
              </ThemedText>
              <ThemedText
                type="caption"
                style={[
                  styles.linePrice,
                  { color: r.color, opacity: r.price != null ? 1 : 0 },
                ]}
              >
                {r.price != null ? formatMoney(r.price, currencyCode) : '—'}
              </ThemedText>
            </View>
          ))}

          {/* Free-text note */}
          {item.note ? (
            <ThemedText type="caption" style={{ color: theme.attention, marginTop: 2 }} numberOfLines={2}>
              ※ {item.note}
            </ThemedText>
          ) : null}
        </View>
      </View>

      {/* Bottom: cancel + qty stepper */}
      <View style={styles.bottomRow}>
        <Pressable
          style={styles.deleteBtn}
          onPress={() => !disabled && removeItem(cartKey(item))}
          disabled={disabled}
          hitSlop={6}
        >
          <ThemedText type="caption" style={{ color: theme.error, fontWeight: '500' }}>{t.cart.cancelItem}</ThemedText>
        </Pressable>

        <View style={styles.stepper}>
          <Pressable
            style={[styles.stepBtn, { borderColor: theme.primary }]}
            onPress={() => !disabled && updateQty(cartKey(item), item.quantity - 1)}
            disabled={disabled}
          >
            <ThemedText style={[styles.stepIcon, { color: theme.primary }]}>−</ThemedText>
          </Pressable>
          <ThemedText type="smallBold" style={styles.stepQty}>{item.quantity}</ThemedText>
          <Pressable
            style={[styles.stepBtn, { backgroundColor: theme.primary, borderColor: theme.primary }]}
            onPress={() => !disabled && updateQty(cartKey(item), item.quantity + 1)}
            disabled={disabled}
          >
            <ThemedText style={[styles.stepIcon, { color: theme.background }]}>＋</ThemedText>
          </Pressable>
        </View>
      </View>
    </ThemedView>
  );
}

const styles = StyleSheet.create({
  row: {
    paddingHorizontal: 14,
    paddingVertical: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  rowDisabled: { opacity: 0.6 },
  topRow: {
    flexDirection: 'row',
    marginBottom: 10,
  },
  contentBlock: { flex: 1, minWidth: 0 },
  lineRow: { flexDirection: 'row', alignItems: 'baseline', gap: 4 },
  lineName: { flex: 1, minWidth: 0, lineHeight: LINE_H },
  linePrice: { flexShrink: 0, lineHeight: LINE_H, textAlign: 'right', minWidth: 64 },
  bottomRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  deleteBtn: {
    height: 32,
    paddingHorizontal: 10,
    justifyContent: 'center',
    alignItems: 'center',
  },
  stepper: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
  },
  stepBtn: {
    width: 36,
    height: 36,
    borderRadius: 18,
    borderWidth: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
  stepIcon: {
    fontSize: 16,
    lineHeight: 20,
  },
  stepQty: {
    minWidth: 26,
    textAlign: 'center',
  },
});
