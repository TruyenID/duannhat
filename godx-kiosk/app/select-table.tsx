// app/select-table.tsx — Table identification (2-tab: QR scan / keypad code).
// The third "Sơ đồ bàn" (floor-map / direct-pick) tab was removed at the
// request of the user — picking by visual map was confusing on a customer-
// facing kiosk and duplicated the QR-scan path. QR + code cover every real
// scenario.
import { useState, useCallback, useEffect } from "react";
import { Pressable, View, Linking, Platform } from "react-native";
import { useRouter } from "expo-router";
import { CameraView, useCameraPermissions } from "expo-camera";
import { Button, Text } from "@godxjp/ui-native";
import { TopBar } from "../src/components/ui/top-bar";
import { StepRibbon } from "../src/components/ui/step-ribbon";
import { Numpad } from "../src/components/ui/numpad";
import { useLocale } from "../src/providers/app-provider";
import { usePaymentFlow } from "../src/providers/payment-flow-provider";
import { ApiError, resolveQrCode } from "../src/lib/api";
import { CLOUD_URL, isUsingWorkstation } from "../src/services/workstation/base-url-resolver";
import { useHardwareScanner } from "../src/hooks/use-hardware-scanner";

type TabId = "scan" | "code";

export default function SelectTableScreen() {
  const router = useRouter();
  const { t } = useLocale();
  const { setTable, setOrderCode } = usePaymentFlow();
  const [tab, setTab] = useState<TabId>("scan");

  const currentYear = new Date().getFullYear();

  // QR scan resolved handler
  const handleQRResolved = useCallback(
    (tableId: string) => {
      setTable(tableId);
      router.push({ pathname: "/bill", params: { tableId } });
    },
    [setTable, router],
  );

  // Code submit handler — directly navigates to bill with order code
  const handleCodeSubmit = useCallback(
    (code: string) => {
      const orderCode = `ORD-${currentYear}-${code}`;
      setOrderCode(orderCode);
      router.push({ pathname: "/bill", params: { orderCode } });
    },
    [currentYear, setOrderCode, router],
  );

  const tabs: { id: TabId; icon: string; title: string; sub: string }[] = [
    {
      id: "scan",
      icon: "📷",
      title: t("kiosk.table_tab_qr"),
      sub: t("kiosk.table_tab_qr_sub"),
    },
    {
      id: "code",
      icon: "⌨️",
      title: t("kiosk.table_tab_code"),
      sub: t("kiosk.table_tab_code_sub"),
    },
  ];

  return (
    <View className="flex-1 bg-background">
      <TopBar showBack onBack={() => router.replace("/advertise")} />
      <StepRibbon step={0} />

      <View className="flex-1 flex-row">
        {/* LEFT RAIL — heading + method tabs */}
        <View className="w-[340px] border-r border-border/70 bg-card/60 px-7 py-6">
          <View className="mb-4">
            <Text className="text-xs font-semibold uppercase tracking-[0.22em] text-primary">
              {t("kiosk.table_step_label")}
            </Text>
            <Text className="mt-2 text-3xl font-extrabold leading-tight tracking-tight text-foreground">
              {t("kiosk.table_heading")}
            </Text>
            <Text className="mt-2 text-sm leading-relaxed text-muted-foreground">
              {t("kiosk.table_sub")}
            </Text>
          </View>

          {/* Tab buttons */}
          <View className="gap-3">
            {tabs.map((item) => {
              const active = tab === item.id;
              return (
                <Pressable
                  key={item.id}
                  onPress={() => setTab(item.id)}
                  className={[
                    "flex-row items-center gap-4 rounded-2xl border-[1.5px] p-5",
                    active
                      ? "border-primary bg-primary/10"
                      : "border-primary/10 bg-card",
                  ].join(" ")}
                >
                  <View
                    className={[
                      "h-14 w-14 items-center justify-center rounded-xl",
                      active ? "bg-primary" : "bg-muted",
                    ].join(" ")}
                  >
                    <Text className="text-2xl">{item.icon}</Text>
                  </View>
                  <View>
                    <Text className="text-lg font-bold text-foreground">
                      {item.title}
                    </Text>
                    <Text className="mt-0.5 text-sm text-muted-foreground">
                      {item.sub}
                    </Text>
                  </View>
                </Pressable>
              );
            })}
          </View>
        </View>

        {/* RIGHT — active tab content */}
        <View className="flex-1 px-7 py-6">
          <View className="flex-1">
            {tab === "scan" && (
              <QRScanTab
                onTableResolved={handleQRResolved}
                onOrderResolved={(code, partialAmount, items) => {
                  setOrderCode(code);
                  router.push({
                    pathname: "/bill",
                    params: {
                      orderCode: code,
                      ...(partialAmount != null && partialAmount > 0
                        ? { partialAmount: String(Math.round(partialAmount)) }
                        : {}),
                      // by_items hand-off: forward the customer-web selection
                      // verbatim (JSON-encoded). bill.tsx parses + writes to
                      // payment-flow state so the pay screen submits
                      // metadata.item_allocations.
                      ...(items && items.length > 0
                        ? { items: JSON.stringify(items) }
                        : {}),
                    },
                  });
                }}
              />
            )}
            {tab === "code" && <CodeTab onSubmit={handleCodeSubmit} />}
          </View>
        </View>
      </View>
    </View>
  );
}

