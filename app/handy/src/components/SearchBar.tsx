import { StyleSheet } from 'react-native';

import { Input } from '@/components/ui/input';
import { Spacing } from '@/constants/theme';

interface Props {
  value: string;
  onChangeText: (text: string) => void;
  placeholder?: string;
}

export function SearchBar({ value, onChangeText, placeholder = '' }: Props) {
  return (
    <Input
      value={value}
      leftIcon="search"
      clearable
      debounceMs={300}
      onDebouncedChange={onChangeText}
      placeholder={placeholder}
      returnKeyType="search"
      containerStyle={styles.container}
    />
  );
}

const styles = StyleSheet.create({
  container: {
    marginHorizontal: Spacing.md,
    marginTop: Spacing.sm,
  },
});
