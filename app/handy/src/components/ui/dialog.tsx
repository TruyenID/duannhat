import {
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  StyleSheet,
  View,
  type ViewStyle,
} from 'react-native';
import Animated, { FadeIn, FadeOut, SlideInDown, SlideOutDown } from 'react-native-reanimated';

import { ThemedText } from '@/components/ThemedText';
import { Radius, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

const AnimatedPressable = Animated.createAnimatedComponent(Pressable);

interface DialogProps {
  visible: boolean;
  onClose: () => void;
  children: React.ReactNode;
  style?: ViewStyle;
}

function Dialog({ visible, onClose, children, style }: DialogProps) {
  const theme = useTheme();

  return (
    <Modal
      visible={visible}
      transparent
      animationType="none"
      statusBarTranslucent
      onRequestClose={onClose}
    >
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        style={styles.keyboardView}
      >
        <AnimatedPressable
          entering={FadeIn.duration(180)}
          exiting={FadeOut.duration(150)}
          style={styles.overlay}
          onPress={onClose}
        />
        <Animated.View
          entering={SlideInDown.springify().damping(26).stiffness(280)}
          exiting={SlideOutDown.duration(160)}
          style={[styles.card, { backgroundColor: theme.card }, style]}
        >
          {children}
        </Animated.View>
      </KeyboardAvoidingView>
    </Modal>
  );
}

function DialogHeader({ children, style }: { children: React.ReactNode; style?: ViewStyle }) {
  return <View style={[styles.header, style]}>{children}</View>;
}

function DialogTitle({ children }: { children: React.ReactNode }) {
  return <ThemedText type="subtitle">{children}</ThemedText>;
}

function DialogDescription({ children }: { children: React.ReactNode }) {
  return <ThemedText type="small" themeColor="textSecondary">{children}</ThemedText>;
}

function DialogFooter({ children, style }: { children: React.ReactNode; style?: ViewStyle }) {
  return <View style={[styles.footer, style]}>{children}</View>;
}

const styles = StyleSheet.create({
  keyboardView: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
  overlay: {
    ...StyleSheet.absoluteFill,
    backgroundColor: 'rgba(0,0,0,0.45)',
  },
  card: {
    borderRadius: Radius.lg,
    padding: Spacing.lg,
    width: 320,
    maxWidth: '90%',
    gap: Spacing.sm,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 8 },
    shadowOpacity: 0.18,
    shadowRadius: 16,
    elevation: 12,
  },
  header: {
    gap: Spacing.xs,
  },
  footer: {
    flexDirection: 'row',
    gap: Spacing.sm,
    marginTop: Spacing.xs,
  },
});

export { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogTitle };
