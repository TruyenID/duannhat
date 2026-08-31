import { ScrollView, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { useLocale } from "../src/providers/app-provider";
import type { LocaleCode } from "../src/i18n";
import {
  Avatar,
  AvatarFallback,
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
  Input,
  Skeleton,
  Text,
} from "@godxjp/ui-native";

function Divider() {
  return <View className="h-px w-full bg-border" />;
}

export default function HomeScreen() {
  const { t, locale, setLocale, locales } = useLocale();
  const localeKeys = Object.keys(locales) as LocaleCode[];

  return (
    <SafeAreaView className="flex-1 bg-background">
    <ScrollView className="flex-1">
      <View className="p-6 gap-8 pb-12">
        {/* Header */}
        <View className="items-center gap-2 pt-8">
          <Text variant="h3">{t("tms.title")}</Text>
          <Text variant="muted">TempoFast Table Management Terminal</Text>
        </View>

        {/* Locale Switcher */}
        <View className="flex-row gap-2 justify-center">
          {localeKeys.map((code) => (
            <Button
              key={code}
              variant={locale === code ? "default" : "outline"}
              size="sm"
              onPress={() => setLocale(code)}
            >
              <Text>{locales[code]}</Text>
            </Button>
          ))}
        </View>

        <Divider />

        {/* Buttons */}
        <View className="gap-3">
          <Text variant="large">Button</Text>
          <View className="flex-row flex-wrap gap-2">
            <Button>
              <Text>{t("action.seat_guest")}</Text>
            </Button>
            <Button variant="outline">
              <Text>{t("action.reserve")}</Text>
            </Button>
            <Button variant="secondary">
              <Text>{t("action.transfer")}</Text>
            </Button>
            <Button variant="ghost">
              <Text>{t("action.merge")}</Text>
            </Button>
          </View>
          <View className="flex-row flex-wrap gap-2">
            <Button variant="destructive">
              <Text>{t("action.block")}</Text>
            </Button>
            <Button size="sm">
              <Text>Small</Text>
            </Button>
            <Button size="default">
              <Text>Default</Text>
            </Button>
            <Button size="lg">
              <Text>Large</Text>
            </Button>
            <Button disabled>
              <Text>Disabled</Text>
            </Button>
          </View>
        </View>

        <Divider />

        {/* Badges */}
        <View className="gap-3">
          <Text variant="large">Badge</Text>
          <View className="flex-row flex-wrap gap-2">
            <Badge className="bg-table-available border-transparent">
              <Text className="text-xs font-medium text-white">
                {t("table_status.available")}
              </Text>
            </Badge>
            <Badge className="bg-table-occupied border-transparent">
              <Text className="text-xs font-medium text-white">
                {t("table_status.occupied")}
              </Text>
            </Badge>
            <Badge className="bg-table-reserved border-transparent">
              <Text className="text-xs font-medium text-white">
                {t("table_status.reserved")}
              </Text>
            </Badge>
            <Badge className="bg-table-cleaning border-transparent">
              <Text className="text-xs font-medium text-white">
                {t("table_status.cleaning")}
              </Text>
            </Badge>
            <Badge className="bg-table-blocked border-transparent">
              <Text className="text-xs font-medium text-white">
                {t("table_status.blocked")}
              </Text>
            </Badge>
          </View>
          <View className="flex-row flex-wrap gap-2">
            <Badge>
              <Text>{t("common.active")}</Text>
            </Badge>
            <Badge variant="secondary">
              <Text>{t("common.inactive")}</Text>
            </Badge>
            <Badge variant="destructive">
              <Text>{t("common.confirm")}</Text>
            </Badge>
            <Badge variant="outline">
              <Text>{t("common.status")}</Text>
            </Badge>
          </View>
        </View>

        <Divider />

        {/* Card */}
        <View className="gap-3">
          <Text variant="large">Card</Text>
          <Card>
            <CardHeader>
              <CardTitle>{t("tms.zone")} A</CardTitle>
              <CardDescription>
                4 {t("tms.table")} · 16 {t("tms.seats")}
              </CardDescription>
            </CardHeader>
            <CardContent>
              <View className="flex-row gap-2">
                <Badge className="bg-table-available border-transparent">
                  <Text className="text-xs font-medium text-white">2 {t("table_status.available")}</Text>
                </Badge>
                <Badge className="bg-table-occupied border-transparent">
                  <Text className="text-xs font-medium text-white">1 {t("table_status.occupied")}</Text>
                </Badge>
                <Badge className="bg-table-reserved border-transparent">
                  <Text className="text-xs font-medium text-white">1 {t("table_status.reserved")}</Text>
                </Badge>
              </View>
            </CardContent>
            <CardFooter className="gap-2">
              <Button size="sm" variant="outline">
                <Text>{t("tms.floor_map")}</Text>
              </Button>
              <Button size="sm" variant="outline">
                <Text>{t("tms.table_list")}</Text>
              </Button>
            </CardFooter>
          </Card>
        </View>

        <Divider />

        {/* Input */}
        <View className="gap-3">
          <Text variant="large">Input</Text>
          <Input placeholder={t("common.search") + "..."} />
          <Input placeholder="Disabled" editable={false} />
        </View>

        <Divider />

        {/* Avatar */}
        <View className="gap-3">
          <Text variant="large">Avatar</Text>
          <View className="flex-row gap-3">
            <Avatar alt="TempoFast">
              <AvatarFallback>
                <Text className="text-sm font-medium">TF</Text>
              </AvatarFallback>
            </Avatar>
            <Avatar alt="Zone A">
              <AvatarFallback className="bg-primary">
                <Text className="text-sm font-medium text-primary-foreground">A1</Text>
              </AvatarFallback>
            </Avatar>
            <Avatar alt="Zone B">
              <AvatarFallback className="bg-table-available">
                <Text className="text-sm font-medium text-white">B2</Text>
              </AvatarFallback>
            </Avatar>
          </View>
        </View>

        <Divider />

        {/* Skeleton */}
        <View className="gap-3">
          <Text variant="large">Skeleton</Text>
          <View className="gap-2">
            <Skeleton className="h-4 w-3/4" />
            <Skeleton className="h-4 w-1/2" />
            <Skeleton className="h-10 w-full" />
          </View>
        </View>
      </View>
    </ScrollView>
    </SafeAreaView>
  );
}
