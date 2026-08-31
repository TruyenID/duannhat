import { useEffect, useRef, useState } from 'react';
import {
  Pressable,
  StyleSheet,
  TextInput,
  View,
  type TextInputProps,
  type ViewStyle,
} from 'react-native';
import Feather from '@expo/vector-icons/Feather';

import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

interface InputProps extends Omit<TextInputProps, 'style'> {
  leftIcon?: React.ComponentProps<typeof Feather>['name'];
  clearable?: boolean;
  debounceMs?: number;
  onDebouncedChange?: (text: string) => void;
  containerStyle?: ViewStyle;
  error?: boolean;
}

function Input({
  leftIcon,
  clearable = false,
  debounceMs,
  onDebouncedChange,
  containerStyle,
  error = false,
  value,
  onChangeText,
  ...rest
}: InputProps) {
  const theme = useTheme();
  const [draft, setDraft] = useState(value ?? '');
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Sync controlled value changes from outside
  useEffect(() => {
    if (value !== undefined) setDraft(value);
  }, [value]);

  function handleChange(text: string) {
    setDraft(text);
    onChangeText?.(text);
    if (debounceMs && onDebouncedChange) {
      if (timer.current) clearTimeout(timer.current);
      timer.current = setTimeout(() => onDebouncedChange(text), debounceMs);
    }
  }

  function handleClear() {
    setDraft('');
    onChangeText?.('');
    onDebouncedChange?.('');
    if (timer.current) clearTimeout(timer.current);
  }

  return (
    <View
      style={[
        styles.container,
        {
          backgroundColor: theme.card,
          borderColor: error ? theme.error : theme.border,
        },
        containerStyle,
      ]}
    >
      {leftIcon && (
        <Feather
          name={leftIcon}
          size={16}
          color={theme.textSecondary}
          style={styles.leftIcon}
        />
      )}
      <TextInput
        style={[styles.input, { color: theme.text }]}
        value={draft}
        onChangeText={handleChange}
        placeholderTextColor={theme.textSecondary}
        {...rest}
      />
      {clearable && draft.length > 0 && (
        <Pressable style={styles.clearBtn} onPress={handleClear} hitSlop={8}>
          <Feather name="x" size={14} color={theme.textSecondary} />
        </Pressable>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    alignItems: 'center',
    borderRadius: Radius.md,
    borderWidth: 1,
    paddingHorizontal: Spacing.sm,
    height: 40,
  },
  leftIcon: {
    marginRight: Spacing.xs,
  },
  input: {
    flex: 1,
    fontSize: 14,
    paddingVertical: 0,
  },
  clearBtn: {
    paddingLeft: Spacing.xs,
  },
});

export { Input };
export type { InputProps };