/** QR Scan Panel — absorbs scan.tsx camera logic.
 *
 * One opaque token per QR. The kiosk strips any JSON/URL wrapper to get the raw
 * token, then asks the backend to decode it (`resolveQrCode`): the response says
 * whether the token is a TABLE (dine-in → fetch order by table) or an ORDER
 * (takeaway/pre-created → open by code). The kiosk routes on `type` and never
 * guesses locally, so the token stays unguessable.
 *
 * Backward-compat fast path: legacy takeaway QR from customer-web embeds the
 * order code inline as `{"orderCode":"ORD-YYYY-XXXX",...}`. If that key is
 * present we route straight to /bill?orderCode=... without a resolve round-trip. */
function QRScanTab({
  onTableResolved,
  onOrderResolved,
}: {
  onTableResolved: (tableId: string) => void;
  onOrderResolved: (
    orderCode: string,
    partialAmount?: number,
    items?: { id: string; qty: number }[],
  ) => void;
}) {
  const { t } = useLocale();
  const [permission, requestPermission] = useCameraPermissions();
  const [scanned, setScanned] = useState(false);
  const [resolving, setResolving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (permission === null) return;
    if (!permission.granted && permission.canAskAgain) {
      requestPermission();
    }
  }, [permission, requestPermission]);

  const handleScan = useCallback(
    async ({ data }: { data: string }) => {
      if (scanned || resolving) return;
      setScanned(true);
      console.log(`[qr-scan] camera fired — raw=${JSON.stringify(data)}`);
      setResolving(true);
      setError(null);
      let rawToken = data;
      try {
        try {
          const parsed = JSON.parse(data) as {
            qr_token?: string;
            table_id?: string;
            token?: string;
            orderCode?: string;
            orderId?: string;
            amount?: number;
            // by_items hand-off — customer-web encodes the selected line
            // items so kiosk's pay submits split_mode=by_items + matching
            // item_allocations to BE.
            items?: { id: string; qty: number }[];
          };
          // Legacy takeaway QR carries the order code inline — skip the resolve.
          // Custom partial-pay QR also carries `amount` (customer-web "Tùy chọn"
          // mode); pass it through so /bill can pre-fill the custom-amount
          // screen instead of defaulting to the full order remaining.
          if (parsed.orderCode) {
            console.log(`[qr-scan] takeaway fast-path → /bill orderCode=${parsed.orderCode} amount=${parsed.amount ?? "<full>"} items=${parsed.items ? parsed.items.length : 0}`);
            onOrderResolved(parsed.orderCode, parsed.amount, parsed.items);
            return;
          }
          rawToken = parsed.token ?? parsed.qr_token ?? parsed.table_id ?? data;
        } catch {
          const match = data.match(/\/table\/([A-Za-z0-9]+)\/?$/);
          if (match) rawToken = match[1];
        }
        console.log(`[qr-scan] resolving token=${JSON.stringify(rawToken)} via cloud=${CLOUD_URL}`);
        const resolved = await resolveQrCode(rawToken);
        console.log(`[qr-scan] resolved →`, resolved);
        if (resolved.type === "order") {
          onOrderResolved(resolved.order_code);
        } else {
          onTableResolved(resolved.table_id);
        }
      } catch (e) {
        // Diagnostic: a 404 while routed through the workstation means the LAN
        // proxy doesn't forward /api/v1/customer/qr/* yet (Cloud-only route).
        const status = e instanceof ApiError ? e.status : "network";
        console.warn(
          `[qr-scan] resolve failed — status=${status}, workstation=${isUsingWorkstation()}, token=${rawToken.slice(0, 8)}…`,
          e,
        );
        setError(t("kiosk.checkout_not_found"));
      } finally {
        setResolving(false);
      }
    },
    [scanned, resolving, onTableResolved, onOrderResolved, t],
  );

  // HID scanner (web only) — forward keystroke buffer vào cùng
  // handleScan flow như camera.
  useHardwareScanner((code) => handleScan({ data: code }), {
    enabled: !scanned && !resolving,
  });

  // Web: bỏ qua camera permission gate, thay vào đó hiển thị UI cho
  // HID scanner (Mac scanner cắm USB sẽ tự gõ keystroke vào page).
  // Camera của laptop có thể dùng được nhưng kiosk-flow ưu tiên scanner
  // ngoại vi vì nhanh + chính xác hơn.
  const isWeb = Platform.OS === "web";

  if (!isWeb && !permission?.granted) {
    return (
      <View className="flex-1 items-center justify-center gap-4">
        <Text className="text-base text-muted-foreground">
          {t("kiosk.scan_permission")}
        </Text>
        <Button
          onPress={() => {
            if (permission?.canAskAgain) requestPermission();
            else Linking.openSettings();
          }}
          variant="outline"
        >
          <Text>
            {permission?.canAskAgain
              ? t("kiosk.scan_grant_permission")
              : t("common.open_settings")}
          </Text>
        </Button>
      </View>
    );
  }

  return (
    <View className="flex-1 items-center justify-center gap-4">
      {isWeb ? (
        // Web: dùng HID scanner (Mac USB). Hiển thị panel chờ scan thay
        // vì camera frame.
        <View className="flex h-80 w-80 items-center justify-center gap-3 rounded-3xl border-2 border-dashed border-primary/40 bg-primary/5">
          <Text className="text-6xl">📡</Text>
          <Text className="text-base font-semibold text-primary">
            {t("kiosk.scan_auto")}
          </Text>
          <Text className="px-6 text-center text-xs text-muted-foreground">
            Quét mã QR bằng thiết bị USB
          </Text>
        </View>
      ) : (
        <View className="h-80 w-80 overflow-hidden rounded-3xl border-2 border-primary/30 bg-foreground">
          <CameraView
            style={{ flex: 1 }}
            facing="back"
            barcodeScannerSettings={{ barcodeTypes: ["qr"] }}
            onBarcodeScanned={handleScan}
          />
        </View>
      )}
      {!isWeb && (
        <Text className="text-sm text-muted-foreground">
          {t("kiosk.scan_auto")}
        </Text>
      )}
      {resolving && (
        <Text className="text-sm text-muted-foreground">
          {t("common.loading")}
        </Text>
      )}
      {error && (
        <Text className="text-sm font-semibold text-destructive">{error}</Text>
      )}
      {scanned && !resolving && (
        <Button
          variant="outline"
          onPress={() => {
            setScanned(false);
            setError(null);
          }}
        >
          <Text>{t("kiosk.scan_rescan")}</Text>
        </Button>
      )}
    </View>
  );
}

