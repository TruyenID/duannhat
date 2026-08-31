import { useState } from "react";
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
import { ApiError } from "../src/lib/api";
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

export default function LoginScreen() {
  const { t, locale, setLocale, locales } = useLocale();
  const { pair } = useAuth();
  const localeKeys = Object.keys(locales) as LocaleCode[];

  const [code, setCode] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

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
      router.replace("/advertise");
    } catch (e) {
      if (e instanceof ApiError) {
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
          {/* Logo + Title */}
          <View className="items-center gap-1 mb-8">
            <View className="h-16 w-16 rounded-2xl bg-primary items-center justify-center mb-3">
              <Text className="text-2xl font-bold text-primary-foreground">
                TF
              </Text>
            </View>
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
