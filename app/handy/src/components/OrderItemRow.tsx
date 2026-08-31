import { useEffect, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  TextInput,
  View,
} from 'react-native';

import { Badge, type BadgeVariant } from '@/components/ui/badge';
import { Dialog, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ThemedText } from '@/components/ThemedText';
import { ThemedView } from '@/components/ThemedView';
import { Radius, Spacing } from '@/constants/theme';
import { useT } from '@/i18n';
import { useTheme } from '@/hooks/use-theme';
import { useRemoveItem } from '@/hooks/use-remove-item';
import { useUpdateItem } from '@/hooks/use-update-item';
import { formatMoney } from '@/lib/format-money';
import type { CustomerOrderItem, OrderItemStatus } from '@/types/pos';

interface Props {
  item: CustomerOrderItem;
  orderId: string;
  currencyCode?: string;
  defaultItemStatus?: string;
}

const LINE_H = 18;

export function OrderItemRow({ item, orderId, currencyCode = 'JPY', defaultItemStatus = 'pending' }: Props) {
  const t = useT();
  const theme = useTheme();
  const updateItem = useUpdateItem();
  const removeItem = useRemoveItem();

  const qty = Number(item.quantity);
  const unitPrice = Number(item.unit_price);
  const subtotal = Number(item.subtotal);

  const [noteModalVisible, setNoteModalVisible] = useState(false);
  const [noteInput, setNoteInput] = useState(item.note ?? '');
  const noteInputRef = useRef<TextInput>(null);

  useEffect(() => { setNoteInput(item.note ?? ''); }, [item.note]);

  // product.name from workstation: "ProductName · SkuVariant"
  const rawName = item.product_sku?.product?.name ?? t.orderItem.noName;
  const dotIdx = rawName.indexOf(' · ');
  const productName = dotIdx !== -1 ? rawName.slice(0, dotIdx) : rawName;
  const skuVariant = dotIdx !== -1 ? rawName.slice(dotIdx + 3) : (item.product_sku?.name ?? null);

  const canDelete = item.status === defaultItemStatus && item.status !== 'served' && item.status !== 'voided';
  const isFinal = item.status === 'served' || item.status === 'voided';
  const isDimmed = item.status === 'voided';

  function handleDelete() {
    removeItem.mutate({ orderId, itemId: item.id });
  }

  const nextStatus: Partial<Record<OrderItemStatus, Exclude<OrderItemStatus, 'voided'>>> = {
    ready: 'served',
  };
  const nextStatusLabel: Partial<Record<OrderItemStatus, string>> = {
    ready: t.itemStatusAction.markServed,
  };
  const [statusPending, setStatusPending] = useState(false);

  function handleStatusAdvance() {
    const next = nextStatus[item.status];
    if (!next) return;
    setStatusPending(true);
    updateItem.mutate(
      { orderId, itemId: item.id, body: { status: next } },
      { onSettled: () => setStatusPending(false) },
    );
  }

  const statusVariant: Record<OrderItemStatus, BadgeVariant> = {
    pending:   'warning',
    preparing: 'info',
    ready:     'success',
    served:    'muted',
    voided:    'error',
  };

  function handleOpenNoteModal() {
    setNoteInput(item.note ?? '');
    setNoteModalVisible(true);
    setTimeout(() => noteInputRef.current?.focus(), 100);
  }

  function handleSaveNote() {
    updateItem.mutate(
      { orderId, itemId: item.id, body: { note: noteInput || null } },
      { onSettled: () => setNoteModalVisible(false) },
    );
  }

  // Base price = unit_price minus topping sum
  const toppingSum = (item.toppings ?? []).reduce((s, t) => {
    if (t.modifier_type === 'remove') return s;
    return s + parseFloat(String(t.unit_price ?? '0')) * Number(t.quantity ?? 1);
  }, 0);
  const baseUnitPrice = unitPrice - toppingSum;

  type Row = { key: string; label: string; price: number | null; color?: string; bold?: boolean };
  const rows: Row[] = [];

  const hasToppings = (item.toppings ?? []).length > 0;

  // Product name — no price on this line
  rows.push({
    key: 'product',
    label: productName,
    price: null,
    bold: true,
  });

  // SKU variant row — carries the base price
  if (skuVariant) {
    rows.push({
      key: 'sku',
      label: skuVariant,
      price: baseUnitPrice > 0 ? baseUnitPrice * qty : subtotal,
    });
  } else {
    // No SKU variant: attach base price to product name line
    rows[0].price = hasToppings ? (baseUnitPrice > 0 ? baseUnitPrice * qty : null) : subtotal;
  }

  // Toppings
  (item.toppings ?? []).forEach((topping, idx) => {
    const isRemove = topping.modifier_type === 'remove';
    const price = parseFloat(String(topping.unit_price ?? '0'));
    const toppingQty = Number(topping.quantity ?? 1);
    const prefix = isRemove ? '–' : '+';
    rows.push({
      key: topping.id ?? `t${idx}`,
      label: `${prefix} ${topping.name ?? ''}${toppingQty > 1 ? ` ×${toppingQty}` : ''}`,
      price: !isRemove && price > 0 ? price * toppingQty : null,
      color: isRemove ? theme.error : theme.info,
    });
  });

  // Legacy note fallback (no structured toppings)
  if (!hasToppings && item.note) {
    item.note.split('\n').forEach((line, idx) => {
      const isRemove = line.startsWith('- ') || line.startsWith('-');
      const isAdd = line.startsWith('+ ') || line.startsWith('+');
      rows.push({
        key: `note${idx}`,
        label: isAdd || isRemove ? line : `※ ${line}`,
        price: null,
        color: isRemove ? theme.error : isAdd ? theme.info : theme.attention,
      });
    });
  }

  // Free-text note when structured toppings exist
  if (hasToppings && item.note) {
    rows.push({ key: 'freenote', label: `※ ${item.note}`, price: null, color: theme.attention });
  }

  return (
    <>
      <Pressable
        style={[styles.row, { backgroundColor: theme.card, borderBottomColor: theme.borderSoft }, isDimmed && styles.rowDimmed]}
        onLongPress={handleOpenNoteModal}
        delayLongPress={400}
      >
        {/* Status badge row */}
        <View style={styles.statusRow}>
          <Badge
            variant={statusVariant[item.status] ?? 'muted'}
            label={t.itemStatus[item.status]}
            dot
          />
        </View>

        {/* Main content: qty box + two-column rows */}
        <View style={styles.mainRow}>
          <ThemedView type="backgroundElement" style={styles.qtyBox}>
            <ThemedText type="label" style={isDimmed ? styles.strikethrough : undefined}>
              ×{qty}
            </ThemedText>
          </ThemedView>

          <View style={styles.contentBlock}>
            {rows.map((r) => (
              <View key={r.key} style={styles.lineRow}>
                <ThemedText
                  type={r.bold ? 'small' : 'caption'}
                  numberOfLines={1}
                  style={[
                    styles.lineName,
                    r.color ? { color: r.color } : undefined,
                    isDimmed ? styles.strikethrough : undefined,
                  ]}
                >
                  {r.label}
                </ThemedText>
                <ThemedText
                  type={r.bold ? 'smallBold' : 'caption'}
                  style={[
                    styles.linePrice,
                    r.color ? { color: r.color } : undefined,
                    isDimmed ? styles.strikethrough : undefined,
                    { opacity: r.price == null ? 0 : 1 },
                  ]}
                >
                  {r.price != null ? formatMoney(r.price, currencyCode) : '—'}
                </ThemedText>
              </View>
            ))}
          </View>
        </View>

        {!isFinal && (canDelete || nextStatus[item.status]) && (
          <View style={styles.actionsRow}>
            <View style={styles.actionsLeft}>
              {canDelete && (
                removeItem.isPending ? (
                  <ActivityIndicator size="small" color={theme.error} />
                ) : (
                  <Pressable style={[styles.cancelBtn, { borderColor: theme.border }]} onPress={handleDelete}>
                    <ThemedText type="label" style={{ color: theme.error }}>{t.orderItem.cancel}</ThemedText>
                  </Pressable>
                )
              )}
              {nextStatus[item.status] && (
                statusPending ? (
                  <ActivityIndicator size="small" color={theme.primary} style={styles.statusAdvanceLoader} />
                ) : (
                  <Pressable
                    style={[styles.statusAdvanceBtn, { backgroundColor: theme.primary }]}
                    onPress={handleStatusAdvance}
                    disabled={statusPending}
                  >
                    <ThemedText type="label" style={{ color: theme.background }}>
                      {nextStatusLabel[item.status]}
                    </ThemedText>
                  </Pressable>
                )
              )}
            </View>
            <ThemedText type="smallBold">{formatMoney(subtotal, currencyCode)}</ThemedText>
          </View>
        )}
      </Pressable>

      <Dialog visible={noteModalVisible} onClose={() => setNoteModalVisible(false)}>
        <DialogHeader>
          <DialogTitle>{productName}</DialogTitle>
        </DialogHeader>
        <ThemedText type="small" themeColor="textSecondary">{t.orderItem.noteLabel}</ThemedText>
        <TextInput
          ref={noteInputRef}
          style={[styles.noteInput, { borderColor: theme.border, color: theme.text }]}
          value={noteInput}
          onChangeText={setNoteInput}
          placeholder={t.orderItem.notePlaceholder}
          placeholderTextColor={theme.textSecondary}
          multiline
          numberOfLines={3}
          returnKeyType="done"
          blurOnSubmit
        />
        <DialogFooter>
          <Pressable style={[styles.modalCancelBtn, { borderColor: theme.border }]} onPress={() => setNoteModalVisible(false)}>
            <ThemedText type="small" themeColor="textSecondary">{t.cancel}</ThemedText>
          </Pressable>
          <Pressable
            style={[styles.confirmBtn, { backgroundColor: theme.primary }, updateItem.isPending && styles.confirmBtnDisabled]}
            onPress={handleSaveNote}
            disabled={updateItem.isPending}
          >
            <ThemedText type="smallBold" style={{ color: theme.background }}>
              {updateItem.isPending ? t.saving : t.save}
            </ThemedText>
          </Pressable>
        </DialogFooter>
      </Dialog>
    </>
  );
}

