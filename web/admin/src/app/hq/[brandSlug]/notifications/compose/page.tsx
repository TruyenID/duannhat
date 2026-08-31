"use client";

/**
 * HQ Notification broadcast composer (plan-012 T4.12).
 *
 * Two-column layout: wizard form on the left, persistent live preview on
 * the right (audience count, template render, channel set, full summary).
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
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@godxjp/ui";
import {
  AlertCircle,
  Bell,
  Calendar,
  Check,
  CheckCircle2,
  Mail,
  MessageSquare,
  MonitorSmartphone,
  Radio,
  Repeat,
  Send,
  Sparkles,
  Users,
  Zap,
  X,
} from "lucide-react";
import { useParams, useRouter } from "next/navigation";
import { useMemo, useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { useAudiencePreview, useAudiences } from "@/hooks/api/use-audiences";
import { useBroadcast } from "@/hooks/api/use-broadcast";
import {
  useCreateNotificationSchedule,
  usePreviewRrule,
} from "@/hooks/api/use-notification-schedules";
import { useTemplateRenderSavedPreview, useTemplates } from "@/hooks/api/use-templates";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
import type {
  NotificationChannel,
  NotificationPriority,
} from "@/services/notification-routing-service";

const CHANNELS: NotificationChannel[] = ["in_app", "realtime", "email", "push"];
const CHANNEL_ICONS: Record<NotificationChannel, typeof Bell> = {
  in_app: Bell,
  realtime: Radio,
  email: Mail,
  push: MonitorSmartphone,
};
const PRIORITIES: NotificationPriority[] = ["low", "normal", "high", "urgent"];
const PRIORITY_TONE: Record<NotificationPriority, string> = {
  low: "bg-muted text-muted-foreground",
  normal: "bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300",
  high: "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300",
  urgent: "bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300",
};

type Step = 1 | 2 | 3 | 4;
const LOCALES: Array<"ja" | "en" | "vi"> = ["ja", "en", "vi"];

// Plan-023 M3 T3.7 — Repeats step shape
type RepeatMode = "none" | "daily" | "weekly" | "monthly" | "custom";
type RepeatEndsMode = "never" | "after_n" | "on_date";
const WEEKDAYS = ["MO", "TU", "WE", "TH", "FR", "SA", "SU"] as const;
type Weekday = (typeof WEEKDAYS)[number];

/**
 * Build an RRULE substring from the simple "Repeats" UI selections.
 * `custom` mode passes through the user-typed RRULE verbatim.
 */
function buildRrule(opts: {
  mode: RepeatMode;
  weekdays: Set<Weekday>;
  monthDay: number;
  timeHour: number;
  timeMinute: number;
  customRrule: string;
  occurrencesRemaining: number | null;
  endsMode: RepeatEndsMode;
  endsAt: string;
}): string {
  if (opts.mode === "none") return "";
  if (opts.mode === "custom") return opts.customRrule.trim();
  const parts: string[] = [];
  if (opts.mode === "daily") {
    parts.push("FREQ=DAILY");
  } else if (opts.mode === "weekly") {
    parts.push("FREQ=WEEKLY");
    if (opts.weekdays.size > 0) parts.push(`BYDAY=${[...opts.weekdays].join(",")}`);
  } else if (opts.mode === "monthly") {
    parts.push("FREQ=MONTHLY");
    parts.push(`BYMONTHDAY=${opts.monthDay}`);
  }
  parts.push(`BYHOUR=${opts.timeHour}`);
  parts.push(`BYMINUTE=${opts.timeMinute}`);
  if (opts.endsMode === "after_n" && opts.occurrencesRemaining !== null) {
    parts.push(`COUNT=${opts.occurrencesRemaining}`);
  }
  return parts.join(";");
}

