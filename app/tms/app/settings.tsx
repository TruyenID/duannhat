import { useCallback, useEffect, useState } from "react";
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  View,
} from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import Svg, { Path } from "react-native-svg";
import { router } from "expo-router";
import { useTranslation } from "../src/providers/app-provider";
import { usePrinterConfig } from "../src/hooks/use-printer-config";
import { testPrinterConnection } from "../src/lib/printer";
import { Button, Input, Text } from "@godxjp/ui-native";

type TestState = "idle" | "loading" | "success" | "error";

function PrinterIcon() {
  return (
    <Svg width={24} height={24} viewBox="0 0 24 24" fill="none" stroke="#6b7280" strokeWidth={1.5}>
      <Path strokeLinecap="round" strokeLinejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m10.5 0a48.536 48.536 0 00-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5zm-3 0h.008v.008H15V10.5z" />
    </Svg>
  );
}

function ChevronLeftIcon() {
  return (
    <Svg width={20} height={20} viewBox="0 0 24 24" fill="none" stroke="#111827" strokeWidth={2}>
      <Path strokeLinecap="round" strokeLinejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
    </Svg>
  );
}

export default function SettingsScreen() {
  const { t } = useTranslation();
  const { printerIp, isLoading, savePrinterIp } = usePrinterConfig();

  const [inputIp, setInputIp] = useState("");
  const [saveError, setSaveError] = useState("");
  const [saved, setSaved] = useState(false);
  const [testState, setTestState] = useState<TestState>("idle");
  const [testError, setTestError] = useState("");

  // Sync input khi load xong từ storage
  useEffect(() => {
    if (!isLoading) {
      setInputIp(printerIp);
      setSaved(!!printerIp);
    }
  }, [isLoading, printerIp]);

  const handleSave = useCallback(async () => {
    setSaveError("");
    setSaved(false);
    try {
      await savePrinterIp(inputIp);
      setSaved(true);
      setTestState("idle");
      setTestError("");
    } catch {
      setSaveError(t("settings.invalid_ip"));
    }
  }, [inputIp, savePrinterIp, t]);

  const handleTest = useCallback(async () => {
    if (!printerIp) {
      setTestError(t("settings.test_no_ip"));
      setTestState("error");
      return;
    }
    setTestState("loading");
    setTestError("");
    try {
      await testPrinterConnection(printerIp);
      setTestState("success");
      setTimeout(() => setTestState("idle"), 3000);
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : String(e);
      setTestError(msg);
      setTestState("error");
    }
  }, [printerIp, t]);

  const ipChanged = inputIp.trim() !== printerIp;

  return (
    <SafeAreaView className="flex-1 bg-gray-50">
      <KeyboardAvoidingView
        className="flex-1"
        behavior={Platform.OS === "ios" ? "padding" : "height"}
      >
        {/* Header */}
        <View className="px-4 py-3 flex-row items-center gap-3 bg-white border-b border-gray-100">
          <Button variant="ghost" size="icon" onPress={() => router.back()}>
            <ChevronLeftIcon />
          </Button>
          <Text className="text-lg font-bold">{t("settings.title")}</Text>
        </View>

        <ScrollView className="flex-1" contentContainerClassName="px-4 py-6 gap-6">

          {/* Section: Thiết bị ngoại vi */}
          <View className="gap-3">
            <Text className="text-xs font-semibold text-gray-400 uppercase tracking-wider px-1">
              {t("settings.peripherals")}
            </Text>

            {/* Printer Card */}
            <View className="bg-white rounded-2xl p-4 gap-4 shadow-sm">

              {/* Printer header */}
              <View className="flex-row items-center gap-3">
                <View className="w-10 h-10 rounded-xl bg-gray-100 items-center justify-center">
                  <PrinterIcon />
                </View>
                <View className="flex-1">
                  <Text className="font-semibold">{t("settings.printer_label")}</Text>
                  <Text className="text-xs text-muted-foreground">{t("settings.printer_model")}</Text>
                </View>
                {/* Status badge */}
                <View className={`px-2 py-1 rounded-full ${saved && !ipChanged ? "bg-emerald-50" : "bg-gray-100"}`}>
                  <Text className={`text-xs font-medium ${saved && !ipChanged ? "text-emerald-600" : "text-gray-400"}`}>
                    {saved && !ipChanged
                      ? t("settings.printer_saved")
                      : t("settings.printer_not_configured")}
                  </Text>
                </View>
              </View>

              {/* Divider */}
              <View className="h-px bg-gray-100" />

              {/* IP input */}
              <View className="gap-1.5">
                <Text className="text-sm font-medium text-gray-700">
                  {t("settings.printer_ip")}
                </Text>
                <Input
                  value={inputIp}
                  onChangeText={text => {
                    setInputIp(text);
                    setSaveError("");
                    setSaved(false);
                  }}
                  placeholder={t("settings.printer_ip_placeholder")}
                  keyboardType="default"
                  autoCorrect={false}
                  autoCapitalize="none"
                  className="font-mono"
                />
                {saveError ? (
                  <Text className="text-xs text-destructive">{saveError}</Text>
                ) : null}
              </View>

              {/* Save button */}
              <Button
                onPress={handleSave}
                disabled={!inputIp.trim() || isLoading}
                variant={saved && !ipChanged ? "outline" : "default"}
                className="w-full"
              >
                <Text>{t("common.save")}</Text>
              </Button>

              {/* Divider */}
              <View className="h-px bg-gray-100" />

              {/* Test connection button */}
              <Button
                onPress={handleTest}
                disabled={testState === "loading" || !printerIp}
                variant="outline"
                className="w-full"
              >
                {testState === "loading" ? (
                  <ActivityIndicator size="small" />
                ) : null}
                <Text>
                  {testState === "loading"
                    ? t("settings.testing")
                    : t("settings.test_connection")}
                </Text>
              </Button>

              {/* Test result */}
              {testState === "success" && (
                <View className="flex-row items-center gap-2 bg-emerald-50 rounded-xl px-3 py-2.5">
                  <Text className="text-emerald-600 font-medium text-sm">
                    ✓  {t("settings.test_success")}
                  </Text>
                </View>
              )}
              {testState === "error" && (
                <View className="bg-destructive/10 rounded-xl px-3 py-2.5">
                  <Text className="text-destructive font-medium text-sm">
                    ✗  {t("settings.test_failed")}
                  </Text>
                  {testError ? (
                    <Text className="text-destructive text-xs mt-0.5">{testError}</Text>
                  ) : null}
                </View>
              )}
            </View>
          </View>

        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}
