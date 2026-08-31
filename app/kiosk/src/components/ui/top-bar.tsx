// src/components/ui/top-bar.tsx
import { useEffect, useState } from "react";
import { Pressable, View } from "react-native";
import { Text } from "@godxjp/ui-native";
import { useRouter } from "expo-router";
import { useAuth } from "../../providers/auth-provider";

interface TopBarProps {
  showBack?: boolean;
  onBack?: () => void;
}

export function TopBar({ showBack, onBack }: TopBarProps) {
  const router = useRouter();
  const { device } = useAuth();
  const branchName = device?.branch?.name ?? "";
  const deviceName = device?.name ?? "Kiosk";

  const [time, setTime] = useState(() => formatTime(new Date()));

  useEffect(() => {
    const interval = setInterval(() => setTime(formatTime(new Date())), 30_000);
    return () => clearInterval(interval);
  }, []);

  return (
    <View className="h-20 flex-row items-center justify-between border-b border-border bg-card px-10">
      <View className="flex-row items-center gap-4">
        {showBack && (
          <Pressable
            onPress={onBack ?? (() => router.back())}
            className="h-12 w-12 items-center justify-center rounded-xl border border-border bg-card"
          >
            <Text className="text-lg text-foreground">{"‹"}</Text>
          </Pressable>
        )}
        <View className="flex-row items-baseline gap-3">
          <Text className="text-lg font-extrabold tracking-wide text-foreground">
            {branchName || "NHÀ HÀNG"}
          </Text>
          <Text className="text-xs uppercase tracking-[0.18em] text-muted-foreground">
            {deviceName}
          </Text>
        </View>
      </View>
      <View className="flex-row items-center gap-3">
        <Text className="text-sm text-muted-foreground">{time}</Text>
      </View>
    </View>
  );
}

function formatTime(date: Date): string {
  return date.toLocaleTimeString("ja-JP", {
    hour: "2-digit",
    minute: "2-digit",
  });
}
