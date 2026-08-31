import { useCallback, useEffect, useMemo, useState } from "react";
import { Pressable, ScrollView, View } from "react-native";
import Animated, {
  cancelAnimation,
  useAnimatedStyle,
  useSharedValue,
  withRepeat,
  withSequence,
  withTiming,
} from "react-native-reanimated";
import { SafeAreaView } from "react-native-safe-area-context";
import Svg, { Path } from "react-native-svg";
import { router } from "expo-router";
import { useAuth } from "../src/providers/auth-provider";
import { useLocale } from "../src/providers/app-provider";
import { useZones } from "../src/hooks/use-zones";
import { useClearCall } from "../src/hooks/use-call-staff";
import { Button, Skeleton, Text } from "@godxjp/ui-native";
import type { Table, TableDisplayState } from "../src/types/tms";
import { getDisplayState } from "../src/lib/table-display";

// ── Color / style maps ──────────────────────────────────────────────

const CARD_BG: Record<TableDisplayState, string> = {
  free: "bg-white",
  occupied: "bg-emerald-500",
  call_staff: "bg-red-500",
  recently_paid: "bg-sky-200",
  cleaning: "bg-amber-200",
};

const CARD_TEXT: Record<TableDisplayState, string> = {
  free: "text-foreground",
  occupied: "text-white",
  call_staff: "text-white",
  recently_paid: "text-sky-900",
  cleaning: "text-amber-900",
};

const CARD_SUB_TEXT: Record<TableDisplayState, string> = {
  free: "text-muted-foreground",
  occupied: "text-emerald-100",
  call_staff: "text-red-100",
  recently_paid: "text-sky-700",
  cleaning: "text-amber-700",
};

const STATE_I18N: Record<TableDisplayState, string> = {
  free: "table_status.available",
  occupied: "table_status.occupied",
  call_staff: "table_status.call_staff",
  recently_paid: "table_status.recently_paid",
  cleaning: "table_status.cleaning",
};

const LEGEND: { state: TableDisplayState; dot: string }[] = [
  { state: "free", dot: "bg-white border border-gray-300" },
  { state: "occupied", dot: "bg-emerald-500" },
  { state: "call_staff", dot: "bg-red-500" },
  { state: "recently_paid", dot: "bg-sky-300" },
  { state: "cleaning", dot: "bg-amber-300" },
];

// ── Bell icon (SVG-free, pure text) ─────────────────────────────────

function BellIcon() {
  return (
    <Svg width={20} height={20} viewBox="0 0 24 24" fill="white">
      <Path d="M12 2a1 1 0 011 1v.54A7.003 7.003 0 0119 10.5v1.21c0 .81.29 1.6.82 2.21l.65.76A1.5 1.5 0 0119.33 17H4.67a1.5 1.5 0 01-1.14-2.32l.65-.76c.53-.61.82-1.4.82-2.21V10.5a7.003 7.003 0 016-6.96V3a1 1 0 011-1zM9.17 19a3.001 3.001 0 005.66 0H9.17z" />
    </Svg>
  );
}

function GearIcon() {
  return (
    <Svg width={20} height={20} viewBox="0 0 24 24" fill="none" stroke="#6b7280" strokeWidth={1.8}>
      <Path strokeLinecap="round" strokeLinejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28z" />
      <Path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    </Svg>
  );
}

// ── Main screen ─────────────────────────────────────────────────────

