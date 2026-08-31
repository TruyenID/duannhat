import { ActivityIndicator, Platform, Pressable, StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/ThemedText';
import { Layout } from '@/constants/theme';
import { useT } from '@/i18n';
import { useTheme } from '@/hooks/use-theme';
import type { CustomerOrder, TableResource, TableStatusValue } from '@/types/pos';

interface Props {
  table: TableResource;
  order?: CustomerOrder;
  onPress: () => void;
  creating?: boolean;
}

export function TableCard({ table, order, onPress, creating = false }: Props) {
  const t = useT();
  const theme = useTheme();

  const statusPalette = {
    free:           { accent: theme.statusFreeAccent,           bg: theme.statusFreeBg,           border: theme.border },
    occupied:       { accent: theme.statusOccupiedAccent,       bg: theme.statusOccupiedBg,       border: theme.attentionSoft },
    reserved:       { accent: theme.statusReservedAccent,       bg: theme.statusReservedBg,       border: theme.warningSoft },
    cleaning:       { accent: theme.statusCleaningAccent,       bg: theme.statusCleaningBg,       border: theme.infoSoft },
    out_of_service: { accent: theme.statusOutOfServiceAccent,   bg: theme.statusOutOfServiceBg,   border: theme.errorSoft },
  } satisfies Record<TableStatusValue, { accent: string; bg: string; border: string }>;

  const palette = statusPalette[table.status] ?? statusPalette.free;

  const items = order?.items ?? [];
  const readyCount  = items.filter((i) => i.status === 'ready').length;
  const prepCount   = items.filter((i) => i.status === 'preparing').length;
  const pendingCount = items.filter((i) => i.status === 'pending').length;

  // Priority: ready (xanh) > preparing (vàng) > pending (xám)
  const dotColor = readyCount > 0
    ? theme.success
    : prepCount > 0
    ? theme.warning
    : pendingCount > 0
    ? theme.textSecondary
    : null;

  const dotCount = readyCount > 0 ? readyCount
    : prepCount > 0 ? prepCount
    : pendingCount;

  const dotLabel = readyCount > 0
    ? t.tableCard.dotReady(readyCount)
    : prepCount > 0
    ? t.tableCard.dotPreparing(prepCount)
    : null;

  return (
    <View style={styles.wrapper}>
      <Pressable
        onPress={onPress}
        disabled={creating}
        style={({ pressed }) => [
          styles.card,
          { backgroundColor: palette.bg, borderColor: palette.border },
          readyCount > 0 && { borderColor: theme.success, borderWidth: 2 },
          (pressed || creating) && styles.pressed,
        ]}
      >
        <View style={[styles.stripe, { backgroundColor: palette.accent }]} />

        <View style={styles.content}>
          {creating ? (
            <ActivityIndicator size="small" color={theme.primary} style={styles.spinner} />
          ) : (
            <>
              <View style={styles.topRow}>
                <ThemedText type="subtitle" style={styles.code} numberOfLines={1}>
                  {table.code}
                </ThemedText>
              </View>

              <ThemedText
                type="caption"
                style={[styles.statusLabel, { color: palette.accent }]}
                numberOfLines={1}
              >
                {t.tableStatus[table.status]}
              </ThemedText>

              {dotLabel && (
                <ThemedText
                  type="caption"
                  style={{ color: dotColor ?? theme.textSecondary, fontWeight: '700' }}
                  numberOfLines={1}
                >
                  {dotLabel}
                </ThemedText>
              )}
            </>
          )}
        </View>
      </Pressable>

      {/* Dot badge rendered outside the card so Android overflow:hidden doesn't clip it */}
      {dotColor && dotCount > 0 && (
        <View style={[styles.dot, { backgroundColor: dotColor }]}>
          <ThemedText style={styles.dotText}>{dotCount}</ThemedText>
        </View>
      )}
    </View>
  );
}

const DOT_SIZE = 20;
const DOT_OFFSET = 6;

const styles = StyleSheet.create({
  wrapper: {
    width: Layout.cardWidth + DOT_OFFSET,
    height: Layout.cardHeight + DOT_OFFSET,
    marginTop: DOT_OFFSET,
    marginRight: DOT_OFFSET,
  },
  card: {
    position: 'absolute',
    bottom: 0,
    left: 0,
    width: Layout.cardWidth,
    height: Layout.cardHeight,
    borderRadius: Layout.cardRadius,
    borderWidth: 1,
    flexDirection: 'row',
    overflow: Platform.OS === 'ios' ? 'visible' : 'hidden',
  },
  pressed: { opacity: 0.75 },
  stripe: { width: Layout.statusStripeWidth },
  content: {
    flex: 1,
    paddingHorizontal: 8,
    paddingVertical: 10,
    gap: 4,
  },
  spinner: { flex: 1 },
  topRow: {
    flexDirection: 'row',
    alignItems: 'baseline',
    justifyContent: 'space-between',
    gap: 4,
  },
  code: {
    fontSize: 16,
    letterSpacing: 0.3,
    flex: 1,
  },
  statusLabel: {
    fontWeight: '600',
    textTransform: 'uppercase',
    letterSpacing: 0.4,
  },
  dot: {
    position: 'absolute',
    top: 0,
    right: 0,
    minWidth: DOT_SIZE,
    height: DOT_SIZE,
    borderRadius: DOT_SIZE / 2,
    paddingHorizontal: 4,
    justifyContent: 'center',
    alignItems: 'center',
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.25,
    shadowRadius: 2,
    elevation: 3,
  },
  dotText: {
    color: '#fff',
    fontSize: 11,
    fontWeight: '800',
    lineHeight: 14,
    includeFontPadding: false,
  },
});
