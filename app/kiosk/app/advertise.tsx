// app/advertise.tsx — Idle / Welcome screen (redesigned)
import { useRef, useCallback, useState } from "react";
import { Pressable, View } from "react-native";
import { useRouter } from "expo-router";
import { Button, Text } from "@godxjp/ui-native";
import { useAuth } from "../src/providers/auth-provider";
import { useLocale } from "../src/providers/app-provider";
import { usePaymentFlow } from "../src/providers/payment-flow-provider";
import { BannerSlider, type BannerItem } from "../src/components/ui/banner-slider";
import { useHardwareScanner } from "../src/hooks/use-hardware-scanner";
import { resolveQrCode, ApiError } from "../src/lib/api";
import type { LocaleCode } from "../src/i18n";
import { devLog } from "../src/lib/dev-log";

const SECRET_TAP_COUNT = 5;
const SECRET_TAP_WINDOW_MS = 3000;
const FIRST_TAP_DELAY_MS = 700;

const BANNER_COLORS = ["#C2410C", "#3E7B4A", "#1E40AF"];

const LANGS: { id: LocaleCode; flag: string; short: string }[] = [
  { id: "vi", flag: "\u{1F1FB}\u{1F1F3}", short: "VI" },
  { id: "en", flag: "\u{1F1EC}\u{1F1E7}", short: "EN" },
  { id: "ja", flag: "\u{1F1EF}\u{1F1F5}", short: "JP" },
];

