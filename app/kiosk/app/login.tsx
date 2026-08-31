import { useCallback, useRef, useState } from "react";
import {
  KeyboardAvoidingView,
  Platform,
  Pressable,
  View,
} from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { router } from "expo-router";
import { useLocale } from "../src/providers/app-provider";
import { useAuth } from "../src/providers/auth-provider";
import { ApiError, DeviceTypeMismatchError } from "../src/lib/api";
import type { LocaleCode } from "../src/i18n";
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
  Input,
  Text,
} from "@godxjp/ui-native";

// Secret gesture into Settings, mirroring /advertise. Settings must be
// reachable BEFORE pairing — a kiosk that can't reach its backend can't pair,
// and the fix (workstation URL, LAN standby, terminal host) lives in Settings.
// Kept as a hidden 5-tap rather than a visible gear so a customer poking at an
// unpaired terminal can't wander in; the passcode gate inside is the real lock.
const SECRET_TAP_COUNT = 5;
const SECRET_TAP_WINDOW_MS = 3000;

export default function LoginScreen() {
  const { t, locale, setLocale, locales } = useLocale();
  const { pair } = useAuth();
  const localeKeys = Object.keys(locales) as LocaleCode[];

  const [code, setCode] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const tapCount = useRef(0);
  const tapTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const handleSecretTap = useCallback(() => {
    tapCount.current += 1;
    if (tapTimer.current) clearTimeout(tapTimer.current);
    tapTimer.current = setTimeout(() => {
      tapCount.current = 0;
    }, SECRET_TAP_WINDOW_MS);

    if (tapCount.current >= SECRET_TAP_COUNT) {
      tapCount.current = 0;
      if (tapTimer.current) clearTimeout(tapTimer.current);
      router.push("/settings");
    }
  }, []);

  const handlePair = async () => {
    const trimmed = code.trim().toUpperCase();
    setError("");

    if (trimmed.length !== 6) {
      setError(t("auth.pairing_failed"));
      return;
    }

    setLoading(true);
    try {
      await pair(trimmed);
      // Let the root guard require passcode setup after the first successful
      // pairing; already-hardened kiosks continue to the idle screen.
      router.replace("/");
    } catch (e) {
      if (e instanceof DeviceTypeMismatchError) {
        setError(`${t("auth.wrong_device_type")} (${e.actualType})`);
      } else if (e instanceof ApiError) {
        const body = e.body as { errors?: Record<string, string[]> };
        const msg = body.errors?.pairing_code?.[0] ?? e.message;
        setError(msg);
      } else {
        setError(t("auth.pairing_failed"));
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <SafeAreaView className="flex-1 bg-background">
      <KeyboardAvoidingView
        className="flex-1"
        behavior={Platform.OS === "ios" ? "padding" : "height"}
      >
        <View className="flex-1 justify-center px-6">
          {/* Logo + Title. The logo doubles as the hidden Settings gesture —
              5 taps within 3s. Same trick as /advertise, same reason: staff
              need a way in, customers must not stumble into one. */}
          <View className="items-center gap-1 mb-8">
            <Pressable
              onPress={handleSecretTap}
              className="h-16 w-16 rounded-2xl bg-primary items-center justify-center mb-3"
            >
              <Text className="text-2xl font-bold text-primary-foreground">
                TF
              </Text>
            </Pressable>
            <Text className="text-2xl font-bold">{t("auth.welcome")}</Text>
            <Text variant="muted">{t("auth.welcome_sub")}</Text>
          </View>

          {/* Pairing Card */}
          <Card className="w-full max-w-sm self-center">
            <CardHeader>
              <CardTitle>{t("auth.pairing_code")}</CardTitle>
              <CardDescription>TempoFast TMS</CardDescription>
            </CardHeader>

            <CardContent className="gap-4">
              {error ? (
                <View className="bg-destructive/10 rounded-md px-3 py-2">
                  <Text className="text-sm text-destructive">{error}</Text>
                </View>
              ) : null}

              <View className="gap-1.5">
                <Input
                  placeholder={t("auth.pairing_placeholder")}
                  value={code}
                  onChangeText={(text) => setCode(text.toUpperCase())}
                  autoCapitalize="characters"
                  autoCorrect={false}
                  maxLength={6}
                  className="text-center font-mono text-2xl tracking-[0.3em] h-14"
                />
              </View>

              <Text className="text-xs text-muted-foreground text-center">
                {t("auth.pairing_hint")}
              </Text>
            </CardContent>

            <CardFooter className="flex-col gap-3">
              <Button
                className="w-full"
                onPress={handlePair}
                disabled={loading || code.trim().length !== 6}
              >
                <Text>
                  {loading ? t("common.loading") : t("auth.login")}
                </Text>
              </Button>
            </CardFooter>
          </Card>

          {/* Locale Switcher */}
          <View className="flex-row gap-2 justify-center mt-8">
            {localeKeys.map((lc) => (
              <Pressable
                key={lc}
                onPress={() => setLocale(lc)}
                className="px-3 py-1.5"
              >
                <Text
                  className={
                    locale === lc
                      ? "text-sm font-medium text-primary"
                      : "text-sm text-muted-foreground"
                  }
                >
                  {locales[lc]}
                </Text>
              </Pressable>
            ))}
          </View>
        </View>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}
