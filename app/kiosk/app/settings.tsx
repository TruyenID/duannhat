import { useCallback, useEffect, useRef, useState } from "react";
import {
  ActivityIndicator,
  Keyboard,
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  TouchableWithoutFeedback,
  View,
} from "react-native";
import { PasscodeScreen } from "../src/components/passcode-screen";
import { usePasscode } from "../src/hooks/use-passcode";
import { SafeAreaView } from "react-native-safe-area-context";
import Svg, { Path } from "react-native-svg";
import { router } from "expo-router";
import { useTranslation } from "../src/providers/app-provider";
import { useAuth } from "../src/providers/auth-provider";
import { useTerminal } from "../src/providers/terminal-provider";
import { useTerminalConfig } from "../src/hooks/use-terminal-config";
import { useIdleTimeout } from "../src/hooks/use-idle-timeout";
import { Button, Input, Text } from "@godxjp/ui-native";
import { WorkstationSettingsCard } from "../src/components/workstation-settings-card";
import { resolveSettingsPasscodeGate } from "../src/lib/passcode-flow";

type TestState = "idle" | "loading" | "success" | "error";

function TerminalIcon() {
  return (
    <Svg width={24} height={24} viewBox="0 0 24 24" fill="none" stroke="#6b7280" strokeWidth={1.5}>
      <Path strokeLinecap="round" strokeLinejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" />
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
  const { logout, isAuthenticated, isLoading: authLoading } = useAuth();

  const [unlocked, setUnlocked] = useState(false);
  const [passcodeError, setPasscodeError] = useState("");
  const [passcodeErrorKey, setPasscodeErrorKey] = useState(0);
  const { isLoading: passcodeLoading, isConfigured, verify, setPasscode, changePasscode } = usePasscode();

  // First-boot set-passcode flow. There is no default credential, so a kiosk
  // with no configured passcode must have one set before Settings is armed.
  type SetupStep = "new" | "confirm";
  const [setupStep, setSetupStep] = useState<SetupStep>("new");
  const [setupNewValue, setSetupNewValue] = useState("");
  const [setupError, setSetupError] = useState("");
  const [setupErrorKey, setSetupErrorKey] = useState(0);

  const handleSetupComplete = useCallback(
    async (code: string) => {
      if (setupStep === "new") {
        setSetupNewValue(code);
        setSetupError("");
        setSetupStep("confirm");
      } else {
        if (code === setupNewValue) {
          const ok = await setPasscode(setupNewValue);
          if (ok) {
            setUnlocked(true);
            setSetupStep("new");
            setSetupNewValue("");
            setSetupError("");
          } else {
            setSetupError(t("passcode.wrong"));
            setSetupErrorKey((k) => k + 1);
            setSetupStep("new");
            setSetupNewValue("");
          }
        } else {
          setSetupError(t("passcode.mismatch"));
          setSetupErrorKey((k) => k + 1);
          setSetupStep("new");
          setSetupNewValue("");
        }
      }
    },
    [setupStep, setupNewValue, setPasscode, t],
  );

  type ChangeStep = "idle" | "current" | "new" | "confirm";
  const [changeStep, setChangeStep] = useState<ChangeStep>("idle");
  const [verifiedCurrentCode, setVerifiedCurrentCode] = useState("");
  const [newPasscodeValue, setNewPasscodeValue] = useState("");
  const [changeError, setChangeError] = useState("");
  const [changeErrorKey, setChangeErrorKey] = useState(0);
  const [changeSuccess, setChangeSuccess] = useState(false);

  const handlePasscodeComplete = useCallback(
    (code: string) => {
      if (verify(code)) {
        setUnlocked(true);
        setPasscodeError("");
      } else {
        setPasscodeError(t("passcode.wrong"));
        setPasscodeErrorKey((k) => k + 1);
      }
    },
    [verify, t],
  );

  const handleChangePasscode = useCallback(
    async (code: string) => {
      if (changeStep === "current") {
        if (verify(code)) {
          setVerifiedCurrentCode(code);
          setChangeError("");
          setChangeStep("new");
        } else {
          setChangeError(t("passcode.wrong"));
          setChangeErrorKey((k) => k + 1);
        }
      } else if (changeStep === "new") {
        setNewPasscodeValue(code);
        setChangeError("");
        setChangeStep("confirm");
      } else if (changeStep === "confirm") {
        if (code === newPasscodeValue) {
          await changePasscode(verifiedCurrentCode, newPasscodeValue);
          setChangeStep("idle");
          setVerifiedCurrentCode("");
          setNewPasscodeValue("");
          setChangeError("");
          setChangeSuccess(true);
          setTimeout(() => setChangeSuccess(false), 3000);
        } else {
          setChangeError(t("passcode.mismatch"));
          setChangeErrorKey((k) => k + 1);
          setChangeStep("new");
          setNewPasscodeValue("");
        }
      }
    },
    [changeStep, verify, changePasscode, verifiedCurrentCode, newPasscodeValue, t],
  );

  const { config: terminalConfig, isLoading: terminalLoading, saveConfig: saveTerminalConfig } = useTerminalConfig();
  const { status: terminalStatus, error: terminalError, testConnection, reset: resetTerminal, printRetry, forceReset, hasTerminal } = useTerminal();
  const [terminalHost, setTerminalHost] = useState("");
  const [terminalPort, setTerminalPort] = useState("");
  const [terminalSaveError, setTerminalSaveError] = useState("");
  const [terminalSaved, setTerminalSaved] = useState(false);
  const [terminalTestState, setTerminalTestState] = useState<TestState>("idle");
  const [terminalTestError, setTerminalTestError] = useState("");
  const [recoverState, setRecoverState] = useState<TestState>("idle");
  const [recoverError, setRecoverError] = useState("");
  const [forceResetDone, setForceResetDone] = useState(false);

  // Idle timeout (seconds) — how long a regular screen waits before returning
  // to /advertise. Was hard-coded; now operator-configurable.
  const { seconds: idleSeconds, isLoading: idleLoading, save: saveIdle } = useIdleTimeout();
  const [idleInput, setIdleInput] = useState("");
  const [idleSaved, setIdleSaved] = useState(false);
  const [idleSaveError, setIdleSaveError] = useState("");

  const terminalTestTimerRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const recoverTimerRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const forceResetTimerRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

  useEffect(() => {
    return () => {
      if (terminalTestTimerRef.current) clearTimeout(terminalTestTimerRef.current);
      if (recoverTimerRef.current) clearTimeout(recoverTimerRef.current);
      if (forceResetTimerRef.current) clearTimeout(forceResetTimerRef.current);
    };
  }, []);

  useEffect(() => {
    if (!terminalLoading && terminalConfig) {
      setTerminalHost(terminalConfig.host);
      setTerminalPort(String(terminalConfig.port));
      setTerminalSaved(true);
    }
  }, [terminalLoading, terminalConfig]);

  useEffect(() => {
    if (!idleLoading) {
      setIdleInput(String(idleSeconds));
    }
  }, [idleLoading, idleSeconds]);

  const handleIdleSave = useCallback(async () => {
    setIdleSaveError("");
    setIdleSaved(false);
    const n = Number(idleInput);
    if (!Number.isFinite(n) || n <= 0) {
      setIdleSaveError(t("settings.idle_invalid"));
      return;
    }
    try {
      await saveIdle(n);
      setIdleSaved(true);
    } catch {
      setIdleSaveError(t("settings.idle_invalid"));
    }
  }, [idleInput, saveIdle, t]);

  const handleTerminalSave = useCallback(async () => {
    setTerminalSaveError("");
    setTerminalSaved(false);
    try {
      await saveTerminalConfig(terminalHost, Number(terminalPort));
      setTerminalSaved(true);
      setTerminalTestState("idle");
      setTerminalTestError("");
    } catch (e: unknown) {
      const msg = e instanceof Error ? e.message : "";
      if (msg === "invalid_ip") {
        setTerminalSaveError(t("settings.invalid_ip"));
      } else if (msg === "invalid_port") {
        setTerminalSaveError(t("settings.invalid_port"));
      } else {
        setTerminalSaveError(msg);
      }
    }
  }, [terminalHost, terminalPort, saveTerminalConfig, t]);

  const handleTerminalTest = useCallback(() => {
    if (!hasTerminal) {
      setTerminalTestError(t("settings.test_no_ip"));
      setTerminalTestState("error");
      return;
    }
    setTerminalTestState("loading");
    setTerminalTestError("");
    testConnection();
  }, [hasTerminal, testConnection, t]);

  // Watch terminal status for test result
  useEffect(() => {
    if (terminalTestState !== "loading") return;
    if (terminalStatus === 'success') {
      setTerminalTestState("success");
      resetTerminal();
      terminalTestTimerRef.current = setTimeout(() => setTerminalTestState("idle"), 3000);
    } else if (terminalStatus === 'error' && terminalError) {
      setTerminalTestError(terminalError.ErrorEvent?.Message ?? t("settings.test_failed"));
      setTerminalTestState("error");
      resetTerminal();
    }
  }, [terminalStatus, terminalError, terminalTestState, resetTerminal, t]);

  // Send PrintRetry to re-print the last slip (recover a stuck/LP0 terminal).
  const handlePrintRetry = useCallback(() => {
    if (!hasTerminal) {
      setRecoverError(t("settings.test_no_ip"));
      setRecoverState("error");
      return;
    }
    setRecoverState("loading");
    setRecoverError("");
    setForceResetDone(false);
    printRetry();
  }, [hasTerminal, printRetry, t]);

  // Watch terminal status for PrintRetry result (mirrors the test watcher; the
  // two are mutually exclusive via their own loading guards).
  useEffect(() => {
    if (recoverState !== "loading") return;
    if (terminalStatus === 'success') {
      setRecoverState("success");
      resetTerminal();
      recoverTimerRef.current = setTimeout(() => setRecoverState("idle"), 3000);
    } else if (terminalStatus === 'error' && terminalError) {
      setRecoverError(terminalError.ErrorEvent?.Message ?? t("settings.terminal_recover_failed"));
      setRecoverState("error");
      resetTerminal();
    }
  }, [terminalStatus, terminalError, recoverState, resetTerminal, t]);

  // Hard-reset the bridge worker (clears a wedged DeviceBusy). No RESULT event
  // comes back, so just confirm transiently.
  const handleForceReset = useCallback(() => {
    forceReset();
    setRecoverState("idle");
    setRecoverError("");
    setForceResetDone(true);
    forceResetTimerRef.current = setTimeout(() => setForceResetDone(false), 3000);
  }, [forceReset]);

  const terminalHostChanged = terminalHost.trim() !== (terminalConfig?.host ?? "");
  const terminalPortChanged = terminalPort.trim() !== String(terminalConfig?.port ?? "");
  const terminalChanged = terminalHostChanged || terminalPortChanged;

  if (passcodeLoading || authLoading) return null;

  const passcodeGate = resolveSettingsPasscodeGate({
    isAuthenticated,
    isPasscodeConfigured: isConfigured,
    isUnlocked: unlocked,
  });
  const isRecoveryMode = passcodeGate === "recovery";

  // Pairing, not the hidden Settings gesture, arms first-passcode setup.
  if (passcodeGate === "setup") {
    return (
      <PasscodeScreen
        key={setupStep}
        title={setupStep === "new" ? t("passcode.set_title") : t("passcode.confirm_new")}
        error={setupError}
        errorKey={setupErrorKey}
        onComplete={handleSetupComplete}
      />
    );
  }

  if (passcodeGate === "verify") {
    return (
      <PasscodeScreen
        title={t("passcode.title")}
        error={passcodeError}
        errorKey={passcodeErrorKey}
        onComplete={handlePasscodeComplete}
      />
    );
  }

  if (changeStep !== "idle") {
    const changeTitles: Record<ChangeStep, string> = {
      idle: "",
      current: t("passcode.current"),
      new: t("passcode.new"),
      confirm: t("passcode.confirm_new"),
    };
    return (
      <PasscodeScreen
        key={changeStep}
        title={changeTitles[changeStep]}
        error={changeError}
        errorKey={changeErrorKey}
        onComplete={handleChangePasscode}
      />
    );
  }

  return (
    <SafeAreaView className="flex-1 bg-background">
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

        <TouchableWithoutFeedback onPress={Keyboard.dismiss}>
          <ScrollView className="flex-1" contentContainerClassName="px-4 py-6 gap-6">

          {isRecoveryMode && (
            <View className="bg-amber-50 border border-amber-200 rounded-2xl p-4">
              <Text className="text-sm text-amber-900">
                {t("passcode.pair_before_set")}
              </Text>
            </View>
          )}

          {/* Section: Thiết bị ngoại vi */}
          <View className="gap-3">
            <Text className="text-xs font-semibold text-gray-400 uppercase tracking-wider px-1">
              {t("settings.peripherals")}
            </Text>

            {/* Terminal Card */}
            <View className="bg-white rounded-2xl p-4 gap-4 shadow-sm">
              <View className="flex-row items-center gap-3">
                <View className="w-10 h-10 rounded-xl bg-gray-100 items-center justify-center">
                  <TerminalIcon />
                </View>
                <View className="flex-1">
                  <Text className="font-semibold">{t("settings.terminal_label")}</Text>
                  <Text className="text-xs text-muted-foreground">{t("settings.terminal_model")}</Text>
                </View>
                <View className={`px-2 py-1 rounded-full ${terminalSaved && !terminalChanged ? "bg-emerald-50" : "bg-gray-100"}`}>
                  <Text className={`text-xs font-medium ${terminalSaved && !terminalChanged ? "text-emerald-600" : "text-gray-400"}`}>
                    {terminalSaved && !terminalChanged
                      ? t("settings.terminal_saved")
                      : t("settings.terminal_not_configured")}
                  </Text>
                </View>
              </View>

              <View className="h-px bg-gray-100" />

              <View className="gap-1.5">
                <Text className="text-sm font-medium text-gray-700">{t("settings.terminal_ip")}</Text>
                <Input
                  value={terminalHost}
                  onChangeText={text => { setTerminalHost(text); setTerminalSaveError(""); setTerminalSaved(false); }}
                  placeholder={t("settings.terminal_ip_placeholder")}
                  keyboardType="default"
                  autoCorrect={false}
                  autoCapitalize="none"
                  className="font-mono"
                />
              </View>

              <View className="gap-1.5">
                <Text className="text-sm font-medium text-gray-700">{t("settings.terminal_port")}</Text>
                <Input
                  value={terminalPort}
                  onChangeText={text => { setTerminalPort(text); setTerminalSaveError(""); setTerminalSaved(false); }}
                  placeholder={t("settings.terminal_port_placeholder")}
                  keyboardType="numeric"
                  autoCorrect={false}
                  className="font-mono"
                />
                {terminalSaveError ? (
                  <Text className="text-xs text-destructive">{terminalSaveError}</Text>
                ) : null}
              </View>

              <Button
                onPress={handleTerminalSave}
                disabled={!terminalHost.trim() || !terminalPort.trim() || terminalLoading}
                variant={terminalSaved && !terminalChanged ? "outline" : "default"}
                className="w-full"
              >
                <Text>{t("common.save")}</Text>
              </Button>

              <View className="h-px bg-gray-100" />

              <Button
                onPress={handleTerminalTest}
                disabled={terminalTestState === "loading" || !terminalConfig}
                variant="outline"
                className="w-full"
              >
                {terminalTestState === "loading" ? <ActivityIndicator size="small" /> : null}
                <Text>
                  {terminalTestState === "loading"
                    ? t("settings.testing")
                    : t("settings.test_connection")}
                </Text>
              </Button>

              {terminalTestState === "success" && (
                <View className="flex-row items-center gap-2 bg-emerald-50 rounded-xl px-3 py-2.5">
                  <Text className="text-emerald-600 font-medium text-sm">
                    ✓  {t("settings.test_success")}
                  </Text>
                </View>
              )}
              {terminalTestState === "error" && (
                <View className="bg-destructive/10 rounded-xl px-3 py-2.5">
                  <Text className="text-destructive font-medium text-sm">
                    ✗  {t("settings.test_failed")}
                  </Text>
                  {terminalTestError ? (
                    <Text className="text-destructive text-xs mt-0.5">{terminalTestError}</Text>
                  ) : null}
                </View>
              )}

              {/* Recovery: unstick a terminal that approved a payment but is
                  jammed waiting for a slip reprint (LP0), or wedged on DeviceBusy. */}
              <View className="h-px bg-gray-100" />
              <Text className="text-xs font-semibold text-gray-400 uppercase tracking-wider">
                {t("settings.terminal_recover_section")}
              </Text>
              <Text className="text-xs text-muted-foreground">
                {t("settings.terminal_recover_hint")}
              </Text>

              <Button
                onPress={handlePrintRetry}
                disabled={recoverState === "loading" || !terminalConfig}
                variant="outline"
                className="w-full"
              >
                {recoverState === "loading" ? <ActivityIndicator size="small" /> : null}
                <Text>
                  {recoverState === "loading"
                    ? t("settings.terminal_recovering")
                    : t("settings.terminal_print_retry")}
                </Text>
              </Button>

              <Button
                onPress={handleForceReset}
                disabled={!terminalConfig}
                variant="outline"
                className="w-full"
              >
                <Text>{t("settings.terminal_force_reset")}</Text>
              </Button>

              {recoverState === "success" && (
                <View className="flex-row items-center gap-2 bg-emerald-50 rounded-xl px-3 py-2.5">
                  <Text className="text-emerald-600 font-medium text-sm">
                    ✓  {t("settings.terminal_recover_success")}
                  </Text>
                </View>
              )}
              {recoverState === "error" && (
                <View className="bg-destructive/10 rounded-xl px-3 py-2.5">
                  <Text className="text-destructive font-medium text-sm">
                    ✗  {t("settings.terminal_recover_failed")}
                  </Text>
                  {recoverError ? (
                    <Text className="text-destructive text-xs mt-0.5">{recoverError}</Text>
                  ) : null}
                </View>
              )}
              {forceResetDone && (
                <View className="flex-row items-center gap-2 bg-emerald-50 rounded-xl px-3 py-2.5">
                  <Text className="text-emerald-600 font-medium text-sm">
                    ✓  {t("settings.terminal_force_reset_done")}
                  </Text>
                </View>
              )}
            </View>
          </View>

          {/* Section: Security. Recovery mode deliberately exposes only the
              configuration surface needed to make pairing possible. */}
          {!isRecoveryMode && (
            <View className="gap-3">
              <Text className="text-xs font-semibold text-gray-400 uppercase tracking-wider px-1">
                {t("passcode.section")}
              </Text>
              <View className="bg-white rounded-2xl p-4 gap-4 shadow-sm">
                <Button
                  onPress={() => {
                    setChangeStep("current");
                    setChangeError("");
                  }}
                  variant="outline"
                  className="w-full"
                >
                  <Text>{t("passcode.change")}</Text>
                </Button>
                {changeSuccess && (
                  <View className="flex-row items-center gap-2 bg-emerald-50 rounded-xl px-3 py-2.5">
                    <Text className="text-emerald-600 font-medium text-sm">
                      {t("passcode.changed")}
                    </Text>
                  </View>
                )}
              </View>
            </View>
          )}

          {/* Section: Workstation */}
          {/* Idle timeout — return to /advertise after N seconds of inactivity */}
          <View className="gap-3">
            <Text className="text-xs font-semibold text-gray-400 uppercase tracking-wider px-1">
              {t("settings.idle_section")}
            </Text>
            <View className="bg-card rounded-2xl border border-border p-4 gap-3">
              <View className="gap-1.5">
                <Text className="text-sm font-medium text-gray-700">
                  {t("settings.idle_seconds")}
                </Text>
                <Input
                  value={idleInput}
                  onChangeText={(text) => setIdleInput(text.replace(/[^0-9]/g, ""))}
                  keyboardType="numeric"
                  placeholder="60"
                />
                <Text className="text-xs text-muted-foreground">
                  {t("settings.idle_hint")}
                </Text>
                {idleSaveError ? (
                  <Text className="text-xs text-destructive">{idleSaveError}</Text>
                ) : null}
              </View>
              <Button
                onPress={handleIdleSave}
                disabled={!idleInput.trim() || idleLoading}
                variant={idleSaved && idleInput === String(idleSeconds) ? "outline" : "default"}
                className="w-full"
              >
                <Text>
                  {idleSaved && idleInput === String(idleSeconds)
                    ? t("settings.idle_saved")
                    : t("common.save")}
                </Text>
              </Button>
            </View>
          </View>

          {/* Workstation */}
          <View className="gap-3">
            <Text className="text-xs font-semibold text-gray-400 uppercase tracking-wider px-1">
              {t("settings.workstation")}
            </Text>
            <WorkstationSettingsCard />
          </View>

          {/* Logout — only meaningful once the device is paired. Settings is
              reachable BEFORE pairing (that's how ops configures a kiosk whose
              backend it can't reach yet), and on that path there is no session
              to end, so the button would just be a dead red button. */}
          {isAuthenticated && (
            <Button
              variant="outline"
              className="w-full border-destructive"
              onPress={logout}
            >
              <Text className="text-destructive font-semibold">{t("common.logout")}</Text>
            </Button>
          )}

        </ScrollView>
        </TouchableWithoutFeedback>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}
