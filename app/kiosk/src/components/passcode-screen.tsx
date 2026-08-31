// src/components/passcode-screen.tsx
import { useCallback, useEffect, useState } from "react";
import { Pressable, View } from "react-native";
import Animated, {
  useAnimatedStyle,
  useSharedValue,
  withSequence,
  withTiming,
} from "react-native-reanimated";
import Svg, { Path } from "react-native-svg";
import { Text } from "@godxjp/ui-native";
import { PASSCODE_LENGTH } from "../lib/constants";

interface PasscodeScreenProps {
  title: string;
  error?: string;
  errorKey?: number;
  onComplete: (code: string) => void;
}

function DeleteIcon() {
  return (
    <Svg width={28} height={28} viewBox="0 0 24 24" fill="none" stroke="#374151" strokeWidth={1.5}>
      <Path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M12 9.75L14.25 12m0 0l2.25 2.25M14.25 12l2.25-2.25M14.25 12L12 14.25m-2.58 4.92l-6.375-6.375a1.125 1.125 0 010-1.59L9.42 4.83c.211-.211.498-.33.796-.33H19.5a2.25 2.25 0 012.25 2.25v10.5a2.25 2.25 0 01-2.25 2.25h-9.284c-.298 0-.585-.119-.796-.33z"
      />
    </Svg>
  );
}

export function PasscodeScreen({ title, error, errorKey, onComplete }: PasscodeScreenProps) {
  const [code, setCode] = useState("");
  const shakeX = useSharedValue(0);

  const shakeStyle = useAnimatedStyle(() => ({
    transform: [{ translateX: shakeX.value }],
  }));

  // Trigger shake when error changes
  useEffect(() => {
    if (error) {
      shakeX.value = withSequence(
        withTiming(10, { duration: 50 }),
        withTiming(-10, { duration: 50 }),
        withTiming(10, { duration: 50 }),
        withTiming(-10, { duration: 50 }),
        withTiming(0, { duration: 50 }),
      );
      setCode("");
    }
  }, [error, errorKey, shakeX]);

  // Auto-submit when all digits entered
  useEffect(() => {
    if (code.length === PASSCODE_LENGTH) {
      onComplete(code);
    }
  }, [code, onComplete]);

  const handlePress = useCallback(
    (digit: string) => {
      setCode((prev) => (prev.length < PASSCODE_LENGTH ? prev + digit : prev));
    },
    [],
  );

  const handleDelete = useCallback(() => {
    setCode((prev) => prev.slice(0, -1));
  }, []);

  const digits = ["1", "2", "3", "4", "5", "6", "7", "8", "9", "", "0", "del"];

  return (
    <View className="flex-1 bg-background flex-row">
      {/* Left side — branding + message */}
      <View className="flex-1 items-center justify-center bg-gray-50 px-8">
        <Text className="text-2xl font-bold text-gray-900 mb-2">{title}</Text>
        {error ? (
          <Text className="text-sm text-destructive">{error}</Text>
        ) : null}
      </View>

      {/* Right side — dots + numpad */}
      <View className="flex-1 items-center justify-center px-8">
        {/* Dots */}
        <Animated.View style={shakeStyle} className="flex-row gap-3 mb-10">
          {Array.from({ length: PASSCODE_LENGTH }).map((_, i) => (
            <View
              key={i}
              className={`w-4 h-4 rounded-full ${
                i < code.length ? "bg-gray-900" : "bg-gray-300"
              }`}
            />
          ))}
        </Animated.View>

        {/* Numpad grid */}
        <View className="w-72">
          <View className="flex-row flex-wrap">
            {digits.map((digit, i) => (
              <View key={i} className="w-1/3 p-2">
                {digit === "" ? (
                  <View className="h-16" />
                ) : digit === "del" ? (
                  <Pressable
                    onPress={handleDelete}
                    className="h-16 rounded-2xl bg-gray-100 items-center justify-center active:bg-gray-200"
                  >
                    <DeleteIcon />
                  </Pressable>
                ) : (
                  <Pressable
                    onPress={() => handlePress(digit)}
                    className="h-16 rounded-2xl bg-gray-100 items-center justify-center active:bg-gray-200"
                  >
                    <Text className="text-2xl font-semibold text-gray-900">
                      {digit}
                    </Text>
                  </Pressable>
                )}
              </View>
            ))}
          </View>
        </View>
      </View>
    </View>
  );
}
