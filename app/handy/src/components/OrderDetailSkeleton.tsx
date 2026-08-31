import { StyleSheet, View } from 'react-native';

import { ThemedView } from '@/components/ThemedView';
import { Skeleton } from '@/components/ui/skeleton';
import { Layout, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

export function OrderDetailSkeleton() {
  const theme = useTheme();

  return (
    <View style={styles.container}>
      {[0, 1, 2, 3].map((i) => (
        <ThemedView
          key={i}
          type="card"
          style={[styles.row, { borderBottomColor: theme.divider }]}
        >
          <View style={styles.left}>
            <Skeleton width={160} height={12} borderRadius={3} />
            <Skeleton width={80} height={10} borderRadius={3} />
          </View>
          <View style={styles.right}>
            <Skeleton width={60} height={12} borderRadius={3} />
          </View>
        </ThemedView>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    paddingTop: Spacing.sm,
  },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: Spacing.sm,
    paddingHorizontal: Layout.screenPaddingH,
    borderBottomWidth: 1,
    gap: Spacing.sm,
  },
  left: {
    flex: 1,
    gap: 6,
  },
  right: {
    alignItems: 'flex-end',
  },
});
