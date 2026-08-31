import { router, useLocalSearchParams, useFocusEffect } from 'expo-router';
import { useCallback, useMemo, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  FlatList,
  Pressable,
  RefreshControl,
  StyleSheet,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import Feather from '@expo/vector-icons/Feather';

import { OrderDetailSkeleton } from '@/components/OrderDetailSkeleton';
import { Button } from '@/components/ui/button';
import { Dialog, DialogFooter, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { OrderItemRow } from '@/components/OrderItemRow';
import { ThemedText } from '@/components/ThemedText';
import { ThemedView } from '@/components/ThemedView';
import { Layout, Radius, Spacing } from '@/constants/theme';
import { useT } from '@/i18n';
import { useTheme } from '@/hooks/use-theme';
import { useOrder } from '@/hooks/use-order';
import { useShopOrderSettings } from '@/hooks/use-shop-order-settings';
import { useVoidOrder } from '@/hooks/use-void-order';
import { formatMoney } from '@/lib/format-money';
import type { CustomerOrderStatus } from '@/types/pos';

function formatElapsed(openedAt: string | null, t: ReturnType<typeof useT>): string {
  if (!openedAt) return '';
  const ms = Date.now() - new Date(openedAt).getTime();
  const totalMin = Math.floor(ms / 60000);
  if (totalMin < 60) return t.order.elapsedMin(totalMin);
  const h = Math.floor(totalMin / 60);
  const m = totalMin % 60;
  return m > 0 ? t.order.elapsedHourMin(h, m) : t.order.elapsedHour(h);
}

export default function OrderDetailScreen() {
  const t = useT();
  const theme = useTheme();
  const { id, shopSlug, tableCode } = useLocalSearchParams<{ id: string; shopSlug: string; tableCode?: string }>();

  const { data: order, isLoading, isError, refetch, isFetching } = useOrder(id);
  const { data: settings } = useShopOrderSettings(shopSlug);
  const currencyCode = settings?.currency_code ?? 'JPY';
  const defaultItemStatus = settings?.default_order_item_status ?? 'pending';
  const voidOrder = useVoidOrder();

  const [voidModalVisible, setVoidModalVisible] = useState(false);
  const [voidReason, setVoidReason] = useState('');

  // Fetch fresh data mỗi khi screen được focus — bắt status thay đổi
  // từ bếp trong lúc staff đang ở màn hình khác. Sau khi fetch xong,
  // WS socket (mount ở _layout) tiếp tục invalidate cache real-time
  // khi có order_item.status_changed cho order này.
  useFocusEffect(
    useCallback(() => {
      refetch();
    }, [refetch]),
  );

  const onRefresh = useCallback(() => { refetch(); }, [refetch]);

  const canVoid = useMemo(() => {
    if (!order) return false;
    const blocking = ['preparing', 'ready'] as const;
    return !order.items?.some((i) => blocking.includes(i.status as typeof blocking[number]));
  }, [order]);

  function handleVoidPress() {
    if (!canVoid) {
      Alert.alert(t.voidOrder.blockedTitle, t.voidOrder.blockedMessage);
      return;
    }
    setVoidReason('');
    setVoidModalVisible(true);
  }

  function handleVoidConfirm() {
    if (!voidReason.trim()) return;
    voidOrder.mutate(
      { orderId: id, voidReason: voidReason.trim() },
      {
        onSuccess: () => {
          setVoidModalVisible(false);
          router.back();
        },
      },
    );
  }

  const statusPalette: Record<CustomerOrderStatus, { bg: string; text: string }> = {
    open:     { bg: theme.infoSoft,          text: theme.info },
    dining:   { bg: theme.successSoft,       text: theme.success },
    checkout: { bg: theme.warningSoft,       text: theme.warning },
    paying:   { bg: theme.attentionSoft,     text: theme.attention },
    closed:   { bg: theme.backgroundElement, text: theme.textSecondary },
    voided:   { bg: theme.errorSoft,         text: theme.error },
  };

  // Các trạng thái hiển thị phụ thuộc vào default_order_item_status của shop.
  // Ví dụ: default=preparing → không còn bước pending → ẩn cột pending.
  const visibleStatuses = useMemo((): Array<'pending' | 'preparing' | 'ready' | 'served'> => {
    const full: Array<'pending' | 'preparing' | 'ready' | 'served'> = ['pending', 'preparing', 'ready', 'served'];
    const idx = full.indexOf(defaultItemStatus as typeof full[number]);
    return idx >= 0 ? full.slice(idx) : full;
  }, [defaultItemStatus]);

  const itemCounts = useMemo(() => {
    const items = order?.items ?? [];
    return {
      pending:   items.filter((i) => i.status === 'pending').length,
      preparing: items.filter((i) => i.status === 'preparing').length,
      ready:     items.filter((i) => i.status === 'ready').length,
      served:    items.filter((i) => i.status === 'served').length,
    };
  }, [order?.items]);

  if (isLoading) {
    return (
      <SafeAreaView style={[styles.root, { backgroundColor: theme.background }]} edges={['top', 'left', 'right']}>
        <ThemedView type="primary" style={styles.header}>
          <View style={{ width: 44 }} />
          <View style={{ flex: 1 }} />
        </ThemedView>
        <OrderDetailSkeleton />
      </SafeAreaView>
    );
  }

  if (isError || !order) {
    return (
      <SafeAreaView style={[styles.centered, { backgroundColor: theme.background }]}>
        <ThemedText type="small" themeColor="textSecondary" style={{ marginBottom: Spacing.md }}>{t.order.loadError}</ThemedText>
        <Button variant="primary" label={t.retry} onPress={() => refetch()} />
      </SafeAreaView>
    );
  }

  const displayCode = tableCode ?? order.tables?.[0]?.code ?? id;
  const items = order.items ?? [];
  const statusStyle = statusPalette[order.status] ?? statusPalette.open;
  const elapsed = formatElapsed(order.opened_at, t);
  const totalAmount = Number(order.total_amount);

  return (
    <SafeAreaView style={[styles.root, { backgroundColor: theme.background }]} edges={['top', 'left', 'right']}>
      {/* Header */}
      <ThemedView type="primary" style={styles.header}>
        <Pressable style={styles.backBtn} onPress={() => router.back()} hitSlop={8}>
          <Feather name="chevron-left" size={24} color={theme.background} />
        </Pressable>
        <View style={styles.headerCenter}>
          <ThemedText type="subtitle" style={{ color: theme.background }} numberOfLines={1}>
            {t.order.headerTitle(displayCode)}
          </ThemedText>
          <View style={styles.headerMeta}>
            {elapsed ? (
              <ThemedText type="caption" style={{ color: theme.background, opacity: 0.8 }}>{elapsed}</ThemedText>
            ) : null}
            {items.length > 0 && (
              <ThemedText type="caption" style={{ color: theme.background, opacity: 0.8 }}>
                {t.order.itemCountSuffix(items.length)}
              </ThemedText>
            )}
          </View>
        </View>
      </ThemedView>

      {/* Status counts bar — chỉ hiện các bước từ default_order_item_status trở đi */}
      <ThemedView type="card" style={[styles.statsBar, { borderBottomColor: theme.border }]}>
        {visibleStatuses.map((s) => {
          const meta: Record<typeof s, { label: string; color: string }> = {
            pending:   { label: t.order.statPending,   color: theme.warning },
            preparing: { label: t.order.statPreparing, color: theme.info },
            ready:     { label: t.order.statReady,     color: theme.success },
            served:    { label: t.order.statServed,    color: theme.textSecondary },
          };
          return (
            <View key={s} style={styles.statSegment}>
              <View style={[styles.statDivider, { backgroundColor: theme.border }]} />
              <StatCell label={meta[s].label} value={itemCounts[s]} color={meta[s].color} />
            </View>
          );
        })}
        <View style={styles.statSegment}>
          <View style={[styles.statDivider, { backgroundColor: theme.border }]} />
          <StatCell
            label={t.order.statTotal}
            value={formatMoney(totalAmount, currencyCode)}
            color={theme.text}
            bold
          />
        </View>
      </ThemedView>

      <FlatList
        data={items}
        keyExtractor={(item) => item.id}
        renderItem={({ item }) => (
          <OrderItemRow item={item} orderId={id} currencyCode={currencyCode} defaultItemStatus={defaultItemStatus} />
        )}
        refreshControl={
          <RefreshControl refreshing={isFetching && !isLoading} onRefresh={onRefresh} tintColor={theme.primary} />
        }
        ListEmptyComponent={
          <View style={styles.empty}>
            <ThemedText type="small" themeColor="textSecondary">{t.order.empty}</ThemedText>
          </View>
        }
        contentContainerStyle={styles.listContent}
        style={styles.list}
      />

      {/* Bottom action bar */}
      <SafeAreaView edges={['bottom']} style={[styles.actionBar, { backgroundColor: theme.card, borderTopColor: theme.border }]}>
        <View style={styles.actionRow}>
          {canVoid && (
            <Button
              variant="outline"
              label={t.voidOrder.button}
              onPress={handleVoidPress}
              textStyle={{ color: theme.error }}
              style={{ borderColor: theme.border, height: 48 }}
            />
          )}
          <Button
            variant="primary"
            label={t.order.addMore}
            onPress={() => router.push({ pathname: '/menu', params: { orderId: id, shopSlug, tableCode: displayCode } })}
            style={styles.addBtn}
          />
        </View>
      </SafeAreaView>

      {/* Void order dialog */}
      <Dialog visible={voidModalVisible} onClose={() => setVoidModalVisible(false)}>
        <DialogHeader>
          <DialogTitle>{t.voidOrder.dialogTitle}</DialogTitle>
          <DialogDescription>{t.voidOrder.dialogSubtitle}</DialogDescription>
        </DialogHeader>
        <ThemedText type="small" themeColor="textSecondary" style={{ marginTop: Spacing.sm }}>{t.voidOrder.reasonLabel}</ThemedText>
        <TextInput
          style={[styles.reasonInput, { borderColor: theme.border, color: theme.text }]}
          value={voidReason}
          onChangeText={setVoidReason}
          placeholder={t.voidOrder.reasonPlaceholder}
          placeholderTextColor={theme.textSecondary}
          multiline
          numberOfLines={3}
          autoFocus
        />
        <DialogFooter>
          <Pressable
            style={[styles.modalCancelBtn, { borderColor: theme.border }]}
            onPress={() => setVoidModalVisible(false)}
          >
            <ThemedText type="small" themeColor="textSecondary">{t.cancel}</ThemedText>
          </Pressable>
          <Pressable
            style={[
              styles.confirmVoidBtn,
              { backgroundColor: !voidReason.trim() || voidOrder.isPending ? theme.errorSoft : theme.error },
            ]}
            onPress={handleVoidConfirm}
            disabled={!voidReason.trim() || voidOrder.isPending}
          >
            {voidOrder.isPending
              ? <ActivityIndicator size="small" color={theme.background} />
              : <ThemedText type="smallBold" style={{ color: theme.background }}>{t.voidOrder.confirm}</ThemedText>
            }
          </Pressable>
        </DialogFooter>
      </Dialog>
    </SafeAreaView>
  );
}

interface StatCellProps {
  label: string;
  value: number | string;
  color: string;
  bold?: boolean;
}

function StatCell({ label, value, color, bold = false }: StatCellProps) {
  return (
    <View style={styles.statCell}>
      <ThemedText type="caption" themeColor="textSecondary" style={styles.statLabel}>{label}</ThemedText>
      <ThemedText type={bold ? 'smallBold' : 'subtitle'} style={{ color }}>
        {value}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  root: { flex: 1 },
  centered: { flex: 1, justifyContent: 'center', alignItems: 'center' },

  header: {
    height: Layout.headerHeight,
    flexDirection: 'row',
    alignItems: 'center',
    paddingHorizontal: 4,
  },
  backBtn: { width: 44, height: 44, justifyContent: 'center', alignItems: 'center' },
  headerCenter: { flex: 1, paddingRight: 44, gap: 2 },
  headerMeta: { flexDirection: 'row', gap: 4 },

  statsBar: {
    flexDirection: 'row',
    borderBottomWidth: StyleSheet.hairlineWidth,
    paddingVertical: Spacing.sm,
  },
  statSegment: {
    flex: 1,
    flexDirection: 'row',
    alignItems: 'stretch',
  },
  statCell: {
    flex: 1,
    alignItems: 'center',
    gap: 2,
  },
  statLabel: { fontSize: 10 },
  statDivider: { width: StyleSheet.hairlineWidth, marginVertical: 4 },

  list: { flex: 1 },
  listContent: { flexGrow: 1 },
  empty: { padding: Spacing.xl, alignItems: 'center' },

  actionBar: {
    paddingHorizontal: Layout.screenPaddingH,
    paddingTop: Spacing.sm,
    borderTopWidth: StyleSheet.hairlineWidth,
  },
  actionRow: {
    flexDirection: 'row',
    gap: Spacing.sm,
  },
  addBtn: {
    flex: 1,
    height: 48,
  },

  reasonInput: {
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
  confirmVoidBtn: {
    flex: 1,
    paddingVertical: Spacing.sm,
    borderRadius: Radius.md,
    alignItems: 'center',
  },
});
