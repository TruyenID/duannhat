import { StyleSheet, View, type ViewStyle } from 'react-native';
import Feather from '@expo/vector-icons/Feather';

import { ThemedText } from '@/components/ThemedText';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type AlertVariant = 'error' | 'warning' | 'success' | 'info';

interface AlertProps {
  variant?: AlertVariant;
  title?: string;
  message: string;
  style?: ViewStyle;
}

const ICONS: Record<AlertVariant, React.ComponentProps<typeof Feather>['name']> = {
  error:   'alert-circle',
  warning: 'alert-triangle',
  success: 'check-circle',
  info:    'info',
};

function Alert({ variant = 'error', title, message, style }: AlertProps) {
  const theme = useTheme();

  const palette: Record<AlertVariant, { bg: string; text: string; icon: string }> = {
    error:   { bg: theme.errorSoft,   text: theme.error,   icon: theme.error },
    warning: { bg: theme.warningSoft, text: '#8a6500',     icon: theme.warning },
    success: { bg: theme.successSoft, text: '#2c8a5d',     icon: theme.success },
    info:    { bg: theme.infoSoft,    text: theme.info,    icon: theme.info },
  };

  const p = palette[variant];

  return (
    <View style={[styles.container, { backgroundColor: p.bg, borderColor: p.icon + '44' }, style]}>
      <Feather name={ICONS[variant]} size={16} color={p.icon} style={styles.icon} />
      <View style={styles.body}>
        {title && (
          <ThemedText type="label" style={[styles.title, { color: p.text }]}>
            {title}
          </ThemedText>
        )}
        <ThemedText type="small" style={{ color: p.text }}>
          {message}
        </ThemedText>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: Spacing.sm,
    paddingHorizontal: Spacing.md,
    paddingVertical: Spacing.sm,
    borderRadius: Radius.md,
    borderWidth: 1,
  },
  icon: {
    marginTop: 1,
    flexShrink: 0,
  },
  body: { flex: 1, gap: 2 },
  title: { marginBottom: 1 },
});

export { Alert };
export type { AlertVariant };