const styles = StyleSheet.create({
  row: {
    paddingHorizontal: 14,
    paddingVertical: 10,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  rowDimmed: { opacity: 0.55 },
  statusRow: { flexDirection: 'row', justifyContent: 'flex-end', marginBottom: 4 },
  mainRow: { flexDirection: 'row', alignItems: 'flex-start', gap: 10 },
  qtyBox: {
    minWidth: 32,
    height: 28,
    paddingHorizontal: 6,
    borderRadius: 4,
    justifyContent: 'center',
    alignItems: 'center',
    flexShrink: 0,
    marginTop: 1,
  },
  contentBlock: { flex: 1, minWidth: 0 },
  lineRow: { flexDirection: 'row', alignItems: 'baseline', gap: 4 },
  lineName: { flex: 1, minWidth: 0, lineHeight: LINE_H },
  linePrice: { flexShrink: 0, lineHeight: LINE_H, textAlign: 'right', minWidth: 64 },
  strikethrough: { textDecorationLine: 'line-through' },
  actionsRow: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 10 },
  actionsLeft: { flexDirection: 'row', alignItems: 'center', gap: Spacing.sm },
  cancelBtn: {
    height: 36,
    paddingHorizontal: 10,
    borderWidth: 1,
    borderRadius: 6,
    justifyContent: 'center',
    alignItems: 'center',
  },
  statusAdvanceBtn: {
    height: 36,
    paddingHorizontal: 12,
    borderRadius: 6,
    justifyContent: 'center',
    alignItems: 'center',
  },
  statusAdvanceLoader: { marginHorizontal: 8 },
  noteInput: {
    borderWidth: 1,
    borderRadius: Radius.md,
    paddingHorizontal: Spacing.md,
    paddingVertical: Spacing.sm,
    fontSize: 14,
    minHeight: 72,
    textAlignVertical: 'top',
    marginVertical: Spacing.xs,
  },
  modalCancelBtn: {
    flex: 1,
    paddingVertical: Spacing.sm,
    borderRadius: Radius.md,
    borderWidth: 1,
    alignItems: 'center',
  },
  confirmBtn: {
    flex: 1,
    paddingVertical: Spacing.sm,
    borderRadius: Radius.md,
    alignItems: 'center',
  },
  confirmBtnDisabled: { opacity: 0.6 },
});
