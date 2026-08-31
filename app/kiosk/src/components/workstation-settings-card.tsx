import { useCallback, useEffect, useState } from "react";
import { ActivityIndicator, Switch, View } from "react-native";
import Svg, { Path } from "react-native-svg";
import { Button, Input, Text } from "@godxjp/ui-native";

import { useTranslation } from "../providers/app-provider";
import { useWorkstation } from "../providers/workstation-provider";
import { useKioskPrinters } from "../hooks/use-kiosk-printers";
import { RECEIPT_ROLE } from "../lib/printer-utils";
import {
  getManualUrl,
  normalizeWorkstationUrl,
  setManualUrl,
} from "../services/workstation/base-url-resolver";

type TestState = "idle" | "loading" | "success" | "error";

function ServerIcon() {
  return (
    <Svg width={24} height={24} viewBox="0 0 24 24" fill="none" stroke="#6b7280" strokeWidth={1.5}>
      <Path
        strokeLinecap="round"
        strokeLinejoin="round"
        d="M5.25 14.25h13.5m-13.5 0a3 3 0 01-3-3m3 3a3 3 0 100 6h13.5a3 3 0 100-6m-16.5-3a3 3 0 013-3h13.5a3 3 0 013 3m-19.5 0a4.5 4.5 0 01.9-2.7L5.737 5.1a3.375 3.375 0 012.7-1.35h7.126c1.062 0 2.062.5 2.7 1.35l2.587 3.45a4.5 4.5 0 01.9 2.7m0 0a3 3 0 01-3 3m0 3h.008v.008h-.008v-.008zm0-6h.008v.008h-.008v-.008zm-3 6h.008v.008h-.008v-.008zm0-6h.008v.008h-.008v-.008z"
      />
    </Svg>
  );
}