export default function BroadcastComposerPage() {
  const params = useParams<{ brandSlug: string }>();
  const router = useRouter();
  const brandSlug = params.brandSlug;
  const { t } = useTranslation();

  const { data: audiencesPage, isLoading: audLoading } = useAudiences(brandSlug);
  const { data: templatesPage, isLoading: tplLoading } = useTemplates(brandSlug);
  const broadcast = useBroadcast(brandSlug);
  const createSchedule = useCreateNotificationSchedule(brandSlug);

  const [step, setStep] = useState<Step>(1);
  const [audienceId, setAudienceId] = useState<string | null>(null);
  const [templateId, setTemplateId] = useState<string | null>(null);
  const [channels, setChannels] = useState<Set<NotificationChannel>>(new Set(["in_app"]));
  const [priority, setPriority] = useState<NotificationPriority>("normal");
  const [scheduledFor, setScheduledFor] = useState<string>("");

  // Plan-023 M3 T3.7 — Repeats step state. `none` means single-shot (existing
  // broadcast path); any other mode submits via the schedules endpoint.
  const [repeatMode, setRepeatMode] = useState<RepeatMode>("none");
  const [repeatWeekdays, setRepeatWeekdays] = useState<Set<Weekday>>(new Set(["MO"]));
  const [repeatMonthDay, setRepeatMonthDay] = useState<number>(1);
  const [repeatTime, setRepeatTime] = useState<string>("09:00");
  const [repeatTimezone, setRepeatTimezone] = useState<string>("Asia/Tokyo");
  const [repeatCustomRrule, setRepeatCustomRrule] = useState<string>(
    "FREQ=DAILY;BYHOUR=9;BYMINUTE=0"
  );
  const [repeatStartsAt, setRepeatStartsAt] = useState<string>("");
  const [repeatEndsMode, setRepeatEndsMode] = useState<RepeatEndsMode>("never");
  const [repeatOccurrencesRemaining, setRepeatOccurrencesRemaining] = useState<number>(10);
  const [repeatEndsAt, setRepeatEndsAt] = useState<string>("");

  const audiences = useMemo(() => audiencesPage?.data ?? [], [audiencesPage]);
  const templates = useMemo(() => templatesPage?.data ?? [], [templatesPage]);

  const selectedAudience = useMemo(
    () => audiences.find((a) => a.id === audienceId) ?? null,
    [audiences, audienceId]
  );
  const selectedTemplate = useMemo(
    () => templates.find((tpl) => tpl.id === templateId) ?? null,
    [templates, templateId]
  );

  const preview = useAudiencePreview(brandSlug, selectedAudience?.rule ?? null);

  // Plan-023 M3 T3.7 — derived RRULE + live next-5-occurrences preview.
  const [timeHour, timeMinute] = useMemo(() => {
    const [h, m] = repeatTime.split(":");
    return [Number(h) || 9, Number(m) || 0];
  }, [repeatTime]);
  const derivedRrule = useMemo(
    () =>
      buildRrule({
        mode: repeatMode,
        weekdays: repeatWeekdays,
        monthDay: repeatMonthDay,
        timeHour,
        timeMinute,
        customRrule: repeatCustomRrule,
        occurrencesRemaining: repeatEndsMode === "after_n" ? repeatOccurrencesRemaining : null,
        endsMode: repeatEndsMode,
        endsAt: repeatEndsAt,
      }),
    [
      repeatMode,
      repeatWeekdays,
      repeatMonthDay,
      timeHour,
      timeMinute,
      repeatCustomRrule,
      repeatEndsMode,
      repeatOccurrencesRemaining,
      repeatEndsAt,
    ]
  );
  const rrulePreviewInput = useMemo(() => {
    if (repeatMode === "none" || derivedRrule === "" || !repeatStartsAt) return null;
    return {
      rrule: derivedRrule,
      timezone: repeatTimezone,
      starts_at: new Date(repeatStartsAt).toISOString(),
      count: 5,
    };
  }, [repeatMode, derivedRrule, repeatTimezone, repeatStartsAt]);
  const rrulePreview = usePreviewRrule(brandSlug, rrulePreviewInput);
  const paramsSample = useMemo(() => {
    const sample: Record<string, string> = {};
    const schema = selectedTemplate?.params_schema;
    if (schema) {
      [...(schema.required ?? []), ...(schema.optional ?? [])].forEach((k) => {
        sample[k] = `<${k}>`;
      });
    }
    return sample;
  }, [selectedTemplate]);
  const render = useTemplateRenderSavedPreview(brandSlug, templateId, paramsSample);

  function canAdvance(): boolean {
    if (step === 1) return audienceId !== null && (preview.data?.count ?? 0) > 0;
    if (step === 2) return templateId !== null;
    if (step === 3) return channels.size > 0;
    // Step 4 (Repeats): single-shot is always valid; recurring requires a
    // non-empty RRULE + a starts_at anchor; custom mode requires the typed
    // RRULE to actually parse (best-effort — backend validates authoritatively).
    if (repeatMode === "none") return true;
    if (!repeatStartsAt) return false;
    if (derivedRrule === "") return false;
    return true;
  }

  function toggleChannel(ch: NotificationChannel, next: boolean) {
    setChannels((prev) => {
      const copy = new Set(prev);
      if (next) copy.add(ch);
      else copy.delete(ch);
      return copy;
    });
  }

  async function handleSubmit() {
    if (!audienceId || !templateId || channels.size === 0) return;

    // Plan-023 M3 T3.7 — repeating broadcasts POST to /schedules instead of
    // /broadcast. Selected template's `key` becomes template_key on the
    // schedule row (the recurring tick worker resolves the audience + the
    // template afresh at every occurrence).
    if (repeatMode !== "none" && selectedTemplate && repeatStartsAt && derivedRrule) {
      try {
        await createSchedule.mutateAsync({
          template_key: selectedTemplate.key,
          audience_id: audienceId,
          channels: Array.from(channels),
          priority,
          rrule: derivedRrule,
          timezone: repeatTimezone,
          starts_at: new Date(repeatStartsAt).toISOString(),
          ends_at:
            repeatEndsMode === "on_date" && repeatEndsAt
              ? new Date(repeatEndsAt).toISOString()
              : null,
          occurrences_remaining: repeatEndsMode === "after_n" ? repeatOccurrencesRemaining : null,
        });
        router.push(`/hq/${brandSlug}/notifications/schedules`);
      } catch {
        /* error surfaced below */
      }
      return;
    }

    try {
      const res = await broadcast.mutateAsync({
        audience_id: audienceId,
        template_id: templateId,
        channels: Array.from(channels),
        priority,
        scheduled_for: scheduledFor ? new Date(scheduledFor).toISOString() : null,
      });
      router.push(`/hq/${brandSlug}/notifications?detail=${res.id}`);
    } catch {
      /* error surfaced below */
    }
  }

  const stepMeta = [
    { num: 1, label: t("notifications.admin.compose.step1_title"), icon: Users },
    { num: 2, label: t("notifications.admin.compose.step2_title"), icon: MessageSquare },
    { num: 3, label: t("notifications.admin.compose.step3_title"), icon: Send },
    { num: 4, label: t("notifications.admin.compose.step4_title"), icon: Repeat },
  ] as const;

  return (
    <>
      <PageHeader
        title={t("notifications.admin.compose.title")}
        description={t("notifications.admin.compose.subtitle")}
      />

      <PageContent>
        {/* Stepper */}
        <div className="mb-6 rounded-lg border bg-muted/20 p-4">
          <div className="flex items-center justify-between">
            {stepMeta.map((s, i) => {
              const active = step === s.num;
              const done = step > s.num;
              const Icon = done ? CheckCircle2 : s.icon;
              return (
                <div key={s.num} className="flex flex-1 items-center gap-3">
                  <div
                    className={
                      done
                        ? "flex size-9 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground"
                        : active
                          ? "flex size-9 shrink-0 items-center justify-center rounded-full border-2 border-primary bg-background text-primary"
                          : "flex size-9 shrink-0 items-center justify-center rounded-full border bg-background text-muted-foreground"
                    }
                  >
                    <Icon className="size-4" />
                  </div>
                  <div className="flex-1">
                    <p className="text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                      Step {s.num}
                    </p>
                    <p
                      className={
                        active
                          ? "text-sm font-semibold"
                          : done
                            ? "text-sm text-foreground"
                            : "text-sm text-muted-foreground"
                      }
                    >
                      {s.label}
                    </p>
                  </div>
                  {i < stepMeta.length - 1 ? (
                    <div className={done ? "h-px flex-1 bg-primary" : "h-px flex-1 bg-border"} />
                  ) : null}
                </div>
              );
            })}
          </div>
        </div>

        {broadcast.isError ? (
          <Alert className="mb-4" variant="destructive">
            <AlertCircle className="size-4" />
            <AlertDescription>
              {(broadcast.error as Error | undefined)?.message ?? t("common.error_loading")}
            </AlertDescription>
          </Alert>
        ) : null}

        <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_360px]">
          {/* ─── Left: form ─── */}
          <div className="flex flex-col gap-4">
            {step === 1 ? (
              <AudienceStep
                audiences={audiences}
                audLoading={audLoading}
                audienceId={audienceId}
                setAudienceId={setAudienceId}
                t={t}
              />
            ) : null}
            {step === 2 ? (
              <TemplateStep
                templates={templates}
                tplLoading={tplLoading}
                templateId={templateId}
                setTemplateId={setTemplateId}
                t={t}
              />
            ) : null}
            {step === 3 ? (
              <DeliveryStep
                channels={channels}
                toggleChannel={toggleChannel}
                priority={priority}
                setPriority={setPriority}
                scheduledFor={scheduledFor}
                setScheduledFor={setScheduledFor}
                t={t}
              />
            ) : null}
            {step === 4 ? (
              <RepeatsStep
                t={t}
                mode={repeatMode}
                setMode={setRepeatMode}
                weekdays={repeatWeekdays}
                setWeekdays={setRepeatWeekdays}
                monthDay={repeatMonthDay}
                setMonthDay={setRepeatMonthDay}
                time={repeatTime}
                setTime={setRepeatTime}
                timezone={repeatTimezone}
                setTimezone={setRepeatTimezone}
                customRrule={repeatCustomRrule}
                setCustomRrule={setRepeatCustomRrule}
                startsAt={repeatStartsAt}
                setStartsAt={setRepeatStartsAt}
                endsMode={repeatEndsMode}
                setEndsMode={setRepeatEndsMode}
                occurrencesRemaining={repeatOccurrencesRemaining}
                setOccurrencesRemaining={setRepeatOccurrencesRemaining}
                endsAt={repeatEndsAt}
                setEndsAt={setRepeatEndsAt}
                derivedRrule={derivedRrule}
                previewOccurrences={rrulePreview.data?.data.occurrences ?? null}
                previewLoading={rrulePreview.isLoading}
                previewError={rrulePreview.isError}
              />
            ) : null}

            {/* Navigation */}
            <div className="flex items-center justify-between">
              <Button
                variant="outline"
                onClick={() => setStep((s) => (s > 1 ? ((s - 1) as Step) : s))}
                disabled={step === 1 || broadcast.isPending}
              >
                {t("common.back")}
              </Button>
              {step < 4 ? (
                <Button
                  onClick={() => setStep((s) => (s < 4 ? ((s + 1) as Step) : s))}
                  disabled={!canAdvance()}
                >
                  {t("common.next")}
                </Button>
              ) : (
                <Button
                  size="lg"
                  onClick={handleSubmit}
                  disabled={!canAdvance() || broadcast.isPending || createSchedule.isPending}
                >
                  {broadcast.isPending || createSchedule.isPending ? (
                    <Spinner className="mr-2 size-4" />
                  ) : repeatMode !== "none" ? (
                    <Repeat className="mr-2 size-4" />
                  ) : (
                    <Send className="mr-2 size-4" />
                  )}
                  {repeatMode !== "none"
                    ? t("notifications.admin.compose.submit_recurring")
                    : scheduledFor
                      ? t("notifications.admin.compose.submit_scheduled")
                      : t("notifications.admin.compose.submit")}
                </Button>
              )}
            </div>
          </div>

          {/* ─── Right: live preview / summary ─── */}
          <div className="flex flex-col gap-4 lg:sticky lg:top-16 lg:self-start">
            <PreviewCard
              icon={<Users className="size-3.5 text-muted-foreground" />}
              label={t("notifications.admin.compose.audience_header")}
              right={
                selectedAudience && preview.data ? (
                  <Badge variant={preview.data.count === 0 ? "destructive" : "secondary"}>
                    {preview.data.count}
                  </Badge>
                ) : null
              }
            >
              {!selectedAudience ? (
                <p className="text-sm text-muted-foreground">
                  {t("notifications.admin.compose.audience_empty_state")}
                </p>
              ) : (
                <div>
                  <p className="text-sm font-medium">{selectedAudience.name}</p>
                  {preview.isLoading ? (
                    <div className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                      <Spinner className="size-3" /> {t("common.loading")}
                    </div>
                  ) : preview.data ? (
                    <div className="mt-2 space-y-1">
                      <p className="text-xs text-muted-foreground">
                        {t("notifications.admin.compose.audience_count", {
                          count: preview.data.count,
                        })}
                      </p>
                      {preview.data.sample.length > 0 ? (
                        <div className="mt-2 flex flex-wrap gap-1">
                          {preview.data.sample.slice(0, 5).map((s) => (
                            <Badge key={s.id} variant="outline" className="text-[10px]">
                              {s.label}
                            </Badge>
                          ))}
                          {preview.data.count > 5 ? (
                            <Badge variant="outline" className="text-[10px]">
                              +{preview.data.count - 5}
                            </Badge>
                          ) : null}
                        </div>
                      ) : null}
                    </div>
                  ) : null}
                </div>
              )}
            </PreviewCard>

            <PreviewCard
              icon={<MessageSquare className="size-3.5 text-muted-foreground" />}
              label={t("notifications.admin.compose.template_preview")}
              right={
                selectedTemplate ? (
                  <span className="font-mono text-[10px] text-muted-foreground">
                    {selectedTemplate.key}
                  </span>
                ) : null
              }
            >
              {!selectedTemplate ? (
                <p className="text-sm text-muted-foreground">
                  {t("notifications.admin.compose.preview_placeholder")}
                </p>
              ) : (
                <Tabs defaultValue="ja">
                  <TabsList className="h-8">
                    {LOCALES.map((loc) => (
                      <TabsTrigger key={loc} value={loc} className="h-6 text-[10px] uppercase">
                        {loc}
                      </TabsTrigger>
                    ))}
                  </TabsList>
                  {LOCALES.map((loc) => (
                    <TabsContent key={loc} value={loc} className="mt-2">
                      {render.isLoading ? (
                        <Spinner className="size-3.5" />
                      ) : render.data ? (
                        <div className="space-y-1 rounded-md bg-muted/30 p-3 text-sm">
                          <p className="font-semibold">{render.data[loc]?.title}</p>
                          <p className="text-xs whitespace-pre-wrap text-muted-foreground">
                            {render.data[loc]?.body}
                          </p>
                        </div>
                      ) : null}
                    </TabsContent>
                  ))}
                </Tabs>
              )}
            </PreviewCard>

            <PreviewCard
              icon={<Sparkles className="size-3.5 text-muted-foreground" />}
              label={t("notifications.admin.compose.summary")}
            >
              <div className="space-y-2 text-xs">
                <SummaryRow
                  icon={<Send className="size-3.5" />}
                  label={t("notifications.admin.compose.channels_header")}
                >
                  {channels.size === 0 ? (
                    <span className="text-muted-foreground">—</span>
                  ) : (
                    <div className="flex flex-wrap gap-1">
                      {Array.from(channels).map((c) => (
                        <Badge key={c} variant="secondary" className="text-[10px]">
                          {t(`notifications.channel.${c}`)}
                        </Badge>
                      ))}
                    </div>
                  )}
                </SummaryRow>
                <SummaryRow
                  icon={<Zap className="size-3.5" />}
                  label={t("notifications.admin.compose.priority_header")}
                >
                  <span
                    className={`rounded px-2 py-0.5 text-[10px] font-medium ${PRIORITY_TONE[priority]}`}
                  >
                    {t(`notifications.priority.${priority}`)}
                  </span>
                </SummaryRow>
                <SummaryRow
                  icon={<Calendar className="size-3.5" />}
                  label={t("notifications.admin.compose.scheduled_header")}
                >
                  <span className="text-muted-foreground">
                    {scheduledFor || t("notifications.admin.compose.send_now")}
                  </span>
                </SummaryRow>
              </div>
            </PreviewCard>
          </div>
        </div>
      </PageContent>
    </>
  );
}

