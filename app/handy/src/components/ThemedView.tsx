import { View, type ViewProps } from 'react-native';

import { type ThemeColor } from '@/constants/colors';
import { useTheme } from '@/hooks/use-theme';

export type ThemedViewProps = ViewProps & {
  type?: ThemeColor;
};

export function ThemedView({ style, type, ...rest }: ThemedViewProps) {
  const theme = useTheme();
  return (
    <View
      style={[type ? { backgroundColor: theme[type] } : undefined, style]}
      {...rest}
    />
  );
}
