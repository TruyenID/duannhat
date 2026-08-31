import { StyleSheet, View, type ViewStyle } from 'react-native';

import { ThemedText } from '@/components/ThemedText';
import { useTheme } from '@/hooks/use-theme';

type BadgeVariant = 'default' | 'success' | 'warning' | 'error' | 'info' | 'attention' | 'muted';

interface BadgeProps {
  variant?: BadgeVariant;
  label: string;
  dot?: boolean;
  style?: ViewStyle;
}

function Badge({ variant = 'default', label, dot = false, style }: BadgeProps) {
  const theme = useTheme();

  const palette: Record<BadgeVariant, { bg: string; text: string; dot: string }> = {
    default:   { bg: theme.backgroundElement, text: theme.textSecondary, dot: theme.textMuted },
    success:   { bg: theme.successSoft,       text: '#2c8a5d',           dot: theme.success },
    warning:   { bg: theme.warningSoft,       text: '#8a6500',           dot: theme.warning },
    error:     { bg: theme.errorSoft,         text: theme.error,         dot: theme.error },
    info:      { bg: theme.infoSoft,          text: theme.info,          dot: theme.info },
    attention: { bg: theme.attentionSoft,     text: theme.attention,     dot: theme.attention },
    muted:     { bg: theme.backgroundElement, text: theme.textSecondary, dot: theme.textMuted },
  };

  const p = palette[variant];

  return (
    <View style={[styles.pill, { backgroundColor: p.bg }, style]}>
      {dot && <View style={[styles.dot, { backgroundColor: p.dot }]} />}
      <ThemedText type="caption" style={[styles.text, { color: p.text }]}>
        {label}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  pill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 5,
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 999,
    alignSelf: 'flex-start',
  },
  dot: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
  text: {
    fontWeight: '600',
    lineHeight: 14,
  },
});

export { Badge };
export type { BadgeVariant };
