// src/components/ui/banner-slider.tsx
import { useEffect, useRef, useState } from "react";
import { Pressable, View } from "react-native";
import { Text } from "@godxjp/ui-native";

export interface BannerItem {
  kicker: string;
  title: string;
  body: string;
  color: string;
}

interface BannerSliderProps {
  banners: BannerItem[];
  interval?: number;
}

export function BannerSlider({ banners, interval = 4500 }: BannerSliderProps) {
  const [idx, setIdx] = useState(0);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (banners.length <= 1) return;
    timerRef.current = setTimeout(
      () => setIdx((i) => (i + 1) % banners.length),
      interval,
    );
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, [idx, banners.length, interval]);

  if (banners.length === 0) return null;
  const b = banners[idx];

  return (
    <View className="min-h-[420px] flex-1 overflow-hidden rounded-3xl border border-border bg-card">
      {/* Kicker badge */}
      <View className="flex-row items-center justify-between px-8 pt-8">
        <View
          className="rounded-full px-3 py-1.5"
          style={{ backgroundColor: b.color }}
        >
          <Text className="text-xs font-bold uppercase tracking-[0.18em] text-white">
            {b.kicker}
          </Text>
        </View>
        <Text className="text-xs text-muted-foreground">
          0{idx + 1} / 0{banners.length}
        </Text>
      </View>

      {/* Content */}
      <View className="flex-1 justify-center px-8 py-5">
        <Text className="text-4xl font-extrabold leading-tight tracking-tight text-foreground">
          {b.title}
        </Text>
        <Text className="mt-4 text-base leading-relaxed text-muted-foreground">
          {b.body}
        </Text>
      </View>

      {/* Dot indicators */}
      <View className="flex-row items-center gap-2 px-8 pb-6">
        {banners.map((_, i) => (
          <Pressable key={i} onPress={() => setIdx(i)}>
            <View
              className={[
                "h-1.5 rounded-full",
                i === idx ? "w-12" : "w-4",
              ].join(" ")}
              style={{
                backgroundColor: i === idx ? b.color : "rgba(0,0,0,0.15)",
              }}
            />
          </Pressable>
        ))}
      </View>
    </View>
  );
}