function StepHeader({
  icon: Icon,
  title,
  description,
}: {
  icon: typeof Bell;
  title: string;
  description: string;
}) {
  return (
    <div className="flex items-start gap-3">
      <div className="rounded-lg bg-primary/10 p-2">
        <Icon className="size-5 text-primary" />
      </div>
      <div className="flex-1">
        <h2 className="text-base font-semibold">{title}</h2>
        <p className="mt-1 text-sm text-muted-foreground">{description}</p>
      </div>
    </div>
  );
}

function AudienceStep({
  audiences,
  audLoading,
  audienceId,
  setAudienceId,
  t,
}: {
  audiences: ReturnType<typeof useAudiences>["data"] extends infer T
    ? T extends { data: infer D }
      ? D
      : []
    : [];
  audLoading: boolean;
  audienceId: string | null;
  setAudienceId: (v: string | null) => void;
  t: (k: string, p?: Record<string, string | number>) => string;
}) {
  return (
    <Card>
      <CardContent className="space-y-5 p-6">
        <StepHeader
          icon={Users}
          title={t("notifications.admin.compose.audience_header")}
          description={t("notifications.admin.compose.audience_hint")}
        />
        {audLoading ? (
          <Skeleton className="h-10 w-full" />
        ) : (
          <Select value={audienceId ?? ""} onValueChange={(v) => setAudienceId(v || null)}>
            <SelectTrigger className="h-auto min-h-11 items-center justify-start py-2 text-left [&>span]:flex-1 [&>span]:text-left">
              <SelectValue placeholder={t("notifications.admin.compose.audience_placeholder")} />
            </SelectTrigger>
            <SelectContent>
              {(audiences ?? []).map((a) => (
                <SelectItem key={a.id} value={a.id}>
                  <div className="flex flex-col items-start text-left">
                    <span className="font-medium">{a.name}</span>
                    {a.description ? (
                      <span className="text-xs text-muted-foreground">{a.description}</span>
                    ) : null}
                  </div>
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </CardContent>
    </Card>
  );
}

function TemplateStep({
  templates,
  tplLoading,
  templateId,
  setTemplateId,
  t,
}: {
  templates: ReturnType<typeof useTemplates>["data"] extends infer T
    ? T extends { data: infer D }
      ? D
      : []
    : [];
  tplLoading: boolean;
  templateId: string | null;
  setTemplateId: (v: string | null) => void;
  t: (k: string, p?: Record<string, string | number>) => string;
}) {
  return (
    <Card>
      <CardContent className="space-y-5 p-6">
        <StepHeader
          icon={MessageSquare}
          title={t("notifications.admin.compose.template_header")}
          description={t("notifications.admin.compose.template_hint")}
        />
        {tplLoading ? (
          <Skeleton className="h-10 w-full" />
        ) : (
          <Select value={templateId ?? ""} onValueChange={(v) => setTemplateId(v || null)}>
            <SelectTrigger className="h-11">
              <SelectValue placeholder={t("notifications.admin.compose.template_placeholder")} />
            </SelectTrigger>
            <SelectContent>
              {(templates ?? []).map((tpl) => (
                <SelectItem key={tpl.id} value={tpl.id}>
                  <span className="font-mono text-xs">{tpl.key}</span>
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </CardContent>
    </Card>
  );
}

function DeliveryStep({
  channels,
  toggleChannel,
  priority,
  setPriority,
  scheduledFor,
  setScheduledFor,
  t,
}: {
  channels: Set<NotificationChannel>;
  toggleChannel: (c: NotificationChannel, n: boolean) => void;
  priority: NotificationPriority;
  setPriority: (p: NotificationPriority) => void;
  scheduledFor: string;
  setScheduledFor: (v: string) => void;
  t: (k: string, p?: Record<string, string | number>) => string;
}) {
  return (
    <Card>
      <CardContent className="space-y-6 p-6">
        <StepHeader
          icon={Send}
          title={t("notifications.admin.compose.step3_title")}
          description={t("notifications.admin.compose.delivery_hint")}
        />

        <div className="space-y-2">
          <p className="text-sm font-medium">{t("notifications.admin.compose.channels_header")}</p>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {CHANNELS.map((c) => {
              const Icon = CHANNEL_ICONS[c];
              const active = channels.has(c);
              return (
                <button
                  key={c}
                  type="button"
                  onClick={() => toggleChannel(c, !active)}
                  className={
                    active
                      ? "group flex flex-col items-center gap-2 rounded-lg border-2 border-primary bg-primary/5 p-4 transition"
                      : "group flex flex-col items-center gap-2 rounded-lg border-2 border-transparent bg-muted/30 p-4 text-muted-foreground transition hover:bg-muted/50"
                  }
                >
                  <Icon className={active ? "size-5 text-primary" : "size-5"} />
                  <span className={active ? "text-xs font-medium" : "text-xs"}>
                    {t(`notifications.channel.${c}`)}
                  </span>
                  {active ? <Check className="size-3 text-primary" /> : <span className="size-3" />}
                </button>
              );
            })}
          </div>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-2">
            <p className="text-sm font-medium">
              {t("notifications.admin.compose.priority_header")}
            </p>
            <Select value={priority} onValueChange={(v) => setPriority(v as NotificationPriority)}>
              <SelectTrigger className="h-10">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {PRIORITIES.map((p) => (
                  <SelectItem key={p} value={p}>
                    <div className="flex items-center gap-2">
                      {p === "urgent" ? <Zap className="size-3.5" /> : null}
                      <span>{t(`notifications.priority.${p}`)}</span>
                    </div>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <p className="text-sm font-medium">
                {t("notifications.admin.compose.scheduled_header")}
              </p>
              {scheduledFor ? (
                <button
                  type="button"
                  onClick={() => setScheduledFor("")}
                  className="text-xs text-muted-foreground hover:text-foreground"
                >
                  <X className="h-3.5 w-3.5" aria-hidden />
                </button>
              ) : null}
            </div>
            <Input
              type="datetime-local"
              value={scheduledFor}
              onChange={(e) => setScheduledFor(e.target.value)}
              className="h-10"
            />
            <p className="text-xs text-muted-foreground">
              {t("notifications.admin.compose.scheduled_hint")}
            </p>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

function PreviewCard({
  icon,
  label,
  right,
  children,
}: {
  icon: React.ReactNode;
  label: string;
  right?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <Card className="overflow-hidden">
      <div className="flex items-center justify-between border-b bg-muted/30 px-4 py-2">
        <div className="flex items-center gap-2">
          {icon}
          <span className="text-xs font-medium tracking-wider text-muted-foreground uppercase">
            {label}
          </span>
        </div>
        {right}
      </div>
      <CardContent className="p-4">{children}</CardContent>
    </Card>
  );
}

function SummaryRow({
  icon,
  label,
  children,
}: {
  icon: React.ReactNode;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex items-start justify-between gap-2 border-b pb-2 last:border-0 last:pb-0">
      <div className="flex items-center gap-1.5 text-muted-foreground">
        {icon}
        <span>{label}</span>
      </div>
      <div className="text-right">{children}</div>
    </div>
  );
}

// =========================================================================
//  RepeatsStep (plan-023 M3 T3.7) — wizard step 4
// =========================================================================

interface RepeatsStepProps {
  t: ReturnType<typeof useTranslation>["t"];
  mode: RepeatMode;
  setMode: (m: RepeatMode) => void;
  weekdays: Set<Weekday>;
  setWeekdays: (s: Set<Weekday>) => void;
  monthDay: number;
  setMonthDay: (n: number) => void;
  time: string;
  setTime: (s: string) => void;
  timezone: string;
  setTimezone: (s: string) => void;
  customRrule: string;
  setCustomRrule: (s: string) => void;
  startsAt: string;
  setStartsAt: (s: string) => void;
  endsMode: RepeatEndsMode;
  setEndsMode: (m: RepeatEndsMode) => void;
  occurrencesRemaining: number;
  setOccurrencesRemaining: (n: number) => void;
  endsAt: string;
  setEndsAt: (s: string) => void;
  derivedRrule: string;
  previewOccurrences: string[] | null;
  previewLoading: boolean;
  previewError: boolean;
}

function RepeatsStep(props: RepeatsStepProps) {
  const { t } = props;
  const { locale } = useTranslation();
  const { timezone } = useTimezone();

  const modes: Array<{ value: RepeatMode; label: string }> = [
    { value: "none", label: t("notifications.admin.compose.repeats.mode.none") },
    { value: "daily", label: t("notifications.admin.compose.repeats.mode.daily") },
    { value: "weekly", label: t("notifications.admin.compose.repeats.mode.weekly") },
    { value: "monthly", label: t("notifications.admin.compose.repeats.mode.monthly") },
    { value: "custom", label: t("notifications.admin.compose.repeats.mode.custom") },
  ];

  function toggleWeekday(day: Weekday) {
    const copy = new Set(props.weekdays);
    if (copy.has(day)) copy.delete(day);
    else copy.add(day);
    props.setWeekdays(copy);
  }

  return (
    <Card data-slot="repeats-step">
      <CardContent className="space-y-5 p-5">
        <div>
          <p className="mb-2 text-sm font-semibold">
            {t("notifications.admin.compose.repeats.title")}
          </p>
          <p className="mb-3 text-xs text-muted-foreground">
            {t("notifications.admin.compose.repeats.subtitle")}
          </p>
          <div className="flex flex-wrap gap-2">
            {modes.map((m) => (
              <Button
                key={m.value}
                size="sm"
                variant={props.mode === m.value ? "default" : "outline"}
                onClick={() => props.setMode(m.value)}
              >
                {m.label}
              </Button>
            ))}
          </div>
        </div>

        {props.mode === "none" ? (
          <p className="text-xs text-muted-foreground">
            {t("notifications.admin.compose.repeats.none_hint")}
          </p>
        ) : (
          <>
            {props.mode === "weekly" ? (
              <div>
                <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                  {t("notifications.admin.compose.repeats.weekdays")}
                </p>
                <div className="flex flex-wrap gap-1">
                  {WEEKDAYS.map((d) => (
                    <Button
                      key={d}
                      size="sm"
                      variant={props.weekdays.has(d) ? "default" : "outline"}
                      onClick={() => toggleWeekday(d)}
                    >
                      {d}
                    </Button>
                  ))}
                </div>
              </div>
            ) : null}

            {props.mode === "monthly" ? (
              <div>
                <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                  {t("notifications.admin.compose.repeats.month_day")}
                </p>
                <Input
                  type="number"
                  min={1}
                  max={31}
                  className="w-24"
                  value={String(props.monthDay)}
                  onChange={(e) =>
                    props.setMonthDay(Math.max(1, Math.min(31, Number(e.target.value) || 1)))
                  }
                />
              </div>
            ) : null}

            {props.mode === "custom" ? (
              <div>
                <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                  {t("notifications.admin.compose.repeats.custom_rrule")}
                </p>
                <Input
                  value={props.customRrule}
                  onChange={(e) => props.setCustomRrule(e.target.value)}
                  placeholder="FREQ=DAILY;BYHOUR=9;BYMINUTE=0"
                  className="font-mono text-xs"
                />
              </div>
            ) : (
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t("notifications.admin.compose.repeats.time")}
                  </p>
                  <Input
                    type="time"
                    value={props.time}
                    onChange={(e) => props.setTime(e.target.value)}
                  />
                </div>
                <div>
                  <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {t("notifications.admin.compose.repeats.timezone")}
                  </p>
                  <Input
                    value={props.timezone}
                    onChange={(e) => props.setTimezone(e.target.value)}
                    placeholder="Asia/Tokyo"
                  />
                </div>
              </div>
            )}

            <div>
              <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {t("notifications.admin.compose.repeats.starts_at")}
              </p>
              <Input
                type="datetime-local"
                value={props.startsAt}
                onChange={(e) => props.setStartsAt(e.target.value)}
              />
            </div>

            <div>
              <p className="mb-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {t("notifications.admin.compose.repeats.ends")}
              </p>
              <div className="flex flex-wrap gap-2">
                {(["never", "after_n", "on_date"] as RepeatEndsMode[]).map((m) => (
                  <Button
                    key={m}
                    size="sm"
                    variant={props.endsMode === m ? "default" : "outline"}
                    onClick={() => props.setEndsMode(m)}
                  >
                    {t(`notifications.admin.compose.repeats.ends_${m}`)}
                  </Button>
                ))}
              </div>
              {props.endsMode === "after_n" ? (
                <Input
                  type="number"
                  min={1}
                  className="mt-2 w-32"
                  value={String(props.occurrencesRemaining)}
                  onChange={(e) =>
                    props.setOccurrencesRemaining(Math.max(1, Number(e.target.value) || 1))
                  }
                />
              ) : null}
              {props.endsMode === "on_date" ? (
                <Input
                  type="datetime-local"
                  className="mt-2"
                  value={props.endsAt}
                  onChange={(e) => props.setEndsAt(e.target.value)}
                />
              ) : null}
            </div>

            <div className="rounded-md border bg-muted/30 p-3 text-xs">
              <p className="mb-1 font-mono break-all">{props.derivedRrule || "—"}</p>
              <p className="mb-2 text-[10px] tracking-wide text-muted-foreground uppercase">
                {t("notifications.admin.compose.repeats.next_5")}
              </p>
              {props.previewLoading ? (
                <Spinner className="size-3" />
              ) : props.previewError ? (
                <p className="text-destructive">
                  {t("notifications.admin.compose.repeats.preview_error")}
                </p>
              ) : props.previewOccurrences && props.previewOccurrences.length > 0 ? (
                <ul className="space-y-0.5 font-mono">
                  {props.previewOccurrences.map((iso) => (
                    <li key={iso}>{formatDateTime(iso, locale, timezone)}</li>
                  ))}
                </ul>
              ) : (
                <p className="text-muted-foreground">
                  {t("notifications.admin.compose.repeats.preview_pending")}
                </p>
              )}
            </div>
          </>
        )}
      </CardContent>
    </Card>
  );
}
