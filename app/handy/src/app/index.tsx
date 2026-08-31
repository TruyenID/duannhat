import { useCallback, useMemo, useState } from 'react';
import {
  Alert,
  Pressable,
  RefreshControl,
  SectionList,
  StyleSheet,
  View,
} from 'react-native';
import { SafeAreaView, useSafeAreaInsets } from 'react-native-safe-area-context';
import { useRouter } from 'expo-router';
import { useQueryClient } from '@tanstack/react-query';

import { AppHeader } from '@/components/AppHeader';
import { TableCard } from '@/components/TableCard';
import { TableCardSkeleton } from '@/components/TableCardSkeleton';
import { ThemedText } from '@/components/ThemedText';
import { ThemedView } from '@/components/ThemedView';
import { Layout, Spacing } from '@/constants/theme';
import { useT } from '@/i18n';
import { useTheme } from '@/hooks/use-theme';
import { useOpenOrders } from '@/hooks/use-open-orders';
import { useTables } from '@/hooks/use-tables';
import { ApiError } from '@/lib/api';
import { useDevice } from '@/lib/device-context';
import { orderService } from '@/services/order-service';
import type { TableResource } from '@/types/pos';

interface ZoneSection {
  zoneId: string;
  zoneName: string;
  data: TableResource[][];
}

const COLS = 3;
const SKELETON_COUNT = 6;

