import { useState } from 'react';
import {
  Modal,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { SymbolView } from 'expo-symbols';
import Feather from '@expo/vector-icons/Feather';

import { ThemedText } from '@/components/ThemedText';
import { ThemedView } from '@/components/ThemedView';
import { Radius, Spacing } from '@/constants/theme';
import { useT } from '@/i18n';
import { useTheme } from '@/hooks/use-theme';
import { formatMoney } from '@/lib/format-money';
import { useCartStore } from '@/store/cart-store';
import type {
  CartItem,
  ShopMenuProduct,
  ShopMenuProductSku,
  ShopMenuToppingGroup,
  ShopMenuToppingGroupItem,
  ToppingSelection,
} from '@/types/pos';


interface ToppingState {
  // groupItemId → quantity selected
  [groupItemId: string]: number;
}

interface Props {
  item: ShopMenuProduct;
  visible: boolean;
  hidePrice?: boolean;
  currencyCode?: string;
  onClose: () => void;
}

function skuLabel(sku: ShopMenuProductSku): string {
  return sku.product_sku?.name ?? sku.product_sku?.sku ?? `SKU ${sku.id.slice(0, 6)}`;
}

/**
 * Returns the extra price for a topping item.
 * topping_group_item_skus.product_sku_id references the topping item's own
 * product SKU (e.g. "Extra hot" SKU), not the main product's variant SKU —
 * so there is no per-variant pricing to resolve here. Use skus[0] directly.
 */
function resolveToppingExtraPrice(
  item: ShopMenuToppingGroupItem,
): number {
  const sku = item.skus?.[0];
  if (!sku) return 0;
  return parseFloat(sku.extra_price) || 0;
}

/**
 * Returns the product_sku_id to send in the order payload for a topping selection.
 * Uses the first SKU row's product_sku_id (the topping item's own SKU).
 */
function resolveToppingSkuId(
  item: ShopMenuToppingGroupItem,
): string | null {
  return item.skus?.[0]?.product_sku_id ?? null;
}

export function ItemDetailModal({ item, visible, hidePrice = false, currencyCode = 'JPY', onClose }: Props) {
  const t = useT();
  const theme = useTheme();

  const addItem = useCartStore((s) => s.addItem);

  const activeSkus = (item.skus ?? []).filter((s) => s.is_active);
  const defaultSku = activeSkus.find((s) => !!s.product_sku) ?? activeSkus[0];
  const hasMultiSku = activeSkus.length > 1;

  const [selectedSkuId, setSelectedSkuId] = useState<string>(defaultSku?.id ?? '');
  const [toppings, setToppings] = useState<ToppingState>({});
  const [quickNotes, setQuickNotes] = useState<string[]>([]);
  const [note, setNote] = useState('');
  const [qty, setQty] = useState(1);

  const currentSku = activeSkus.find((s) => s.id === selectedSkuId) ?? defaultSku;
  const basePrice = currentSku?.selling_price ?? 0;
  // Recompute discounted price per selected SKU using discount_percent so
  // each variant gets its own accurate price (active_promotion.discounted_price
  // is anchored to the default SKU's price, not the currently selected one).
  const promoPrice = item.active_promotion
    ? Math.round(basePrice * (100 - item.active_promotion.discount_percent) / 100)
    : undefined;
  const toppingGroups: ShopMenuToppingGroup[] = item.product?.topping_groups ?? [];
  const toppingSum = toppingGroups.reduce((sum, group) => {
    const selectedItems = (group.items ?? [])
      .filter((gi) => (toppings[gi.id] ?? 0) > 0)
      .map((gi) => ({ price: resolveToppingExtraPrice(gi), qty: toppings[gi.id] ?? 0 }));
    const raw = selectedItems.reduce((s, { price, qty }) => s + price * qty, 0);
    if (group.price_strategy !== 'free_up_to_n' || !group.free_quantity) return sum + raw;
    // Discount: sort by price desc, waive up to free_quantity units total
    const sorted = selectedItems.slice().sort((a, b) => b.price - a.price);
    let remaining = group.free_quantity;
    const discount = sorted.reduce((d, { price, qty }) => {
      const waived = Math.min(qty, remaining);
      remaining -= waived;
      return d + price * waived;
    }, 0);
    return sum + raw - discount;
  }, 0);
  const unitPrice = (promoPrice ?? basePrice) + toppingSum;
  const totalPrice = unitPrice * qty;
  const minPrice = activeSkus.length ? Math.min(...activeSkus.map((s) => s.selling_price)) : 0;
  // Build the CartItem shape we'd submit so we can look up its cart key for qty
  function buildCartItem() {
    if (!currentSku || !item.product) return null;
    const toppingSelections: ToppingSelection[] = [];
    const toppingLabels: string[] = [];
    toppingGroups.forEach((group) => {
      const isFreeUpToN = group.price_strategy === 'free_up_to_n' && !!group.free_quantity;
      // For free_up_to_n: sort selected items desc by price, waive the most expensive N units
      const selectedInGroup = (group.items ?? [])
        .map((gi) => ({ gi, q: toppings[gi.id] ?? 0, price: resolveToppingExtraPrice(gi) }))
        .filter(({ q }) => q > 0);
      let freeRemaining = isFreeUpToN ? (group.free_quantity ?? 0) : 0;
      const sortedForFree = isFreeUpToN
        ? selectedInGroup.slice().sort((a, b) => b.price - a.price)
        : selectedInGroup;
      // Build a map of waivedQty per groupItemId
      const waivedMap: Record<string, number> = {};
      if (isFreeUpToN) {
        for (const { gi, q } of sortedForFree) {
          const waived = Math.min(q, freeRemaining);
          waivedMap[gi.id] = waived;
          freeRemaining -= waived;
          if (freeRemaining <= 0) break;
        }
      }

      (group.items ?? []).forEach((gi) => {
        const q = toppings[gi.id] ?? 0;
        if (q > 0) {
          const resolvedSkuId = resolveToppingSkuId(gi);
          const extraPrice = resolveToppingExtraPrice(gi);
          const waivedQty = waivedMap[gi.id] ?? 0;
          const chargedQty = q - waivedQty;
          const effectiveTotal = extraPrice * chargedQty;
          if (resolvedSkuId) {
            toppingSelections.push({
              topping_group_item_id: gi.id,
              product_sku_id: resolvedSkuId,
              quantity: q,
              name: gi.name ?? undefined,
              modifier_type: group.modifier_type,
              unit_price: extraPrice > 0 ? Math.round(extraPrice) : undefined,
              topping_group_id: group.id,
              topping_group_name: group.name,
            });
          }
          const prefix = group.modifier_type === 'remove' ? '−' : '+';
          let priceTag = '';
          if (extraPrice > 0) {
            priceTag = effectiveTotal === 0
              ? ` (${formatMoney(extraPrice, currencyCode)} →無料)`
              : effectiveTotal < extraPrice * q
                ? ` ${formatMoney(effectiveTotal, currencyCode)}`
                : ` ${formatMoney(effectiveTotal, currencyCode)}`;
          }
          toppingLabels.push(q > 1 ? `${prefix} ${gi.name ?? '—'} ×${q}${priceTag}` : `${prefix} ${gi.name ?? '—'}${priceTag}`);
        }
      });
    });
    const allNotes = [...quickNotes, note].filter(Boolean).join('、');
    const skuVariantName = hasMultiSku ? skuLabel(currentSku) : undefined;
    return {
      cartItem: {
        product_sku_id: currentSku.product_sku_id,
        menu_product_sku_id: currentSku.id,
        name: item.product.name,
        sku_variant_name: skuVariantName,
        selling_price: unitPrice,
        quantity: qty,
        note: allNotes || undefined,
        toppings: toppingSelections.length > 0 ? toppingSelections : undefined,
        topping_labels: toppingLabels.length > 0 ? toppingLabels : undefined,
      } as CartItem,
    };
  }

  // For multiple-selection groups: toggle on/off, respecting effective_max_select
  function toggleToppingItem(group: ShopMenuToppingGroup, groupItemId: string) {
    setToppings((prev) => {
      const next = { ...prev };
      if (next[groupItemId]) {
        delete next[groupItemId];
      } else {
        const currentCount = (group.items ?? []).reduce((s, gi) => s + (prev[gi.id] ?? 0), 0);
        if (group.effective_max_select !== null && currentCount >= group.effective_max_select) return prev;
        next[groupItemId] = 1;
      }
      return next;
    });
  }

  // For single-selection groups: deselect previous item in the same group
  function selectSingleToppingItem(group: ShopMenuToppingGroup, groupItemId: string) {
    setToppings((prev) => {
      const next = { ...prev };
      // clear all items belonging to this group first
      (group.items ?? []).forEach((gi) => { delete next[gi.id]; });
      // if tapping already-selected → deselect (only when min_select === 0)
      if (prev[groupItemId] && group.effective_min_select === 0) return next;
      next[groupItemId] = 1;
      return next;
    });
  }

  // Returns true when all required topping groups satisfy their effective_min_select
  function toppingValid(): boolean {
    return toppingGroups.every((group) => {
      if (group.effective_min_select <= 0) return true;
      const selected = (group.items ?? []).reduce((s, gi) => s + (toppings[gi.id] ?? 0), 0);
      return selected >= group.effective_min_select;
    });
  }

  function toggleQuickNote(n: string) {
    setQuickNotes((arr) => arr.includes(n) ? arr.filter((x) => x !== n) : [...arr, n]);
  }

  function handleSubmit() {
    const built = buildCartItem();
    if (!built) return;
    addItem(built.cartItem);
    onClose();
  }

return (
    <Modal visible={visible} animationType="slide" presentationStyle="pageSheet" onRequestClose={onClose}>
      <SafeAreaView style={[styles.root, { backgroundColor: theme.card }]} edges={['top']}>
      <ThemedView type="background" style={styles.root}>
        {/* Header */}
        <ThemedView type="card" style={[styles.header, { borderBottomColor: theme.border }]}>
          <Pressable onPress={onClose} style={styles.backBtn} hitSlop={8}>
            <Feather name="chevron-left" size={24} color={theme.primary} />
          </Pressable>
          <View style={styles.headerInfo}>
            <ThemedText type="subtitle" numberOfLines={1}>{item.product?.name ?? '—'}</ThemedText>
            <ThemedText type="caption" themeColor="textSecondary" style={{ marginTop: 2 }}>
              {item.id.slice(0, 4).toUpperCase()}{hidePrice ? '' : ` · ${formatMoney(promoPrice ?? minPrice, currencyCode)}${hasMultiSku ? '〜' : ''}`}
            </ThemedText>
          </View>
        </ThemedView>

        {/* Body */}
        <ScrollView style={styles.body} contentContainerStyle={styles.bodyContent} keyboardShouldPersistTaps="handled">
          {/* Variant picker */}
          {hasMultiSku && (
            <View style={styles.section}>
              <SectionLabel required>{t.itemDetail.selectSize}</SectionLabel>
              {activeSkus.map((sku) => {
                const active = sku.id === selectedSkuId;
                return (
                  <Pressable
                    key={sku.id}
                    style={[styles.variantRow, { backgroundColor: active ? theme.primarySoft : theme.card, borderColor: active ? theme.primary : theme.border }]}
                    onPress={() => setSelectedSkuId(sku.id)}
                  >
                    <View style={[styles.radio, { borderColor: active ? theme.primary : theme.border, backgroundColor: theme.card }]}>
                      {active && <View style={[styles.radioDot, { backgroundColor: theme.primary }]} />}
                    </View>
                    <ThemedText type="small" style={active ? { fontWeight: '600' } : undefined}>{skuLabel(sku)}</ThemedText>
                    {!hidePrice && <ThemedText type="smallBold" style={{ color: active ? theme.primary : theme.text }}>{formatMoney(sku.selling_price, currencyCode)}</ThemedText>}
                  </Pressable>
                );
              })}
            </View>
          )}

          {/* Topping groups */}
          {toppingGroups.map((group) => {
            const isSingle = group.selection_type === 'single';
            const isRequired = group.effective_min_select > 0;
            const selectedCount = (group.items ?? []).reduce((s, gi) => s + (toppings[gi.id] ?? 0), 0);
            const hint = isSingle
              ? t.itemDetail.toppingHintSingle
              : group.effective_max_select
                ? t.itemDetail.toppingHintMultiple(group.effective_min_select, group.effective_max_select)
                : undefined;
            return (
              <View key={group.id} style={styles.section}>
                <SectionLabel required={isRequired} hint={hint}>
                  {group.name}（{group.modifier_type === 'remove' ? t.itemDetail.toppingRemove : t.itemDetail.topping}）
                </SectionLabel>
                {(group.items ?? []).map((gi) => {
                  const qInState = toppings[gi.id] ?? 0;
                  const selected = qInState > 0;
                  const extra = resolveToppingExtraPrice(gi);
                  const maxReached = !isSingle
                    && group.effective_max_select !== null
                    && selectedCount >= group.effective_max_select
                    && !selected;
                  const handlePress = isSingle
                    ? () => selectSingleToppingItem(group, gi.id)
                    : () => toggleToppingItem(group, gi.id);
                  return (
                    <Pressable
                      key={gi.id}
                      style={[
                        styles.toppingRow,
                        {
                          backgroundColor: maxReached ? theme.background : selected ? theme.primarySoft : theme.card,
                          borderColor: maxReached ? theme.border : selected ? theme.primary : theme.border,
                          opacity: maxReached ? 0.4 : 1,
                        },
                      ]}
                      onPress={handlePress}
                      disabled={maxReached}
                    >
                      <View
                        style={isSingle
                          ? [styles.radio, { borderColor: selected ? theme.primary : theme.border, backgroundColor: theme.card }]
                          : [styles.checkbox, { borderColor: selected ? theme.primary : theme.border, backgroundColor: selected ? theme.primary : theme.card }]}
                      >
                        {isSingle
                          ? selected && <View style={[styles.radioDot, { backgroundColor: theme.primary }]} />
                          : selected && (
                            Platform.OS === 'ios'
                              ? <SymbolView name="checkmark" size={13} tintColor={theme.background} weight="bold" />
                              : <ThemedText style={{ color: theme.background, fontSize: 13, fontWeight: '700', lineHeight: 16 }}>✓</ThemedText>
                          )
                        }
                      </View>
                      <ThemedText type="small" style={styles.toppingName} numberOfLines={1}>{gi.name ?? '—'}</ThemedText>
                      {(!hidePrice || extra === 0) && (
                        <ThemedText type="small" style={[styles.toppingPrice, extra === 0 ? { color: theme.success } : undefined]}>
                          {extra === 0 ? t.itemDetail.free : `+${formatMoney(extra, currencyCode)}`}
                        </ThemedText>
                      )}
                    </Pressable>
                  );
                })}
                {isRequired && selectedCount < group.effective_min_select && (
                  <ThemedText type="caption" style={{ color: theme.error, marginTop: 4 }}>
                    {t.itemDetail.toppingRequired(group.effective_min_select)}
                  </ThemedText>
                )}
              </View>
            );
          })}

          {/* Quick notes */}
          <View style={styles.section}>
            <SectionLabel>{t.itemDetail.quickNotes}</SectionLabel>
            <View style={styles.chipWrap}>
              {t.itemDetail.quickNotesList.map((n) => {
                const active = quickNotes.includes(n);
                return (
                  <Pressable
                    key={n}
                    style={[styles.chip, { borderColor: active ? theme.primary : theme.border, backgroundColor: active ? theme.primary : theme.card }]}
                    onPress={() => toggleQuickNote(n)}
                  >
                    <ThemedText type="label" style={{ color: active ? theme.background : theme.text }}>{n}</ThemedText>
                  </Pressable>
                );
              })}
            </View>
          </View>

          {/* Free-form note */}
          <View style={styles.section}>
            <SectionLabel>{t.itemDetail.kitchenNote}</SectionLabel>
            <TextInput
              style={[styles.noteInput, { borderColor: theme.border, color: theme.text, backgroundColor: theme.card }]}
              value={note}
              onChangeText={setNote}
              placeholder={t.itemDetail.kitchenNotePlaceholder}
              placeholderTextColor={theme.textMuted}
              multiline
              numberOfLines={3}
            />
          </View>

          {/* Quantity */}
          <View style={[styles.section, styles.qtyRow]}>
            <SectionLabel>{t.itemDetail.quantity}</SectionLabel>
            <View style={{ flex: 1 }} />
            <QtyStepper value={qty} onChange={setQty} />
          </View>
        </ScrollView>

        {/* Footer */}
        <SafeAreaView edges={['bottom']} style={{ backgroundColor: theme.card }}>
        <ThemedView type="card" style={[styles.footer, { borderTopColor: theme.border }]}>
          <View style={styles.footerSummary}>
            <ThemedText type="caption" themeColor="textSecondary" style={{ textTransform: 'uppercase', letterSpacing: 0.5, fontWeight: '600' }}>
              {t.itemDetail.subtotal}
            </ThemedText>
            <View style={{ flex: 1 }} />
            {!hidePrice && <ThemedText type="title" style={{ fontSize: 22 }}>{formatMoney(totalPrice, currencyCode)}</ThemedText>}
          </View>
          <Pressable
            style={({ pressed }) => [styles.submitBtn, { backgroundColor: theme.primary }, pressed && styles.submitBtnPressed, (!currentSku || !toppingValid()) && styles.submitBtnDisabled]}
            onPress={handleSubmit}
            disabled={!currentSku || !toppingValid()}
          >
            <ThemedText type="smallBold" style={{ color: theme.background, letterSpacing: 0.2 }}>
              {t.itemDetail.addToOrder}
            </ThemedText>
          </Pressable>
        </ThemedView>
        </SafeAreaView>
      </ThemedView>
      </SafeAreaView>
    </Modal>
  );
}

function SectionLabel({ children, required, hint }: { children: React.ReactNode; required?: boolean; hint?: string }) {
  const theme = useTheme();
  return (
    <View style={sl.row}>
      <ThemedText type="caption" themeColor="textSecondary" style={{ textTransform: 'uppercase', letterSpacing: 0.6, fontWeight: '600' }}>
        {children}
      </ThemedText>
      {required && <ThemedText type="caption" style={{ color: theme.error }}> *</ThemedText>}
      {hint && (
        <ThemedText type="caption" themeColor="textMuted" style={{ marginLeft: 6 }}>
          {hint}
        </ThemedText>
      )}
    </View>
  );
}

const sl = StyleSheet.create({
  row: { flexDirection: 'row', alignItems: 'center', marginBottom: 8 },
});

function QtyStepper({ value, onChange }: { value: number; onChange: (q: number) => void }) {
  const theme = useTheme();
  return (
    <View style={[qs.wrap, { borderColor: theme.border }]}>
      <Pressable style={[qs.btn, { backgroundColor: theme.card }, value <= 1 && qs.btnDisabled]} onPress={() => onChange(Math.max(1, value - 1))} disabled={value <= 1}>
        <ThemedText style={{ fontSize: 18, lineHeight: 22 }}>−</ThemedText>
      </Pressable>
      <ThemedText type="subtitle" style={qs.qty}>{value}</ThemedText>
      <Pressable style={[qs.btn, { backgroundColor: theme.card }]} onPress={() => onChange(Math.min(99, value + 1))}>
        <ThemedText style={{ fontSize: 18, lineHeight: 22 }}>+</ThemedText>
      </Pressable>
    </View>
  );
}

const qs = StyleSheet.create({
  wrap: { flexDirection: 'row', alignItems: 'center', borderWidth: 1, borderRadius: Radius.md, overflow: 'hidden', alignSelf: 'flex-start', height: 40 },
  btn: { width: 40, height: 40, justifyContent: 'center', alignItems: 'center' },
  btnDisabled: { opacity: 0.3 },
  qty: { width: 40, textAlign: 'center', fontSize: 15 },
});

const styles = StyleSheet.create({
  root: { flex: 1 },
  header: { flexDirection: 'row', alignItems: 'center', minHeight: 52, paddingHorizontal: Spacing.xs, paddingVertical: 8, borderBottomWidth: 1 },
  backBtn: { width: 44, height: 44, justifyContent: 'center', alignItems: 'center' },
  headerInfo: { flex: 1, minWidth: 0 },
  body: { flex: 1 },
  bodyContent: { padding: 14, gap: 0 },
  section: { marginBottom: 18 },
  qtyRow: { flexDirection: 'row', alignItems: 'center' },
  variantRow: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 14, minHeight: 56, borderWidth: 1, borderRadius: Radius.md, marginBottom: Spacing.sm },
  radio: { width: 20, height: 20, borderRadius: 10, borderWidth: 2, justifyContent: 'center', alignItems: 'center' },
  radioDot: { width: 10, height: 10, borderRadius: 5 },
  toppingRow: { flexDirection: 'row', alignItems: 'center', gap: 10, paddingHorizontal: 12, paddingVertical: 10, minHeight: 52, borderWidth: 1, borderRadius: Radius.sm, marginBottom: 6 },
  checkbox: { width: 22, height: 22, borderRadius: 4, borderWidth: 2, justifyContent: 'center', alignItems: 'center' },
  toppingName: { flex: 1 },
  toppingPrice: { minWidth: 60, textAlign: 'right' },
  chipWrap: { flexDirection: 'row', flexWrap: 'wrap', gap: 6 },
  chip: { height: 36, paddingHorizontal: 14, borderRadius: 999, borderWidth: 1, justifyContent: 'center', alignItems: 'center' },
  noteInput: { borderWidth: 1, borderRadius: Radius.md, padding: 12, fontSize: 14, minHeight: 72, lineHeight: 20, textAlignVertical: 'top' },
  footer: { borderTopWidth: 1, paddingHorizontal: 12, paddingTop: 12 },
  footerSummary: { flexDirection: 'row', alignItems: 'baseline', gap: 8, marginBottom: 4 },
  footerAdded: { flexDirection: 'row', alignItems: 'center', marginBottom: 10 },
  addedBadge: { paddingHorizontal: 8, paddingVertical: 2, borderRadius: 999 },
  footerBtns: { flexDirection: 'row', gap: 8 },
  anotherBtn: { flex: 1, height: 52, borderRadius: Radius.md, borderWidth: 1.5, justifyContent: 'center', alignItems: 'center' },
  submitBtn: { height: 52, borderRadius: Radius.md, justifyContent: 'center', alignItems: 'center', alignSelf: 'stretch' },
  submitBtnPressed: { opacity: 0.8 },
  submitBtnDisabled: { opacity: 0.4 },
});
