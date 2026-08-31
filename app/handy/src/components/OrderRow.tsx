import { StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/ThemedText';
import { ThemedView } from '@/components/ThemedView';
import { useT } from '@/i18n';
import { useTheme } from '@/hooks/use-theme';
import { formatMoney } from '@/lib/format-money';
import type { CustomerOrder } from '@/types/pos';

function formatTime(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

interface Props {
  order: CustomerOrder;
  currencyCode?: string;
}

export function OrderRow({ order, currencyCode = 'JPY' }: Props) {
  const t = useT();
  const theme = useTheme();
  const tableNames = order.tables?.map((t) => t.code).join(', ') ?? '—';
  const itemCount = order.items?.length ?? 0;

  return (
    <ThemedView type="card" style={[styles.row, { borderBottomColor: theme.divider }]}>
      <View style={styles.left}>
        <ThemedText type="label">{order.order_code}</ThemedText>
        <ThemedText type="caption" themeColor="textSecondary">
          {tableNames}{order.opened_at ? `  ${formatTime(order.opened_at)}` : ''}
        </ThemedText>
      </View>
      <View style={styles.right}>
        <ThemedText type="label">{formatMoney(order.total_amount, currencyCode)}</ThemedText>
        <ThemedText type="caption" themeColor="textSecondary">{t.orderRow.itemCount(itemCount)}</ThemedText>
      </View>
    </ThemedView>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 10,
    paddingHorizontal: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  left: { flex: 1, gap: 2 },
  right: { alignItems: 'flex-end', gap: 2 },
});
