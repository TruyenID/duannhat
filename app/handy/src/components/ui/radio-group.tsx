import { Pressable, StyleSheet, View, type ViewStyle } from 'react-native';
import Feather from '@expo/vector-icons/Feather';

import { ThemedText } from '@/components/ThemedText';
import { Radius } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

interface RadioOption<T extends string> {
  value: T;
  label: string;
  description?: string;
  icon?: React.ComponentProps<typeof Feather>['name'];
}

interface RadioGroupProps<T extends string> {
  options: RadioOption<T>[];
  value: T;
  onValueChange: (value: T) => void;
  style?: ViewStyle;
}

function RadioGroup<T extends string>({
  options,
  value,
  onValueChange,
  style,
}: RadioGroupProps<T>) {
  const theme = useTheme();

  return (
    <View style={[styles.card, { backgroundColor: theme.card }, style]}>
      {options.map((opt, index) => {
        const selected = opt.value === value;
        const isLast = index === options.length - 1;

        return (
          <Pressable
            key={opt.value}
            style={({ pressed }) => [
              styles.row,
              !isLast && { borderBottomWidth: StyleSheet.hairlineWidth, borderBottomColor: theme.divider },
              pressed && { backgroundColor: theme.backgroundSelected },
            ]}
            onPress={() => onValueChange(opt.value)}
          >
            <View style={styles.left}>
              {opt.icon && (
                <Feather
                  name={opt.icon}
                  size={18}
                  color={selected ? theme.primary : theme.textSecondary}
                  style={styles.optIcon}
                />
              )}
              <View style={styles.labelBlock}>
                <ThemedText
                  type="small"
                  style={{ color: selected ? theme.primary : theme.text, fontWeight: selected ? '600' : '500' }}
                >
                  {opt.label}
                </ThemedText>
                {opt.description && (
                  <ThemedText type="caption" themeColor="textSecondary">{opt.description}</ThemedText>
                )}
              </View>
            </View>
            {selected && (
              <Feather name="check" size={18} color={theme.primary} />
            )}
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  card: {
    borderRadius: Radius.lg,
    overflow: 'hidden',
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
    paddingVertical: 14,
    minHeight: 50,
  },
  left: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    flex: 1,
  },
  optIcon: {
    width: 20,
  },
  labelBlock: {
    gap: 1,
  },
});

export { RadioGroup };
export type { RadioOption, RadioGroupProps };