export function WorkstationSettingsCard() {
  const { t } = useTranslation();
  const { workstation, socketConnected, usingWorkstation, lanFallbackEnabled, setLanFallback } =
    useWorkstation();
  const { printers, hasReceiptPrinter, loaded: printersLoaded } = useKioskPrinters();

  const [manualInput, setManualInput] = useState("");
  const [manualStored, setManualStored] = useState<string | null>(null);
  const [testState, setTestState] = useState<TestState>("idle");
  const [testError, setTestError] = useState("");

  // Load manual URL on mount.
  useEffect(() => {
    (async () => {
      const v = await getManualUrl();
      setManualStored(v);
      if (v) setManualInput(v);
    })();
  }, []);

  const trimmedInput = manualInput.trim();
  const inputChanged = trimmedInput !== (manualStored ?? "");

  const handleSaveManual = useCallback(async () => {
    const next = trimmedInput || null;
    await setManualUrl(next);
    setManualStored(next);
  }, [trimmedInput]);

  const handleClearManual = useCallback(async () => {
    await setManualUrl(null);
    setManualStored(null);
    setManualInput("");
  }, []);

  const handleTest = useCallback(async () => {
    const target = normalizeWorkstationUrl(trimmedInput) ?? workstation?.proxyUrl;
    if (!target) return;
    setTestState("loading");
    setTestError("");
    try {
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 3_000);
      // eslint-disable-next-line no-restricted-globals -- probing an operator-typed LAN address for reachability: no auth, no locale, and it must fail fast rather than take the wrapper's retry path
      const res = await fetch(`${target}/api/lan/health`, { signal: controller.signal });
      clearTimeout(timer);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      setTestState("success");
    } catch (err) {
      setTestState("error");
      setTestError(err instanceof Error ? err.message : String(err));
    }
  }, [trimmedInput, workstation?.proxyUrl]);

  // Status label. `usingWorkstation` is checked FIRST — even when the standby
  // toggle is off, a saved manual URL or the build-time default still serves as
  // a Cloud-outage fallback (resolveWorkstationUrl ignores the toggle). If a
  // real failover is in progress the card must say so, matching the app-wide
  // banner's "Cloud down — using LAN"; showing "Off" then would flatly
  // contradict it. See issue #44 review findings #8/#9.
  let statusLabel: string;
  let statusTone: "ok" | "muted" | "warning" = "muted";
  if (usingWorkstation) {
    // Routing to LAN means Cloud is currently failing — that's worth flagging.
    statusLabel = t("settings.workstation_status_active");
    statusTone = "warning";
  } else if (!lanFallbackEnabled) {
    statusLabel = t("settings.workstation_status_off");
    statusTone = "muted";
  } else if (workstation) {
    statusLabel = `${t("settings.workstation_status_standby")} — ${workstation.name}`;
    statusTone = "ok";
  } else if (manualStored) {
    statusLabel = `${t("settings.workstation_status_standby")} — ${manualStored}`;
    statusTone = "ok";
  } else {
    statusLabel = t("settings.workstation_status_not_found");
    statusTone = "warning";
  }

  return (
    <View className="bg-white rounded-2xl p-4 gap-4 shadow-sm">
      <View className="flex-row items-center gap-3">
        <View className="w-10 h-10 rounded-xl bg-gray-100 items-center justify-center">
          <ServerIcon />
        </View>
        <View className="flex-1">
          <Text className="font-semibold">{t("settings.workstation")}</Text>
          {/* Only the LAN address is shown. The Cloud origin comes from
              EXPO_PUBLIC_API_URL at build time and is not operator-tunable, so
              printing it here would just be noise on a config screen. */}
          <Text className="text-xs text-muted-foreground">
            {workstation?.proxyUrl ?? manualStored ?? "—"}
          </Text>
        </View>
        <View
          className={`px-2 py-1 rounded-full ${
            statusTone === "ok"
              ? "bg-emerald-50"
              : statusTone === "warning"
                ? "bg-amber-50"
                : "bg-gray-100"
          }`}
        >
          <Text
            className={`text-xs font-medium ${
              statusTone === "ok"
                ? "text-emerald-600"
                : statusTone === "warning"
                  ? "text-amber-700"
                  : "text-gray-500"
            }`}
          >
            {statusLabel}
          </Text>
        </View>
      </View>

      {workstation && (
        <View className="gap-0.5">
          <Text className="text-xs text-muted-foreground">
            {`v${workstation.version} · WS ${socketConnected ? "✓" : "✗"} · branch ${workstation.branchId}`}
          </Text>
        </View>
      )}

      <View className="h-px bg-gray-100" />

      {/* Workstation opt-in. Off by default (Cloud is the source of truth), but
          this switch does double duty: it starts mDNS discovery, which the kiosk
          needs BOTH to fail over on a Cloud outage AND to find the printer —
          printing is always LAN-only. A kiosk that prints must turn this on (or
          set a manual URL below). The label/hint say so; see issue #44 finding #1. */}
      <View className="flex-row items-start gap-3">
        <View className="flex-1 gap-0.5">
          <Text className="text-sm font-medium text-gray-700">
            {t("settings.workstation_lan_fallback")}
          </Text>
          <Text className="text-xs text-muted-foreground">
            {t("settings.workstation_lan_fallback_hint")}
          </Text>
        </View>
        <Switch
          value={lanFallbackEnabled}
          onValueChange={(next) => {
            void setLanFallback(next);
          }}
        />
      </View>

      <View className="h-px bg-gray-100" />

      {/* Manual URL */}
      <View className="gap-1.5">
        <Text className="text-sm font-medium text-gray-700">
          {t("settings.workstation_manual_url")}
        </Text>
        <Input
          value={manualInput}
          onChangeText={setManualInput}
          placeholder={t("settings.workstation_manual_url_placeholder")}
          keyboardType="default"
          autoCorrect={false}
          autoCapitalize="none"
          className="font-mono"
        />
      </View>

      <View className="flex-row gap-2">
        <Button
          onPress={handleSaveManual}
          disabled={!inputChanged}
          variant={inputChanged ? "default" : "outline"}
          className="flex-1"
        >
          <Text>{t("common.save")}</Text>
        </Button>
        {manualStored && (
          <Button onPress={handleClearManual} variant="outline" className="flex-1">
            <Text>{t("settings.workstation_clear_manual")}</Text>
          </Button>
        )}
      </View>

      <View className="h-px bg-gray-100" />

      <Button
        onPress={handleTest}
        disabled={testState === "loading" || (!workstation && !trimmedInput)}
        variant="outline"
        className="w-full"
      >
        {testState === "loading" ? <ActivityIndicator size="small" /> : null}
        <Text>{t("settings.workstation_test")}</Text>
      </Button>

      {testState === "success" && (
        <View className="flex-row items-center gap-2 bg-emerald-50 rounded-xl px-3 py-2.5">
          <Text className="text-emerald-600 font-medium text-sm">
            ✓ {t("settings.workstation_test_success")}
          </Text>
        </View>
      )}
      {testState === "error" && (
        <View className="bg-destructive/10 rounded-xl px-3 py-2.5">
          <Text className="text-destructive font-medium text-sm">
            {t("settings.workstation_test_fail")}
          </Text>
          {testError ? <Text className="text-destructive text-xs mt-0.5">{testError}</Text> : null}
        </View>
      )}

      <View className="h-px bg-gray-100" />

      {/* Printer config, read from Cloud (issue #44 Phase B). Cloud owns the
          config; the workstation still pushes the bytes. Surfacing it here lets
          an operator confirm a receipt printer exists — and see the config gap
          where they'd fix it (admin), not on the customer's success screen. */}
      <View className="gap-1.5">
        <Text className="text-sm font-medium text-gray-700">
          {t("settings.printers")}
        </Text>
        {printers.length > 0 ? (
          printers.map((p) => (
            <View key={p.id} className="flex-row items-center justify-between gap-2">
              <Text className="text-sm text-gray-700" numberOfLines={1}>
                {p.name}
                {p.roles?.includes(RECEIPT_ROLE) ? "  🧾" : ""}
              </Text>
              <Text className="text-xs text-muted-foreground" numberOfLines={1}>
                {p.address ?? p.connection_type}
              </Text>
            </View>
          ))
        ) : (
          <Text className="text-xs text-muted-foreground">
            {printersLoaded ? t("settings.printers_none") : "…"}
          </Text>
        )}
        {printersLoaded && !hasReceiptPrinter && (
          <View className="bg-amber-50 rounded-xl px-3 py-2 mt-0.5">
            <Text className="text-amber-800 text-xs font-medium">
              ⚠️ {t("settings.printers_no_receipt")}
            </Text>
          </View>
        )}
      </View>
    </View>
  );
}