export default function AdvertiseScreen() {
  const router = useRouter();
  const { device } = useAuth();
  const { t, locale, setLocale } = useLocale();
  const { reset, setTable, setOrderCode } = usePaymentFlow();
  const branchName = device?.branch?.name ?? "";

  const tapCount = useRef(0);
  const firstTapTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const secretTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const [scannerBusy, setScannerBusy] = useState(false);

  // Hardware QR/barcode scanner (web only) — Mac/desktop scanner gửi
  // keystrokes vào window. Detect burst nhanh + Enter, resolve QR qua
  // cloud rồi navigate sang /bill như camera scan flow ở /select-table.
  const handleHardwareScan = useCallback(
    async (data: string) => {
      if (scannerBusy) return;
      setScannerBusy(true);
      devLog(`[advertise/hw-scan] raw=${JSON.stringify(data)}`);
      let rawToken = data;
      try {
        // Cùng parsing logic với /select-table handleScan: JSON, URL,
        // hoặc raw token.
        try {
          const parsed = JSON.parse(data) as {
            qr_token?: string;
            table_id?: string;
            token?: string;
            orderCode?: string;
            amount?: number;
            items?: { id: string; qty: number }[];
          };
          if (parsed.orderCode) {
            reset();
            setOrderCode(parsed.orderCode);
            // Custom partial-pay QR (customer-web "Tùy chọn"): forward amount
            // to /bill which pre-fills /custom/amount with this value.
            // by_items QR additionally carries items[] — bill.tsx detects
            // this and switches splitMode to by_items so the pay submit
            // attributes the payment to specific items (BE records as
            // metadata.item_allocations).
            const partial = parsed.amount != null && parsed.amount > 0
              ? { partialAmount: String(Math.round(parsed.amount)) }
              : {};
            const items = parsed.items && parsed.items.length > 0
              ? { items: JSON.stringify(parsed.items) }
              : {};
            router.push({
              pathname: "/bill",
              params: { orderCode: parsed.orderCode, ...partial, ...items },
            });
            return;
          }
          rawToken = parsed.token ?? parsed.qr_token ?? parsed.table_id ?? data;
        } catch {
          const match = data.match(/\/table\/([A-Za-z0-9]+)\/?$/);
          if (match) rawToken = match[1];
        }
        devLog(`[advertise/hw-scan] resolving token=${JSON.stringify(rawToken)}`);
        const resolved = await resolveQrCode(rawToken);
        devLog(`[advertise/hw-scan] resolved →`, resolved);
        reset();
        if (resolved.type === "order") {
          setOrderCode(resolved.order_code);
          router.push({ pathname: "/bill", params: { orderCode: resolved.order_code } });
        } else if (resolved.type === "table") {
          setTable(resolved.table_id);
          router.push({ pathname: "/bill", params: { tableId: resolved.table_id } });
        }
      } catch (err) {
        console.warn(
          "[advertise/hw-scan] resolve failed:",
          err instanceof ApiError ? `${err.status} ${err.message}` : err,
        );
      } finally {
        setScannerBusy(false);
      }
    },
    [scannerBusy, reset, setTable, setOrderCode, router],
  );

  useHardwareScanner(handleHardwareScan, { enabled: !scannerBusy });

  const clearTimers = useCallback(() => {
    if (firstTapTimer.current) {
      clearTimeout(firstTapTimer.current);
      firstTapTimer.current = null;
    }
    if (secretTimer.current) {
      clearTimeout(secretTimer.current);
      secretTimer.current = null;
    }
  }, []);

  const goToTable = useCallback(() => {
    reset();
    router.push("/select-table");
  }, [reset, router]);

  const handleScreenTap = useCallback(() => {
    tapCount.current += 1;

    if (tapCount.current === 1) {
      firstTapTimer.current = setTimeout(() => {
        tapCount.current = 0;
        clearTimers();
        goToTable();
      }, FIRST_TAP_DELAY_MS);
      return;
    }

    if (tapCount.current === 2) {
      if (firstTapTimer.current) {
        clearTimeout(firstTapTimer.current);
        firstTapTimer.current = null;
      }
      secretTimer.current = setTimeout(() => {
        tapCount.current = 0;
        clearTimers();
        goToTable();
      }, SECRET_TAP_WINDOW_MS);
      return;
    }

    if (tapCount.current >= SECRET_TAP_COUNT) {
      tapCount.current = 0;
      clearTimers();
      router.push("/settings");
    }
  }, [clearTimers, goToTable, router]);

  // Build banners from i18n
  const banners: BannerItem[] = [
    {
      kicker: t("kiosk.banner_1_kicker"),
      title: t("kiosk.banner_1_title"),
      body: t("kiosk.banner_1_body"),
      color: BANNER_COLORS[0],
    },
    {
      kicker: t("kiosk.banner_2_kicker"),
      title: t("kiosk.banner_2_title"),
      body: t("kiosk.banner_2_body"),
      color: BANNER_COLORS[1],
    },
    {
      kicker: t("kiosk.banner_3_kicker"),
      title: t("kiosk.banner_3_title"),
      body: t("kiosk.banner_3_body"),
      color: BANNER_COLORS[2],
    },
  ];

  return (
    <Pressable className="flex-1 bg-background" onPress={handleScreenTap}>
      {/* Top bar */}
      <View className="h-20 flex-row items-center justify-between px-10">
        <View className="flex-row items-baseline gap-3">
          <Text className="text-2xl font-extrabold tracking-wide text-foreground">
            {branchName || "NHÀ HÀNG"}
          </Text>
          <Text className="text-xs uppercase tracking-[0.22em] text-muted-foreground">
            {device?.name ?? "Kiosk"}
          </Text>
        </View>

        {/* Language pills */}
        <Pressable
          onPress={(e) => e.stopPropagation()}
          className="flex-row gap-1.5 rounded-full border border-border bg-card p-1.5"
        >
          {LANGS.map((L) => {
            const on = locale === L.id;
            return (
              <Pressable
                key={L.id}
                onPress={() => setLocale(L.id)}
                className={[
                  "flex-row items-center gap-2 rounded-full px-4 py-2",
                  on ? "bg-primary" : "bg-transparent",
                ].join(" ")}
              >
                <Text className="text-base">{L.flag}</Text>
                <Text
                  className={[
                    "text-sm",
                    on
                      ? "font-bold text-primary-foreground"
                      : "font-medium text-muted-foreground",
                  ].join(" ")}
                >
                  {L.short}
                </Text>
              </Pressable>
            );
          })}
        </Pressable>
      </View>

      {/* Body — 2-column layout */}
      <View className="flex-1 flex-row">
        {/* LEFT — CTA */}
        <View className="flex-1 justify-center px-10 py-8">
          <Text className="text-7xl font-extrabold leading-none tracking-tighter text-primary">
            {t("kiosk.idle_title")}
          </Text>
          <Text className="mt-5 max-w-[480px] text-xl leading-relaxed text-muted-foreground">
            {t("kiosk.idle_sub")}
          </Text>

          <Pressable
            onPress={(e) => {
              e.stopPropagation();
              goToTable();
            }}
            className="mt-10 self-start flex-row items-center gap-4 rounded-full bg-primary px-8 py-5"
          >
            <View className="h-3 w-3 rounded-full bg-primary-foreground" />
            <Text className="text-xl font-extrabold text-primary-foreground">
              {t("kiosk.idle_start")}
            </Text>
            <Text className="text-xl text-primary-foreground">{"→"}</Text>
          </Pressable>

          <Text className="mt-auto pt-8 text-sm text-muted-foreground">
            {t("kiosk.idle_help")}
          </Text>
        </View>

        {/* RIGHT — Banner slider */}
        <View className="flex-1 justify-center px-6 py-6">
          <Pressable onPress={(e) => e.stopPropagation()}>
            <BannerSlider banners={banners} />
          </Pressable>
        </View>
      </View>
    </Pressable>
  );
}
