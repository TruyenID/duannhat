import { StyleSheet, View } from 'react-native';

import { ThemedView } from '@/components/ThemedView';
import { Skeleton } from '@/components/ui/skeleton';
import { Layout } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

export function TableCardSkeleton() {
  const theme = useTheme();

  return (
    <ThemedView type="card" style={[styles.card, { borderColor: theme.border }]}>
      <View style={[styles.stripe, { backgroundColor: theme.backgroundSelected }]} />
      <View style={styles.content}>
        <Skeleton width={40} height={10} borderRadius={3} />
        <Skeleton width={32} height={8} borderRadius={3} />
        <Skeleton width={24} height={8} borderRadius={3} />
      </View>
    </ThemedView>
  );
}

const DOT_OFFSET = 6;

const styles = StyleSheet.create({
  card: {
    width: Layout.cardWidth,
    height: Layout.cardHeight,
    marginTop: DOT_OFFSET,
    marginRight: DOT_OFFSET,
    borderRadius: Layout.cardRadius,
    borderWidth: 1,
    flexDirection: 'row',
    overflow: 'hidden',
  },
  stripe: {
    width: Layout.statusStripeWidth,
  },
  content: {
    flex: 1,
    paddingHorizontal: 6,
    paddingVertical: 8,
    justifyContent: 'space-between',
  },
});
