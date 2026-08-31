import { StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/ThemedText';
import { ThemedView } from '@/components/ThemedView';
import { Spacing } from '@/constants/theme';
import { useT } from '@/i18n';
import { useTheme } from '@/hooks/use-theme';
import { formatMoney } from '@/lib/format-money';
import type { CustomerOrder } from '@/types/pos';

interface Props {
  order: CustomerOrder;
  currencyCode?: string;
}

export function OrderSummary({ order, currencyCode = 'JPY' }: Props) {
  const t = useT();
  const theme = useTheme();
  const subtotal = Number(order.subtotal);
  const taxAmount = Number(order.tax_amount);
  const serviceCharge = Number(order.service_charge);
  const totalAmount = Number(order.total_amount);

  // plan-043 T5.7 — per-rate consumption-tax breakdown (8%対象 / 10%対象) from
  // the order payload. OPTIONAL: fall back to the single tax line when an old
  // payload lacks `tax_breakdown`. Only rows with a positive rate or tax show.
  const taxRows = (order.tax_breakdown ?? []).filter((r) => r.rate > 0 || r.tax !== 0);
  const hasBreakdown = taxRows.length > 0;

  return (
    <ThemedView type="card" style={[styles.container, { borderTopColor: theme.border }]}>
      <SummaryRow label={t.summary.subtotal} value={subtotal} currencyCode={currencyCode} />
      {hasBreakdown
        ? taxRows.map((row) => (
            <SummaryRow
              key={row.rate}
              label={`${t.summary.taxRateLine(row.rate)} — ${t.summary.taxIncludedInline}`}
              value={row.tax}
              currencyCode={currencyCode}
            />
          ))
        : taxAmount > 0 && (
            <SummaryRow label={t.summary.tax} value={taxAmount} currencyCode={currencyCode} />
          )}
      {serviceCharge > 0 && <SummaryRow label={t.summary.serviceCharge} value={serviceCharge} currencyCode={currencyCode} />}
      <View style={[styles.divider, { backgroundColor: theme.divider }]} />
      <SummaryRow
        label={`${t.summary.total}${order.is_tax_included ? ` (${t.summary.taxIncludedBadge})` : ''}`}
        value={totalAmount}
        currencyCode={currencyCode}
        bold
      />
    </ThemedView>
  );
}

function SummaryRow({ label, value, bold, currencyCode }: { label: string; value: number; bold?: boolean; currencyCode: string }) {
  return (
    <View style={styles.row}>
      <ThemedText type={bold ? 'smallBold' : 'small'} themeColor={bold ? 'text' : 'textSecondary'}>
        {label}
      </ThemedText>
      <ThemedText type={bold ? 'smallBold' : 'small'} themeColor={bold ? 'text' : 'textSecondary'}>
        {formatMoney(value, currencyCode)}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    paddingHorizontal: Spacing.md,
    paddingVertical: Spacing.sm,
    borderTopWidth: 1,
  },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingVertical: 4,
  },
  divider: {
    height: 1,
    marginVertical: Spacing.xs,
  },
});
