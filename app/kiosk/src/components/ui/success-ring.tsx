// src/components/ui/success-ring.tsx
import { useEffect } from "react";
import { View } from "react-native";
import { Text } from "@godxjp/ui-native";
import Animated, {
  useSharedValue,
  useAnimatedStyle,
  withSpring,
  withDelay,
  withRepeat,
  withTiming,
  Easing,
} from "react-native-reanimated";

export function SuccessRing() {
  const scale = useSharedValue(0.4);
  const opacity = useSharedValue(0);

  // Expanding ring animations
  const ring0Scale = useSharedValue(0.85);
  const ring0Opacity = useSharedValue(0);
  const ring1Scale = useSharedValue(0.85);
  const ring1Opacity = useSharedValue(0);
  const ring2Scale = useSharedValue(0.85);
  const ring2Opacity = useSharedValue(0);

  useEffect(() => {
    // Pop-in animation for the center circle
    scale.value = withSpring(1, { damping: 12, stiffness: 180 });
    opacity.value = withTiming(1, { duration: 400 });

    // Expanding rings
    const ringAnim = (delay: number) => ({
      scale: withDelay(
        delay,
        withRepeat(
          withTiming(1.4, { duration: 2400, easing: Easing.out(Easing.ease) }),
          -1,
          false,
        ),
      ),
      opacity: withDelay(
        delay,
        withRepeat(
          withTiming(0, { duration: 2400, easing: Easing.out(Easing.ease) }),
          -1,
          false,
        ),
      ),
    });

    const r0 = ringAnim(0);
    ring0Scale.value = r0.scale;
    ring0Opacity.value = r0.opacity;

    const r1 = ringAnim(600);
    ring1Scale.value = r1.scale;
    ring1Opacity.value = r1.opacity;

    const r2 = ringAnim(1200);
    ring2Scale.value = r2.scale;
    ring2Opacity.value = r2.opacity;
  }, []);

  const centerStyle = useAnimatedStyle(() => ({
    transform: [{ scale: scale.value }],
    opacity: opacity.value,
  }));

  const ring0Style = useAnimatedStyle(() => ({
    transform: [{ scale: ring0Scale.value }],
    opacity: ring0Opacity.value,
  }));

  const ring1Style = useAnimatedStyle(() => ({
    transform: [{ scale: ring1Scale.value }],
    opacity: ring1Opacity.value,
  }));

  const ring2Style = useAnimatedStyle(() => ({
    transform: [{ scale: ring2Scale.value }],
    opacity: ring2Opacity.value,
  }));

  return (
    <View className="h-48 w-48 items-center justify-center">
      {/* Expanding rings */}
      <Animated.View
        className="absolute inset-0 rounded-full border-2 border-primary"
        style={ring0Style}
      />
      <Animated.View
        className="absolute inset-0 rounded-full border-2 border-primary"
        style={ring1Style}
      />
      <Animated.View
        className="absolute inset-0 rounded-full border-2 border-primary"
        style={ring2Style}
      />
      {/* Center filled circle with check */}
      <Animated.View
        className="h-40 w-40 items-center justify-center rounded-full bg-primary"
        style={centerStyle}
      >
        <Text className="text-6xl text-primary-foreground">✓</Text>
      </Animated.View>
    </View>
  );
}