/** Code entry panel — numpad */
function CodeTab({ onSubmit }: { onSubmit: (code: string) => void }) {
  const { t } = useLocale();
  const [code, setCode] = useState("");
  const year = new Date().getFullYear();

  return (
    <View className="flex-1 flex-row gap-6">
      {/* Code display */}
      <View className="flex-1 items-center justify-center overflow-hidden rounded-3xl border border-border bg-card px-6 py-8">
        <Text className="text-xs uppercase tracking-[0.22em] text-muted-foreground">
          {t("kiosk.order_code_placeholder")}
        </Text>
        <View className="mt-4 flex-shrink flex-row items-baseline gap-1">
          <Text className="text-xl font-semibold tracking-wider text-muted-foreground">
            ORD-{year}-
          </Text>
          <Text
            className={[
              "text-6xl font-bold tracking-wider",
              code ? "text-foreground" : "text-muted",
            ].join(" ")}
            numberOfLines={1}
            adjustsFontSizeToFit
          >
            {code || "XXXX"}
          </Text>
        </View>
        <Text className="mt-6 text-center text-sm text-muted-foreground">
          {t("kiosk.order_code_hint")}
        </Text>
      </View>
      {/* Numpad */}
      <View className="w-[320px]">
        <Numpad
          value={code}
          onChange={setCode}
          maxLength={6}
          onSubmit={() => {
            if (code.length >= 3) onSubmit(code);
          }}
          submitDisabled={code.length < 3}
          submitLabel={t("common.confirm")}
        />
      </View>
    </View>
  );
}
