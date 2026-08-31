import { StyleSheet, View } from 'react-native';

import { ThemedView } from '@/components/ThemedView';
import { Skeleton } from '@/components/ui/skeleton';
import { useTheme } from '@/hooks/use-theme';

export function OrderRowSkeleton() {
  const theme = useTheme();

  return (
    <ThemedView type="card" style={[styles.row, { borderBottomColor: theme.divider }]}>
      <View style={styles.left}>
        <Skeleton width={80} height={10} borderRadius={3} />
        <Skeleton width={60} height={9} borderRadius={3} />
      </View>
      <View style={styles.right}>
        <Skeleton width={50} height={10} borderRadius={3} />
        <Skeleton width={30} height={9} borderRadius={3} />
      </View>
    </ThemedView>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 12,
    paddingHorizontal: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
    gap: 8,
  },
  left: {
    flex: 1,
    gap: 6,
  },
  right: {
    alignItems: 'flex-end',
    gap: 6,
  },
});
