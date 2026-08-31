"use client";

/**
 * /me/settings/notifications — user preferences page (plan-012 T3.11).
 */

import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Skeleton,
  Spinner,
  Switch,
} from "@godxjp/ui";
import {
  Bell,
  Clock,
  Mail,
  MonitorSmartphone,
  Moon,
  Radio,
  Settings2,
  VolumeX,
} from "lucide-react";
import { Fragment, useEffect, useMemo, useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import {
  useDigestPreference,
  useUpdateDigestPreference,
} from "@/hooks/api/use-notification-digest-preference";
import {
  useNotificationPreferences,
  useSetMasterMute,
  useSetQuietHours,
  useUpsertNotificationPreference,
  useUserNotificationTypes,
} from "@/hooks/api/use-notification-preferences";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
import type {
  DigestCadence,
  DigestPriority,
} from "@/services/notification-digest-preference-service";
import type { PreferenceRow } from "@/services/notification-preference-service";
import type { NotificationChannel } from "@/services/notification-routing-service";

const CHANNELS: NotificationChannel[] = ["in_app", "realtime", "email", "push"];
const CHANNEL_ICONS: Record<NotificationChannel, typeof Bell> = {
  in_app: Bell,
  realtime: Radio,
  email: Mail,
  push: MonitorSmartphone,
};

const COMMON_TIMEZONES = [
  "Asia/Tokyo",
  "Asia/Ho_Chi_Minh",
  "Asia/Singapore",
  "UTC",
  "America/Los_Angeles",
  "America/New_York",
  "Europe/London",
];

function prefKey(type: string, channel: NotificationChannel) {
  return `${type}|${channel}`;
}

function seedMap(prefs: PreferenceRow[]): Map<string, boolean> {
  const map = new Map<string, boolean>();
  prefs.forEach((p) => map.set(prefKey(p.type, p.channel), p.enabled));
  return map;
}

function groupByPrefix(types: string[]): Array<[string, string[]]> {
  const groups: Record<string, string[]> = {};
  types.forEach((t) => {
    const prefix = t.split(".")[0] ?? "other";
    groups[prefix] = groups[prefix] ?? [];
    groups[prefix].push(t);
  });
  return Object.entries(groups)
    .map(([k, v]) => [k, [...v].sort()] as [string, string[]])
    .sort((a, b) => a[0].localeCompare(b[0]));
}

export default function MeNotificationPreferencesPage() {
  const { t, locale } = useTranslation();
  const { data, isLoading, isError } = useNotificationPreferences();
  const { data: types, isLoading: typesLoading } = useUserNotificationTypes();
  const upsertPref = useUpsertNotificationPreference();
  const setMasterMute = useSetMasterMute();
  const setQuietHours = useSetQuietHours();

  const [map, setMap] = useState<Map<string, boolean>>(new Map());
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [tz, setTz] = useState("Asia/Tokyo");
  const [quietDirty, setQuietDirty] = useState(false);

  useEffect(() => {
    if (data) {
      setMap(seedMap(data.preferences));
      setFrom(data.quiet_hours.from ?? "");
      setTo(data.quiet_hours.to ?? "");
      setTz(data.quiet_hours.tz ?? "Asia/Tokyo");
      setQuietDirty(false);
    }
  }, [data]);

  const masterMute = data?.master_mute ?? false;
  const groups = useMemo(() => groupByPrefix(types ?? []), [types]);
  const quietActive = Boolean(data?.quiet_hours.from && data?.quiet_hours.to);

  function cellChecked(type: string, channel: NotificationChannel): boolean {
    const v = map.get(prefKey(type, channel));
    return v === undefined ? true : v;
  }

  function toggleCell(type: string, channel: NotificationChannel, next: boolean) {
    setMap((prev) => new Map(prev).set(prefKey(type, channel), next));
    upsertPref.mutate({ type, channel, enabled: next });
  }

  function handleQuietHoursSave() {
    if (!from || !to) return;
    setQuietHours.mutate({ from, to, tz });
    setQuietDirty(false);
  }

  const optedOutCount = useMemo(() => Array.from(map.values()).filter((v) => !v).length, [map]);

  return (
    <>
      <PageHeader
        title={t("notifications.me.preferences.title")}
        description={t("notifications.me.preferences.subtitle")}
      />

      <PageContent>
        {isError ? (
          <Alert className="mb-4" variant="destructive">
            <AlertDescription>{t("common.error_loading")}</AlertDescription>
          </Alert>
        ) : null}

        <div className="mx-auto max-w-4xl space-y-4">
          {/* Summary strip */}
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <SummaryTile
              icon={<VolumeX className="size-4" />}
              label={t("notifications.me.preferences.master_mute")}
              value={masterMute ? t("common.on") : t("common.off")}
              tone={masterMute ? "warning" : "neutral"}
            />
            <SummaryTile
              icon={<Moon className="size-4" />}
              label={t("notifications.me.preferences.quiet_hours")}
              value={
                quietActive ? `${data?.quiet_hours.from}–${data?.quiet_hours.to}` : t("common.off")
              }
              tone={quietActive ? "info" : "neutral"}
            />
            <SummaryTile
              icon={<Settings2 className="size-4" />}
              label={t("notifications.me.preferences.opt_outs")}
              value={`${optedOutCount}`}
              tone={optedOutCount > 0 ? "info" : "neutral"}
            />
          </div>

          {/* Master mute */}
          <Card>
            <CardContent className="flex items-center justify-between gap-4 p-5">
              <div className="flex items-start gap-4">
                <div
                  className={
                    masterMute
                      ? "rounded-lg bg-amber-100 p-2.5 dark:bg-amber-950"
                      : "rounded-lg bg-muted p-2.5"
                  }
                >
                  <VolumeX
                    className={
                      masterMute
                        ? "size-5 text-amber-700 dark:text-amber-400"
                        : "size-5 text-muted-foreground"
                    }
                  />
                </div>
                <div>
                  <div className="flex items-center gap-2">
                    <p className="text-sm font-semibold">
                      {t("notifications.me.preferences.master_mute")}
                    </p>
                    {masterMute ? <Badge variant="secondary">{t("common.on")}</Badge> : null}
                  </div>
                  <p className="mt-0.5 text-sm text-muted-foreground">
                    {t("notifications.me.preferences.master_mute_hint")}
                  </p>
                </div>
              </div>
              <Switch
                checked={masterMute}
                onCheckedChange={(next) => setMasterMute.mutate(next)}
                disabled={isLoading}
              />
            </CardContent>
          </Card>

          {/* Quiet hours */}
          <Card>
            <CardContent className="space-y-4 p-5">
              <div className="flex items-start gap-4">
                <div
                  className={
                    quietActive
                      ? "rounded-lg bg-blue-100 p-2.5 dark:bg-blue-950"
                      : "rounded-lg bg-muted p-2.5"
                  }
                >
                  <Clock
                    className={
                      quietActive
                        ? "size-5 text-blue-700 dark:text-blue-400"
                        : "size-5 text-muted-foreground"
                    }
                  />
                </div>
                <div className="flex-1">
                  <div className="flex items-center gap-2">
                    <p className="text-sm font-semibold">
                      {t("notifications.me.preferences.quiet_hours")}
                    </p>
                    {quietActive ? (
                      <Badge variant="secondary">
                        {data?.quiet_hours.from}–{data?.quiet_hours.to}
                      </Badge>
                    ) : null}
                  </div>
                  <p className="mt-0.5 text-sm text-muted-foreground">
                    {t("notifications.me.preferences.quiet_hours_hint")}
                  </p>
                </div>
              </div>
              <div className="flex flex-wrap items-end gap-3 rounded-md border bg-muted/20 p-3">
                <div className="space-y-1">
                  <label className="text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                    {t("common.from")}
                  </label>
                  <Input
                    type="time"
                    value={from}
                    onChange={(e) => {
                      setFrom(e.target.value);
                      setQuietDirty(true);
                    }}
                    className="h-9 w-28"
                  />
                </div>
                <div className="space-y-1">
                  <label className="text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                    {t("common.to")}
                  </label>
                  <Input
                    type="time"
                    value={to}
                    onChange={(e) => {
                      setTo(e.target.value);
                      setQuietDirty(true);
                    }}
                    className="h-9 w-28"
                  />
                </div>
                <div className="flex-1 space-y-1">
                  <label className="text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                    {t("common.timezone")}
                  </label>
                  <Select
                    value={tz}
                    onValueChange={(v) => {
                      setTz(v);
                      setQuietDirty(true);
                    }}
                  >
                    <SelectTrigger className="h-9">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {COMMON_TIMEZONES.map((id) => (
                        <SelectItem key={id} value={id}>
                          {id}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
                <Button
                  size="sm"
                  variant={quietDirty ? "default" : "outline"}
                  onClick={handleQuietHoursSave}
                  disabled={setQuietHours.isPending || !from || !to || !quietDirty}
                >
                  {setQuietHours.isPending ? <Spinner className="mr-2 size-3.5" /> : null}
                  {t("common.save")}
                </Button>
              </div>
            </CardContent>
          </Card>

          {/* Per-type matrix */}
          <Card>
            <CardContent className="p-5">
              <div className="mb-4 flex items-start justify-between gap-4">
                <div>
                  <p className="text-sm font-semibold">
                    {t("notifications.me.preferences.per_type_matrix")}
                  </p>
                  <p className="mt-0.5 text-sm text-muted-foreground">
                    {t("notifications.me.preferences.per_type_hint")}
                  </p>
                </div>
                <Badge variant="outline">{(types ?? []).length}</Badge>
              </div>
              {isLoading || typesLoading ? (
                <Skeleton className="h-48" />
              ) : groups.length === 0 ? (
                <div className="flex flex-col items-center justify-center gap-2 rounded-md border border-dashed p-10 text-center">
                  <Bell className="size-8 text-muted-foreground" />
                  <p className="text-sm text-muted-foreground">
                    {t("notifications.me.preferences.empty_types")}
                  </p>
                </div>
              ) : (
                <div className="overflow-x-auto rounded-md border">
                  <table className="w-full text-sm">
                    <thead className="bg-muted/30">
                      <tr>
                        <th className="py-2.5 pr-2 pl-4 text-left font-medium text-muted-foreground">
                          {t("notifications.me.preferences.column_type")}
                        </th>
                        {CHANNELS.map((c) => {
                          const Icon = CHANNEL_ICONS[c];
                          return (
                            <th key={c} className="py-2.5 text-center font-medium">
                              <div className="flex flex-col items-center gap-1 text-muted-foreground">
                                <Icon className="size-3.5" />
                                <span className="text-[10px] tracking-wider uppercase">
                                  {t(`notifications.channel.${c}`)}
                                </span>
                              </div>
                            </th>
                          );
                        })}
                      </tr>
                    </thead>
                    <tbody>
                      {groups.map(([prefix, typeList]) => (
                        <Fragment key={prefix}>
                          <tr className="bg-muted/10">
                            <td
                              colSpan={CHANNELS.length + 1}
                              className="px-4 py-1.5 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                            >
                              {prefix}
                            </td>
                          </tr>
                          {typeList.map((type) => (
                            <tr key={type} className="border-t hover:bg-muted/20">
                              <td className="py-2 pr-2 pl-4 font-mono text-xs">{type}</td>
                              {CHANNELS.map((c) => (
                                <td key={c} className="py-2 text-center">
                                  <Switch
                                    checked={cellChecked(type, c)}
                                    onCheckedChange={(next) => toggleCell(type, c, next)}
                                  />
                                </td>
                              ))}
                            </tr>
                          ))}
                        </Fragment>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </CardContent>
          </Card>

          <DigestSection />
        </div>
      </PageContent>
    </>
  );
}

// =========================================================================
//  Plan-023 M5 T5.9 — DigestSection
// =========================================================================

const PRIORITIES_ORDERED: DigestPriority[] = ["urgent", "high", "normal", "low"];

function DigestSection() {
  const { t, locale } = useTranslation();
  const { timezone: displayTimezone } = useTimezone();
  const { data, isLoading, isError, refetch } = useDigestPreference();
  const updateMutation = useUpdateDigestPreference();

  const [cadence, setCadence] = useState<DigestCadence>("off");
  const [deliveryTime, setDeliveryTime] = useState<string>("08:00");
  const [timezone, setTimezone] = useState<string>("Asia/Tokyo");
  const [weekday, setWeekday] = useState<number>(1);
  const [included, setIncluded] = useState<Set<DigestPriority>>(
    new Set(["urgent", "high", "normal", "low"])
  );
  const [dirty, setDirty] = useState(false);

  // Hydrate form state once the server prefs land. This is the legitimate
  // "set state from async data" pattern — disabling the lint that lumps it
  // in with the anti-pattern of setting derived state from props.
  /* eslint-disable react-hooks/set-state-in-effect */
  useEffect(() => {
    if (!data) return;
    setCadence(data.cadence);
    setDeliveryTime(data.delivery_time);
    setTimezone(data.timezone);
    setWeekday(data.weekday ?? 1);
    setIncluded(new Set(data.include_priorities));
    setDirty(false);
  }, [data]);
  /* eslint-enable react-hooks/set-state-in-effect */

  function togglePriority(p: DigestPriority, next: boolean) {
    const copy = new Set(included);
    if (next) copy.add(p);
    else copy.delete(p);
    setIncluded(copy);
    setDirty(true);
  }

  async function onSave() {
    await updateMutation.mutateAsync({
      cadence,
      delivery_time: deliveryTime,
      timezone,
      weekday: cadence === "weekly" ? weekday : null,
      include_priorities: Array.from(included),
    });
    setDirty(false);
  }

  return (
    <Card data-slot="digest-section">
      <CardContent className="space-y-4 p-5">
        <div>
          <p className="text-sm font-semibold">{t("notifications.digest.title")}</p>
          <p className="text-xs text-muted-foreground">{t("notifications.digest.subtitle")}</p>
        </div>

        {isError ? (
          <Alert variant="destructive">
            <AlertDescription className="flex items-center justify-between">
              {t("common.error_loading")}
              <Button variant="outline" size="sm" onClick={() => refetch()}>
                {t("common.retry")}
              </Button>
            </AlertDescription>
          </Alert>
        ) : null}

        {isLoading ? (
          <Skeleton className="h-32 w-full" />
        ) : (
          <>
            {/* Cadence */}
            <div>
              <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {t("notifications.digest.cadence")}
              </p>
              <div className="flex flex-wrap gap-2">
                {(["off", "daily", "weekly"] as DigestCadence[]).map((c) => (
                  <Button
                    key={c}
                    size="sm"
                    variant={cadence === c ? "default" : "outline"}
                    onClick={() => {
                      setCadence(c);
                      setDirty(true);
                    }}
                  >
                    {t(`notifications.digest.cadence_${c}`)}
                  </Button>
                ))}
              </div>
            </div>

            {cadence !== "off" ? (
              <>
                {cadence === "weekly" ? (
                  <div>
                    <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                      {t("notifications.digest.weekday")}
                    </p>
                    <div className="flex flex-wrap gap-1">
                      {[0, 1, 2, 3, 4, 5, 6].map((d) => (
                        <Button
                          key={d}
                          size="sm"
                          variant={weekday === d ? "default" : "outline"}
                          onClick={() => {
                            setWeekday(d);
                            setDirty(true);
                          }}
                        >
                          {t(`notifications.digest.weekday_${d}`)}
                        </Button>
                      ))}
                    </div>
                  </div>
                ) : null}

                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                      {t("notifications.digest.delivery_time")}
                    </p>
                    <Input
                      type="time"
                      value={deliveryTime}
                      onChange={(e) => {
                        setDeliveryTime(e.target.value);
                        setDirty(true);
                      }}
                    />
                  </div>
                  <div>
                    <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                      {t("notifications.digest.timezone")}
                    </p>
                    <Select
                      value={timezone}
                      onValueChange={(v) => {
                        setTimezone(v);
                        setDirty(true);
                      }}
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {COMMON_TIMEZONES.map((tz) => (
                          <SelectItem key={tz} value={tz}>
                            {tz}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>

                <div>
                  <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t("notifications.digest.priorities")}
                  </p>
                  <div className="flex flex-wrap gap-2">
                    {PRIORITIES_ORDERED.map((p) => {
                      const checked = included.has(p);
                      return (
                        <Button
                          key={p}
                          size="sm"
                          variant={checked ? "default" : "outline"}
                          onClick={() => togglePriority(p, !checked)}
                        >
                          {t(`notifications.digest.priority_${p}`)}
                        </Button>
                      );
                    })}
                  </div>
                </div>
              </>
            ) : null}

            <div className="flex items-center justify-between pt-2">
              <span className="text-xs text-muted-foreground">
                {data?.last_sent_at
                  ? t("notifications.digest.last_sent_at_label", {
                      at: formatDateTime(data.last_sent_at, locale, displayTimezone),
                    })
                  : t("notifications.digest.never_sent")}
              </span>
              <Button onClick={onSave} disabled={!dirty || updateMutation.isPending}>
                {updateMutation.isPending ? <Spinner className="mr-2 size-3.5" /> : null}
                {t("common.save")}
              </Button>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}

function SummaryTile({
  icon,
  label,
  value,
  tone,
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  tone: "neutral" | "info" | "warning";
}) {
  const toneCls = {
    neutral: "bg-muted/30 text-muted-foreground",
    info: "bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300",
    warning: "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300",
  }[tone];

  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <div className={`rounded-md p-2 ${toneCls}`}>{icon}</div>
        <div className="min-w-0 flex-1">
          <p className="truncate text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
            {label}
          </p>
          <p className="truncate text-sm font-semibold">{value}</p>
        </div>
      </CardContent>
    </Card>
  );
}