export default function TablesScreen() {
  const t = useT();
  const theme = useTheme();
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const queryClient = useQueryClient();
  const { shopSlug } = useDevice();
  const [creatingTableId, setCreatingTableId] = useState<string | null>(null);

  const tablesQuery = useTables();
  const ordersQuery = useOpenOrders();

  // Map table.id → full CustomerOrder, dùng để truyền vào TableCard.
  // table.current_order_id (từ WS) là source of truth; map này là fallback
  // cho trường hợp WS chưa populate current_order_id.
  const orderByTableId = useMemo(() => {
    const map = new Map<string, import('@/types/pos').CustomerOrder>();
    for (const order of ordersQuery.data ?? []) {
      for (const tbl of order.tables ?? []) {
        map.set(tbl.id, order);
        // WS trả table code (không phải UUID) trong tables[] — index cả code
        if ('code' in tbl && tbl.code) map.set(tbl.code as string, order);
      }
    }
    return map;
  }, [ordersQuery.data]);

  const zoneSections = useMemo((): ZoneSection[] => {
    const tables = tablesQuery.data ?? [];
    const zoneMap = new Map<string, { zoneName: string; tables: TableResource[] }>();
    for (const table of tables) {
      const zoneId = table.zone?.id ?? '__no_zone__';
      const zoneName = table.zone?.name ?? '—';
      if (!zoneMap.has(zoneId)) zoneMap.set(zoneId, { zoneName, tables: [] });
      zoneMap.get(zoneId)!.tables.push(table);
    }
    return Array.from(zoneMap.entries()).map(([zoneId, { zoneName, tables: ts }]) => {
      const rows: TableResource[][] = [];
      for (let i = 0; i < ts.length; i += COLS) rows.push(ts.slice(i, i + COLS));
      return { zoneId, zoneName, data: rows };
    });
  }, [tablesQuery.data]);

  const isLoading = tablesQuery.isLoading && !tablesQuery.data;
  const isError = tablesQuery.isError;
  const isRefreshing = tablesQuery.isFetching || ordersQuery.isFetching;

  const onRefresh = useCallback(() => {
    tablesQuery.refetch();
    ordersQuery.refetch();
  }, [tablesQuery, ordersQuery]);

  const handleTablePress = useCallback(
    async (table: TableResource) => {
      // current_order_id từ WS là source of truth (populated server-side).
      // Fallback sang map nếu WS chưa trả field này.
      const existingOrderId =
        table.current_order_id ??
        orderByTableId.get(table.id)?.id ??
        orderByTableId.get(table.code)?.id;

      if (existingOrderId) {
        router.push({ pathname: '/order/[id]', params: { id: existingOrderId, shopSlug, tableCode: table.code } });
        return;
      }

      // Bàn đang occupied/reserved nhưng chưa có order trong cache
      // (WS lag hoặc orders chưa load xong) → refetch để lấy order mới nhất
      if (table.status !== 'free') {
        setCreatingTableId(table.id);
        try {
          const [freshTables, freshOrders] = await Promise.all([
            tablesQuery.refetch(),
            ordersQuery.refetch(),
          ]);
          // Tìm lại order sau khi refetch
          const freshTable = freshTables.data?.find((t) => t.id === table.id);
          const freshOrderId = freshTable?.current_order_id;
          const mapOrderId =
            freshOrders.data?.find((o) => o.tables?.some((tbl) => tbl.id === table.id || tbl.code === table.code))?.id;
          const resolvedOrderId = freshOrderId ?? mapOrderId;
          if (resolvedOrderId) {
            router.push({ pathname: '/order/[id]', params: { id: resolvedOrderId, shopSlug, tableCode: table.code } });
          } else {
            Alert.alert('', `${table.code} ${t.tables.busyAlert}`);
          }
        } catch {
          Alert.alert('', `${table.code} ${t.tables.busyAlert}`);
        } finally {
          setCreatingTableId(null);
        }
        return;
      }

      Alert.alert(
        t.tables.createOrderConfirmTitle(table.code),
        t.tables.createOrderConfirmMessage,
        [
          { text: t.cancel, style: 'cancel' },
          {
            text: t.tables.createOrderConfirmButton,
            style: 'default',
            onPress: async () => {
              setCreatingTableId(table.id);
              try {
                const res = await orderService.create({ order_type: 'dine_in', table_ids: [table.id] });
                // invalidate thay vì removeQueries — giữ cache cũ, refetch background
                queryClient.invalidateQueries({ queryKey: ['tables', shopSlug] });
                queryClient.invalidateQueries({ queryKey: ['orders', shopSlug, 'list'] });
                router.push({ pathname: '/order/[id]', params: { id: res.data.id, shopSlug, tableCode: table.code } });
              } catch (err) {
                const msg = err instanceof ApiError ? `[${err.status}] ${JSON.stringify(err.body)}` : String(err);
                Alert.alert(t.error, msg);
              } finally {
                setCreatingTableId(null);
              }
            },
          },
        ],
      );
    },
    [router, shopSlug, orderByTableId, queryClient, t, tablesQuery, ordersQuery],
  );

  const renderRow = useCallback(
    ({ item }: { item: TableResource[] }) => (
      <View style={styles.row}>
        {item.map((table) => (
          <TableCard
            key={table.id}
            table={table}
            order={orderByTableId.get(table.id) ?? orderByTableId.get(table.code)}
            onPress={() => handleTablePress(table)}
            creating={creatingTableId === table.id}
          />
        ))}
      </View>
    ),
    [orderByTableId, handleTablePress, creatingTableId],
  );

  const renderSectionHeader = useCallback(
    ({ section }: { section: ZoneSection }) => (
      <View style={styles.sectionHeader}>
        <ThemedText type="subtitle" style={styles.sectionTitle}>{section.zoneName}</ThemedText>
      </View>
    ),
    [],
  );

  if (isLoading) {
    return (
      <SafeAreaView style={[styles.container, { backgroundColor: theme.background }]} edges={['top']}>
        <AppHeader shopName={shopSlug} />
        <View style={[styles.skeletonGrid, { paddingBottom: insets.bottom + Spacing.md }]}>
          {Array.from({ length: SKELETON_COUNT }).map((_, i) => (
            <TableCardSkeleton key={i} />
          ))}
        </View>
      </SafeAreaView>
    );
  }

  if (isError && zoneSections.length === 0) {
    return (
      <SafeAreaView style={[styles.container, { backgroundColor: theme.background }]} edges={['top']}>
        <AppHeader shopName={shopSlug} />
        <View style={styles.errorFullScreen}>
          <ThemedText type="small" style={{ color: theme.error, marginBottom: Spacing.md, textAlign: 'center' }}>
            {t.tables.loadError}
          </ThemedText>
          <Pressable onPress={onRefresh} style={[styles.retryButton, { backgroundColor: theme.primary }]}>
            <ThemedText type="caption" style={{ color: '#fff', fontWeight: '600' }}>{t.retry}</ThemedText>
          </Pressable>
        </View>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={[styles.container, { backgroundColor: theme.background }]} edges={['top']}>
      <AppHeader shopName={shopSlug} />

      {isError && (
        <ThemedView type="errorSoft" style={[styles.errorBanner, { borderBottomColor: theme.error }]}>
          <ThemedText type="small" style={{ color: theme.error, flex: 1 }}>{t.tables.loadError}</ThemedText>
          <Pressable onPress={onRefresh} style={[styles.retryButton, { backgroundColor: theme.error }]}>
            <ThemedText type="caption" style={{ color: theme.background, fontWeight: '600' }}>{t.retry}</ThemedText>
          </Pressable>
        </ThemedView>
      )}

      <SectionList
        sections={zoneSections}
        keyExtractor={(row, i) => row.map((t) => t.id).join('-') + i}
        renderItem={renderRow}
        renderSectionHeader={renderSectionHeader}
        contentContainerStyle={[styles.tableList, { paddingBottom: insets.bottom + Spacing.md }]}
        refreshControl={
          <RefreshControl refreshing={isRefreshing} onRefresh={onRefresh} tintColor={theme.primary} />
        }
        ListEmptyComponent={
          <View style={styles.emptyTables}>
            <ThemedText type="small" themeColor="textSecondary">{t.tables.empty}</ThemedText>
          </View>
        }
      />
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  container: { flex: 1 },
  skeletonGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    paddingHorizontal: Layout.screenPaddingH,
    paddingTop: Spacing.md,
    gap: Layout.cardGap,
  },
  tableList: {
    paddingHorizontal: Layout.screenPaddingH,
    paddingTop: Spacing.md,
  },
  row: { flexDirection: 'row', gap: Layout.cardGap, marginBottom: Layout.cardGap },
  sectionHeader: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingTop: Spacing.lg,
    paddingBottom: Spacing.sm,
  },
  sectionTitle: { fontSize: 13, fontWeight: '600' },
  errorBanner: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'stretch',
    paddingHorizontal: 12,
    paddingVertical: 8,
    gap: 8,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  retryButton: {
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 4,
  },
  emptyTables: { paddingVertical: Spacing.xl, alignItems: 'center' },
  errorFullScreen: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    paddingHorizontal: Layout.screenPaddingH,
  },
});
