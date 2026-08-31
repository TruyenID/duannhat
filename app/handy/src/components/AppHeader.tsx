import { Animated, Easing, StyleSheet, TouchableOpacity } from 'react-native';
import { useRouter } from 'expo-router';
import Feather from '@expo/vector-icons/Feather';
import { useRef } from 'react';

import { ThemedText } from '@/components/ThemedText';
import { ThemedView } from '@/components/ThemedView';
import { Layout, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { useSync } from '@/hooks/use-sync';

interface Props {
  shopName?: string;
}

export function AppHeader({ shopName }: Props) {
  const theme = useTheme();
  const router = useRouter();
  const { sync, syncing } = useSync();
  const spinAnim = useRef(new Animated.Value(0)).current;

  function startSpin() {
    spinAnim.setValue(0);
    Animated.loop(
      Animated.timing(spinAnim, {
        toValue: 1,
        duration: 700,
        easing: Easing.linear,
        useNativeDriver: true,
      }),
    ).start();
  }

  function stopSpin() {
    spinAnim.stopAnimation();
    spinAnim.setValue(0);
  }

  async function handleSync() {
    startSpin();
    try {
      await sync();
    } finally {
      stopSpin();
    }
  }

  const rotate = spinAnim.interpolate({
    inputRange: [0, 1],
    outputRange: ['0deg', '360deg'],
  });

  return (
    <ThemedView type="sumi" style={styles.header}>
      {/* Left: branch name */}
      <ThemedText style={[styles.branchName, { color: theme.background }]} numberOfLines={1}>
        {shopName ?? '—'}
      </ThemedText>

      {/* Right: sync + settings */}
      <TouchableOpacity
        onPress={handleSync}
        hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
        style={styles.iconButton}
        accessibilityLabel="Sync"
        disabled={syncing}
      >
        <Animated.View style={{ transform: [{ rotate }] }}>
          <Feather name="refresh-cw" size={20} color={theme.background} />
        </Animated.View>
      </TouchableOpacity>

      <TouchableOpacity
        onPress={() => router.push('/settings')}
        hitSlop={{ top: 8, bottom: 8, left: 8, right: 8 }}
        style={styles.iconButton}
        accessibilityLabel="Settings"
      >
        <Feather name="settings" size={20} color={theme.background} />
      </TouchableOpacity>
    </ThemedView>
  );
}


const styles = StyleSheet.create({
  header: {
    height: Layout.headerHeight,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: Layout.screenPaddingH,
  },
  branchName: {
    fontSize: 15,
    fontWeight: '700',
    flex: 1,
  },
  iconButton: {
    marginLeft: Spacing.sm,
  },
});
