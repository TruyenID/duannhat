import { ActivityIndicator, Pressable, StyleSheet, type ViewStyle, type TextStyle } from 'react-native';

import { ThemedText } from '@/components/ThemedText';
import { Radius } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type ButtonVariant = 'primary' | 'outline' | 'ghost' | 'destructive';
type ButtonSize = 'sm' | 'md' | 'lg';

interface ButtonProps {
  label: string;
  onPress: () => void;
  variant?: ButtonVariant;
  size?: ButtonSize;
  loading?: boolean;
  disabled?: boolean;
  style?: ViewStyle;
  textStyle?: TextStyle;
  fullWidth?: boolean;
}

function Button({
  label,
  onPress,
  variant = 'primary',
  size = 'md',
  loading = false,
  disabled = false,
  style,
  textStyle,
  fullWidth = false,
}: ButtonProps) {
  const theme = useTheme();
  const isDisabled = disabled || loading;

  const variantStyles: Record<ButtonVariant, { bg: string; border?: string; text: string }> = {
    primary:     { bg: theme.primary,     text: theme.background },
    outline:     { bg: 'transparent',    border: theme.border,  text: theme.text },
    ghost:       { bg: 'transparent',                           text: theme.textSecondary },
    destructive: { bg: theme.error,       text: theme.background },
  };

  const sizeHeight: Record<ButtonSize, number> = { sm: 36, md: 48, lg: 54 };
  const sizePadH: Record<ButtonSize, number>  = { sm: 12, md: 20, lg: 24 };

  const v = variantStyles[variant];

  return (
    <Pressable
      onPress={onPress}
      disabled={isDisabled}
      style={({ pressed }) => [
        styles.base,
        {
          height: sizeHeight[size],
          paddingHorizontal: sizePadH[size],
          backgroundColor: v.bg,
          borderColor: v.border ?? 'transparent',
          borderWidth: v.border ? 1 : 0,
          borderRadius: Radius.md,
          alignSelf: fullWidth ? 'stretch' : 'flex-start',
        },
        (pressed || isDisabled) && styles.pressed,
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator
          size="small"
          color={variant === 'outline' || variant === 'ghost' ? theme.primary : theme.background}
        />
      ) : (
        <ThemedText
          type="label"
          style={[{ color: v.text }, textStyle]}
        >
          {label}
        </ThemedText>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  base: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: 'transparent',
  },
  pressed: { opacity: 0.6 },
});

export { Button };
export type { ButtonVariant, ButtonSize, ButtonProps };