export default function HomeScreen() {
  const { device, logout } = useAuth();
  const { t } = useLocale();
  const { zones, isLoading, error, refresh } = useZones();
  const [activeZoneId, setActiveZoneId] = useState<string | null>(null);

  const handleLogout = async () => {
    await logout();
    router.replace("/login");
  };

  const displayZones = useMemo(
    () => activeZoneId ? zones.filter((z) => z.id === activeZoneId) : zones,
    [activeZoneId, zones],
  );

  const allTables = useMemo(
    () => displayZones.flatMap((z) => z.tables),
    [displayZones],
  );

  const stateCounts = useMemo(
    () => allTables.reduce(
      (acc, tbl) => {
        const ds = getDisplayState(tbl);
        acc[ds] = (acc[ds] || 0) + 1;
        return acc;
      },
      {} as Record<string, number>,
    ),
    [allTables],
  );

  return (
    <SafeAreaView className="flex-1 bg-gray-50">
      {/* Header */}
      <View className="px-5 pt-4 pb-3 flex-row items-center justify-between bg-white border-b border-gray-100">
        <View className="flex-1">
          <Text className="text-xl font-bold">{t("tms.title")}</Text>
          <Text className="text-xs text-muted-foreground">
            {device?.branch?.name ?? device?.name ?? "TMS"}
          </Text>
        </View>
        <Button variant="ghost" size="icon" onPress={() => router.push("/settings")}>
          <GearIcon />
        </Button>
        <Button variant="ghost" size="sm" onPress={handleLogout}>
          <Text>{t("common.logout")}</Text>
        </Button>
      </View>

      {/* Zone tabs */}
      <View className="bg-white border-b border-gray-100">
        <ScrollView
          horizontal
          showsHorizontalScrollIndicator={false}
          contentContainerClassName="px-5 gap-2 py-3"
        >
          <Pressable
            onPress={() => setActiveZoneId(null)}
            className={`px-4 py-2 rounded-full ${
              activeZoneId === null ? "bg-primary" : "bg-gray-100"
            }`}
          >
            <Text
              className={`text-sm font-medium ${
                activeZoneId === null
                  ? "text-primary-foreground"
                  : "text-gray-600"
              }`}
            >
              {t("tms.all_zones")}
            </Text>
          </Pressable>
          {zones.map((zone) => (
            <Pressable
              key={zone.id}
              onPress={() => setActiveZoneId(zone.id)}
              className={`px-4 py-2 rounded-full ${
                activeZoneId === zone.id ? "bg-primary" : "bg-gray-100"
              }`}
            >
              <Text
                className={`text-sm font-medium ${
                  activeZoneId === zone.id
                    ? "text-primary-foreground"
                    : "text-gray-600"
                }`}
              >
                {zone.name}
              </Text>
            </Pressable>
          ))}
        </ScrollView>
      </View>

      {/* Legend */}
      <View className="px-5 py-2.5 flex-row flex-wrap gap-x-5 gap-y-1">
        {LEGEND.map(({ state, dot }) => {
          const count = stateCounts[state] || 0;
          return (
            <View key={state} className="flex-row items-center gap-1.5">
              <View className={`w-3 h-3 rounded-sm ${dot}`} />
              <Text className="text-xs text-gray-500">
                {t(STATE_I18N[state])} {count}
              </Text>
            </View>
          );
        })}
      </View>

      {/* Content */}
      <ScrollView className="flex-1" contentContainerClassName="px-4 pb-8">
        {isLoading ? (
          <LoadingSkeleton />
        ) : error ? (
          <View className="flex-1 items-center justify-center py-20 gap-4">
            <Text className="text-destructive text-center">{error}</Text>
            <Button variant="outline" size="sm" onPress={refresh}>
              <Text>{t("tms.retry")}</Text>
            </Button>
          </View>
        ) : allTables.length === 0 ? (
          <View className="flex-1 items-center justify-center py-20">
            <Text variant="muted">{t("tms.table_list")}</Text>
            <Text className="text-xs text-muted-foreground mt-1">
              {zones.length === 0 ? t("tms.no_zones") : t("tms.no_tables")}
            </Text>
          </View>
        ) : (
          displayZones.map((zone) => (
            <View key={zone.id} className="mb-5">
              {activeZoneId === null && (
                <Text className="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2 px-1">
                  {zone.name}
                </Text>
              )}
              <View className="flex-row flex-wrap gap-2.5">
                {zone.tables.map((table) => (
                  <TableCard
                    key={table.id}
                    table={table}
                    t={t}
                  />
                ))}
              </View>
            </View>
          ))
        )}
      </ScrollView>
    </SafeAreaView>
  );
}

// ── Table card ──────────────────────────────────────────────────────

function TableCard({
  table,
  t,
}: {
  table: Table;
  t: (key: string) => string;
}) {
  const ds = getDisplayState(table);
  const { mutate: clearCall } = useClearCall();

  const opacity = useSharedValue(1);

  useEffect(() => {
    if (ds === "call_staff") {
      opacity.value = withRepeat(
        withSequence(
          withTiming(0.3, { duration: 500 }),
          withTiming(1, { duration: 500 }),
        ),
        -1,
      );
    } else {
      cancelAnimation(opacity);
      opacity.value = withTiming(1);
    }
  }, [ds, opacity]);

  const animatedStyle = useAnimatedStyle(() => ({
    opacity: opacity.value,
  }));

  const handleClearCall = useCallback(() => {
    clearCall(table.id);
  }, [clearCall, table.id]);

  return (
    <Animated.View
      style={ds === "call_staff" ? animatedStyle : undefined}
      className={`w-[30%] min-w-[100px] rounded-2xl p-3 gap-1.5 ${CARD_BG[ds]}`}
    >
      {/* Top row: name + bell icon */}
      <View className="flex-row items-center justify-between">
        <Text className={`text-lg font-bold ${CARD_TEXT[ds]}`}>
          {table.name}
        </Text>
        {ds === "call_staff" && <BellIcon />}
      </View>

      {/* Code */}
      <Text className={`text-[10px] ${CARD_SUB_TEXT[ds]}`}>
        {table.code}
      </Text>

      {/* Seats */}
      <Text className={`text-xs ${CARD_SUB_TEXT[ds]}`}>
        {t("tms.seats")}: {table.seat_count}
      </Text>

      {/* Status label */}
      <Text className={`text-[11px] font-semibold ${CARD_TEXT[ds]} mt-0.5`}>
        {t(STATE_I18N[ds])}
      </Text>

      {/* Đã xử lý button — chỉ hiện khi call_staff */}
      {ds === "call_staff" && (
        <Pressable
          onPress={handleClearCall}
          className="mt-1 bg-white/20 rounded-lg py-1 px-2 items-center"
        >
          <Text className="text-white text-[11px] font-semibold">
            ✓ {t("action.call_resolved")}
          </Text>
        </Pressable>
      )}
    </Animated.View>
  );
}

// ── Skeleton ────────────────────────────────────────────────────────

function LoadingSkeleton() {
  return (
    <View className="gap-3 mt-2">
      <Skeleton className="h-3 w-20 mb-1" />
      <View className="flex-row flex-wrap gap-2.5">
        {Array.from({ length: 6 }).map((_, i) => (
          <View key={i} className="w-[30%] min-w-[100px]">
            <Skeleton className="h-28 rounded-2xl" />
          </View>
        ))}
      </View>
    </View>
  );
}
