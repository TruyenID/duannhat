import { Pressable, ScrollView, StyleSheet, View, type ViewStyle } from 'react-native';

import { ThemedText } from '@/components/ThemedText';
import { useTheme } from '@/hooks/use-theme';

interface TabItem {
  id: string;
  label: string;
}

interface TabsProps {
  items: TabItem[];
  value: string;
  onValueChange: (id: string) => void;
  style?: ViewStyle;
}

function Tabs({ items, value, onValueChange, style }: TabsProps) {
  const theme = useTheme();

  return (
    <View style={[styles.wrapper, { backgroundColor: theme.card, borderBottomColor: theme.borderSoft }, style]}>
      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        contentContainerStyle={styles.container}
        bounces={false}
      >
        {items.map((tab) => {
          const active = tab.id === value;
          return (
            <Pressable
              key={tab.id}
              style={[styles.tab, active && { borderBottomColor: theme.primary }]}
              onPress={() => onValueChange(tab.id)}
              hitSlop={{ top: 4, bottom: 4 }}
            >
              <ThemedText
                type="label"
                style={active ? { color: theme.primary, fontWeight: '700' } : { color: theme.textSecondary }}
              >
                {tab.label}
              </ThemedText>
            </Pressable>
          );
        })}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  wrapper: {
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  container: {
    flexDirection: 'row',
  },
  tab: {
    height: 40,
    paddingHorizontal: 14,
    borderBottomWidth: 2,
    borderBottomColor: 'transparent',
    justifyContent: 'center',
    alignItems: 'center',
  },
});

export { Tabs };
export type { TabItem };
