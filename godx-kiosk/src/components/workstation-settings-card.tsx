import { useCallback, useEffect, useState } from "react";
import { ActivityIndicator, View } from "react-native";
import Svg, { Path } from "react-native-svg";
import { Button, Input, Text } from "@godxjp/ui-native";

import { useTranslation } from "../providers/app-provider";
import { useWorkstation } from "../providers/workstation-provider";
import {
  CLOUD_URL,
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
  const { workstation, socketConnected, usingWorkstation } = useWorkstation();

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
      const res = await fetch(`${target}/api/lan/health`, { signal: controller.signal });
      clearTimeout(timer);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      setTestState("success");
    } catch (err) {
      setTestState("error");
      setTestError(err instanceof Error ? err.message : String(err));
    }
  }, [trimmedInput, workstation?.proxyUrl]);

  // Status label
  let statusLabel = t("settings.workstation_status_searching");
  let statusTone: "ok" | "muted" | "warning" = "muted";
  if (workstation && usingWorkstation) {
    statusLabel = `${t("settings.workstation_status_connected")} — ${workstation.name}`;
    statusTone = "ok";
  } else if (workstation && !usingWorkstation) {
    statusLabel = t("workstation.banner.unreachable");
    statusTone = "warning";
  } else if (manualStored) {
    statusLabel = manualStored;
    statusTone = "muted";
  } else {
    statusLabel = t("settings.workstation_status_not_found");
    statusTone = "muted";
  }

  return (
    <View className="bg-white rounded-2xl p-4 gap-4 shadow-sm">
      <View className="flex-row items-center gap-3">
        <View className="w-10 h-10 rounded-xl bg-gray-100 items-center justify-center">
          <ServerIcon />
        </View>
        <View className="flex-1">
          <Text className="font-semibold">{t("settings.workstation")}</Text>
          <Text className="text-xs text-muted-foreground">
            {usingWorkstation ? `LAN: ${workstation?.proxyUrl}` : `Cloud: ${CLOUD_URL}`}
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
    </View>
  );
}
