"use client";

/**
 * Plan-023 M3 T3.8 — HQ Notification Schedules page.
 *
 * Lists recurring `NotificationSchedule` rows scoped to the brand,
 * with status badges + next-occurrence column + per-row pause/resume/
 * cancel actions. Cancel respects the 60-second freeze window enforced
 * by the backend canceller; on 422 within_freeze_window we surface the
 * remaining seconds in the dialog instead of a generic error toast.
 *
 * Create UX is intentionally not on this page in v1 — schedules are
 * built from the composer's step 4 wizard (T3.7, deferred). This page
 * is the audit + lifecycle-control surface.
 *
 * Detail Sheet shows the full RRULE, timezone, audience reference,
 * channels, parameters, and next-5-occurrences preview.
 */

import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  Checkbox,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  Skeleton,
  Spinner,
} from "@godxjp/ui";
import {
  AlertCircle,
  CalendarClock,
  Eye,
  FileText,
  Pause,
  Pencil,
  Play,
  Send,
  Users,
  X,
} from "lucide-react";
import { useParams } from "next/navigation";
import { useMemo, useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import {
  useCancelNotificationSchedule,
  useNotificationSchedule,
  useNotificationSchedules,
  usePauseNotificationSchedule,
  useResumeNotificationSchedule,
  useUpdateNotificationSchedule,
} from "@/hooks/api/use-notification-schedules";
import { ApiError } from "@/lib/api";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
import type {
  ChannelCode,
  Priority,
  ScheduleRow,
  ScheduleStatus,
} from "@/services/notification-schedule-service";

export default function SchedulesPage() {
  const { brandSlug } = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  const [statusFilter, setStatusFilter] = useState<ScheduleStatus | "all">("all");
  const [viewing, setViewing] = useState<ScheduleRow | null>(null);
  const [editing, setEditing] = useState<ScheduleRow | null>(null);
  const [confirmCancel, setConfirmCancel] = useState<ScheduleRow | null>(null);
  const [freezeError, setFreezeError] = useState<string | null>(null);

  const { data, isLoading, isError, refetch } = useNotificationSchedules(brandSlug, {
    status: statusFilter === "all" ? undefined : statusFilter,
  });

  const detail = useNotificationSchedule(brandSlug, viewing?.id ?? null);
  const cancelMutation = useCancelNotificationSchedule(brandSlug);
  const pauseMutation = usePauseNotificationSchedule(brandSlug);
  const resumeMutation = useResumeNotificationSchedule(brandSlug);
  const updateMutation = useUpdateNotificationSchedule(brandSlug);

  const rows = useMemo<ScheduleRow[]>(() => data?.data ?? [], [data?.data]);

  const counts = useMemo(() => {
    const acc: Record<ScheduleStatus | "total", number> = {
      total: rows.length,
      active: 0,
      paused: 0,
      completed: 0,
      cancelled: 0,
    };
    rows.forEach((r) => {
      acc[r.status] = (acc[r.status] ?? 0) + 1;
    });
    return acc;
  }, [rows]);

  async function onConfirmCancel() {
    if (!confirmCancel) return;
    setFreezeError(null);
    try {
      await cancelMutation.mutateAsync(confirmCancel.id);
      setConfirmCancel(null);
    } catch (e) {
      if (e instanceof ApiError && e.body?.error === "within_freeze_window") {
        setFreezeError(t("notifications.schedules.error_freeze_window"));
      } else {
        setFreezeError(t("common.error_loading"));
      }
    }
  }

  return (
    <>
      <PageHeader
        title={t("notifications.schedules.title")}
        description={t("notifications.schedules.subtitle")}
      >
        <StatusFilter value={statusFilter} onChange={setStatusFilter} />
      </PageHeader>

      <PageContent>
        {isError ? (
          <Alert variant="destructive" className="mb-4">
            <AlertCircle className="size-4" />
            <AlertDescription className="flex items-center justify-between">
              {t("common.error_loading")}
              <Button variant="outline" size="sm" onClick={() => refetch()}>
                {t("common.retry")}
              </Button>
            </AlertDescription>
          </Alert>
        ) : null}

        {/* Summary strip */}
        {!isLoading && rows.length > 0 ? (
          <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <StatTile
              label={t("notifications.schedules.stat_active")}
              value={counts.active}
              tone="primary"
            />
            <StatTile
              label={t("notifications.schedules.stat_paused")}
              value={counts.paused}
              tone="neutral"
            />
            <StatTile
              label={t("notifications.schedules.stat_completed")}
              value={counts.completed}
              tone="info"
            />
            <StatTile
              label={t("notifications.schedules.stat_cancelled")}
              value={counts.cancelled}
              tone="muted"
            />
          </div>
        ) : null}

        {isLoading ? (
          <ScheduleListSkeleton />
        ) : rows.length === 0 ? (
          <EmptyState />
        ) : (
          <ScheduleList
            rows={rows}
            onView={setViewing}
            onEdit={setEditing}
            onPause={(s) => pauseMutation.mutate(s.id)}
            onResume={(s) => resumeMutation.mutate(s.id)}
            onCancel={setConfirmCancel}
          />
        )}
      </PageContent>

      {/* Detail Sheet */}
      <Sheet open={viewing !== null} onOpenChange={(open) => !open && setViewing(null)}>
        <SheetContent
          side="right"
          className="flex w-full flex-col gap-0 p-0 sm:max-w-xl"
          data-slot="schedule-detail"
        >
          <div className="flex items-start gap-3 border-b bg-muted/20 px-6 py-4">
            <div className="rounded-lg bg-primary/10 p-2.5">
              <CalendarClock className="size-5 text-primary" />
            </div>
            <div className="flex-1">
              <SheetHeader className="space-y-1 p-0 text-left">
                <SheetTitle className="text-base font-semibold">
                  {t("notifications.schedules.detail.title")}
                </SheetTitle>
                <SheetDescription className="text-xs">
                  {t("notifications.schedules.detail.subtitle")}
                </SheetDescription>
              </SheetHeader>
            </div>
          </div>

          <div className="flex-1 overflow-y-auto px-6 py-5">
            {detail.isLoading ? (
              <div className="space-y-3">
                <Skeleton className="h-6 w-1/2" />
                <Skeleton className="h-4 w-3/4" />
                <Skeleton className="h-4 w-2/3" />
              </div>
            ) : detail.data ? (
              <ScheduleDetail row={detail.data.data} />
            ) : null}
          </div>
        </SheetContent>
      </Sheet>

      {/* Edit Sheet */}
      {editing ? (
        <EditScheduleSheet
          row={editing}
          onClose={() => setEditing(null)}
          onSave={async (payload) => {
            await updateMutation.mutateAsync({ id: editing.id, payload });
            setEditing(null);
          }}
          isSaving={updateMutation.isPending}
        />
      ) : null}

      {/* Cancel confirm */}
      <Dialog
        open={confirmCancel !== null}
        onOpenChange={(open) => {
          if (!open) {
            setConfirmCancel(null);
            setFreezeError(null);
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("notifications.schedules.cancel.title")}</DialogTitle>
            <DialogDescription>
              {t("notifications.schedules.cancel.body", {
                name: confirmCancel?.template_key ?? "",
              })}
            </DialogDescription>
          </DialogHeader>
          {freezeError ? (
            <Alert variant="destructive">
              <AlertCircle className="size-4" />
              <AlertDescription>{freezeError}</AlertDescription>
            </Alert>
          ) : null}
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setConfirmCancel(null);
                setFreezeError(null);
              }}
            >
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              onClick={onConfirmCancel}
              disabled={cancelMutation.isPending}
            >
              {cancelMutation.isPending ? <Spinner className="mr-2 size-3.5" /> : null}
              {t("notifications.schedules.cancel.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

// =========================================================================
//  Subcomponents
// =========================================================================

function StatusFilter({
  value,
  onChange,
}: {
  value: ScheduleStatus | "all";
  onChange: (v: ScheduleStatus | "all") => void;
}) {
  const { t } = useTranslation();
  const options: Array<{ value: ScheduleStatus | "all"; label: string }> = [
    { value: "all", label: t("common.all") },
    { value: "active", label: t("notifications.schedules.stat_active") },
    { value: "paused", label: t("notifications.schedules.stat_paused") },
    { value: "completed", label: t("notifications.schedules.stat_completed") },
    { value: "cancelled", label: t("notifications.schedules.stat_cancelled") },
  ];
  return (
    <div className="flex items-center gap-1">
      {options.map((opt) => (
        <Button
          key={opt.value}
          variant={value === opt.value ? "default" : "outline"}
          size="sm"
          onClick={() => onChange(opt.value)}
        >
          {opt.label}
        </Button>
      ))}
    </div>
  );
}

function ScheduleList({
  rows,
  onView,
  onEdit,
  onPause,
  onResume,
  onCancel,
}: {
  rows: ScheduleRow[];
  onView: (r: ScheduleRow) => void;
  onEdit: (r: ScheduleRow) => void;
  onPause: (r: ScheduleRow) => void;
  onResume: (r: ScheduleRow) => void;
  onCancel: (r: ScheduleRow) => void;
}) {
  return (
    <div className="space-y-3">
      {rows.map((row) => (
        <ScheduleCard
          key={row.id}
          row={row}
          onView={onView}
          onEdit={onEdit}
          onPause={onPause}
          onResume={onResume}
          onCancel={onCancel}
        />
      ))}
    </div>
  );
}

function ScheduleCard({
  row,
  onView,
  onEdit,
  onPause,
  onResume,
  onCancel,
}: {
  row: ScheduleRow;
  onView: (r: ScheduleRow) => void;
  onEdit: (r: ScheduleRow) => void;
  onPause: (r: ScheduleRow) => void;
  onResume: (r: ScheduleRow) => void;
  onCancel: (r: ScheduleRow) => void;
}) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const terminal = row.status === "completed" || row.status === "cancelled";
  return (
    <Card
      data-slot="schedule-card"
      className="cursor-pointer transition-colors hover:bg-muted/40"
      onClick={() => onView(row)}
    >
      <CardContent className="flex items-center gap-4 p-4">
        <CalendarClock className="size-5 text-muted-foreground" />
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <p className="truncate font-medium">{row.template_key}</p>
            <StatusBadge status={row.status} />
          </div>
          <p className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
            <Users className="size-3" />
            <span className="truncate">
              {row.audience_name ?? t("notifications.schedules.audience_missing")}
            </span>
          </p>
          <p className="truncate text-xs text-muted-foreground">
            {row.rrule} · {row.timezone}
          </p>
          {row.next_occurrence_at ? (
            <p className="text-xs text-muted-foreground">
              {t("notifications.schedules.next_fire")}:{" "}
              {formatDateTime(row.next_occurrence_at, locale, timezone)}
            </p>
          ) : null}
        </div>
        <div className="flex items-center gap-1" onClick={(e) => e.stopPropagation()}>
          <Button variant="ghost" size="icon" onClick={() => onView(row)}>
            <Eye className="size-4" />
          </Button>
          {row.status === "active" ? (
            <Button
              variant="ghost"
              size="icon"
              onClick={() => onEdit(row)}
              title={t("notifications.schedules.edit.action")}
              aria-label={t("notifications.schedules.edit.action")}
            >
              <Pencil className="size-4" />
            </Button>
          ) : null}
          {row.status === "active" ? (
            <Button variant="ghost" size="icon" onClick={() => onPause(row)}>
              <Pause className="size-4" />
            </Button>
          ) : row.status === "paused" ? (
            <Button variant="ghost" size="icon" onClick={() => onResume(row)}>
              <Play className="size-4" />
            </Button>
          ) : null}
          {!terminal ? (
            <Button variant="ghost" size="icon" onClick={() => onCancel(row)}>
              <X className="size-4" />
            </Button>
          ) : null}
        </div>
      </CardContent>
    </Card>
  );
}

function ScheduleDetail({ row }: { row: ScheduleRow }) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  return (
    <div className="space-y-4 text-sm">
      <DetailCard
        icon={<FileText className="size-3.5" />}
        title={t("notifications.schedules.detail.section_identity")}
      >
        <DetailRow
          label={t("notifications.schedules.detail.template")}
          value={row.template_key}
          mono
        />
        <DetailRow
          label={t("notifications.schedules.detail.audience")}
          value={row.audience_name ?? t("notifications.schedules.audience_missing")}
        />
      </DetailCard>

      <DetailCard
        icon={<CalendarClock className="size-3.5" />}
        title={t("notifications.schedules.detail.section_recurrence")}
      >
        <DetailRow label={t("notifications.schedules.detail.rrule")} value={row.rrule} mono />
        <DetailRow label={t("notifications.schedules.detail.timezone")} value={row.timezone} />
        <DetailRow
          label={t("notifications.schedules.detail.starts_at")}
          value={row.starts_at ? formatDateTime(row.starts_at, locale, timezone) : "—"}
        />
        <DetailRow
          label={t("notifications.schedules.detail.ends_at")}
          value={row.ends_at ? formatDateTime(row.ends_at, locale, timezone) : "—"}
        />
        <DetailRow
          label={t("notifications.schedules.detail.occurrences_remaining")}
          value={row.occurrences_remaining === null ? "∞" : String(row.occurrences_remaining)}
        />
      </DetailCard>

      <DetailCard
        icon={<Send className="size-3.5" />}
        title={t("notifications.schedules.detail.section_delivery")}
      >
        <DetailRow
          label={t("notifications.schedules.detail.channels")}
          value={row.channels.length ? row.channels.join(", ") : "—"}
          mono
        />
        <DetailRow label={t("notifications.schedules.detail.priority")} value={row.priority} />
      </DetailCard>

      <DetailCard
        icon={<CalendarClock className="size-3.5" />}
        title={t("notifications.schedules.detail.next_5")}
      >
        {row.next_5_occurrences.length === 0 ? (
          <p className="px-4 py-3 text-xs text-muted-foreground italic">
            {t("notifications.schedules.detail.next_5_empty")}
          </p>
        ) : (
          <ol className="divide-y">
            {row.next_5_occurrences.map((iso, idx) => (
              <li key={iso} className="flex items-center gap-3 px-4 py-2.5 text-xs">
                <span className="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[10px] font-semibold text-primary">
                  {idx + 1}
                </span>
                <span className="font-mono">{formatDateTime(iso, locale, timezone)}</span>
              </li>
            ))}
          </ol>
        )}
      </DetailCard>
    </div>
  );
}

function DetailCard({
  icon,
  title,
  children,
}: {
  icon: React.ReactNode;
  title: string;
  children: React.ReactNode;
}) {
  return (
    <div className="rounded-lg border">
      <div className="flex items-center gap-2 border-b bg-muted/30 px-4 py-2">
        <span className="text-muted-foreground">{icon}</span>
        <span className="text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
          {title}
        </span>
      </div>
      <div className="divide-y">{children}</div>
    </div>
  );
}

function DetailRow({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <div className="grid grid-cols-[120px_1fr] items-start gap-3 px-4 py-2.5">
      <span className="text-[11px] tracking-wide text-muted-foreground uppercase">{label}</span>
      <span className={`text-xs break-words ${mono ? "font-mono" : ""}`}>{value}</span>
    </div>
  );
}

function StatusBadge({ status }: { status: ScheduleStatus }) {
  const { t } = useTranslation();
  const tone: Record<ScheduleStatus, "default" | "secondary" | "outline" | "destructive"> = {
    active: "default",
    paused: "secondary",
    completed: "outline",
    cancelled: "destructive",
  };
  return <Badge variant={tone[status]}>{t(`notifications.schedules.status.${status}`)}</Badge>;
}

function StatTile({
  label,
  value,
  tone,
}: {
  label: string;
  value: number;
  tone: "primary" | "neutral" | "info" | "muted";
}) {
  const tones: Record<string, string> = {
    primary: "text-primary",
    neutral: "text-foreground",
    info: "text-blue-600",
    muted: "text-muted-foreground",
  };
  return (
    <Card data-slot="schedule-stat-tile">
      <CardContent className="p-3">
        <p className="text-xs tracking-wide text-muted-foreground uppercase">{label}</p>
        <p className={`text-2xl font-semibold ${tones[tone]}`}>{value}</p>
      </CardContent>
    </Card>
  );
}

function ScheduleListSkeleton() {
  return (
    <div className="space-y-3">
      {[0, 1, 2].map((i) => (
        <Skeleton key={i} className="h-20 w-full" />
      ))}
    </div>
  );
}

function EmptyState() {
  const { t } = useTranslation();
  return (
    <Card data-slot="schedule-empty">
      <CardContent className="p-8 text-center text-sm text-muted-foreground">
        <CalendarClock className="mx-auto mb-3 size-8 text-muted-foreground/60" />
        <p className="font-medium">{t("notifications.schedules.empty.title")}</p>
        <p className="mt-1 text-xs">{t("notifications.schedules.empty.subtitle")}</p>
      </CardContent>
    </Card>
  );
}

const ALL_CHANNELS: ChannelCode[] = ["in_app", "realtime", "email", "push"];
const PRIORITIES: Priority[] = ["low", "normal", "high", "urgent"];

interface EditPayload {
  rrule?: string;
  timezone?: string;
  starts_at?: string;
  priority?: Priority;
  channels?: ChannelCode[];
}

/**
 * Edit Sheet — patches RRULE / timezone / starts_at / priority / channels
 * on an active schedule. Only the diff is sent so untouched fields don't
 * trigger backend re-validation (PATCH semantics).
 */
function EditScheduleSheet({
  row,
  onClose,
  onSave,
  isSaving,
}: {
  row: ScheduleRow;
  onClose: () => void;
  onSave: (payload: EditPayload) => Promise<void>;
  isSaving: boolean;
}) {
  const { t } = useTranslation();
  const [rrule, setRrule] = useState(row.rrule);
  const [timezone, setTimezone] = useState(row.timezone);
  const [startsAt, setStartsAt] = useState(toDateTimeLocal(row.starts_at));
  const [priority, setPriority] = useState<Priority>(row.priority);
  const [channels, setChannels] = useState<ChannelCode[]>(row.channels);
  const [error, setError] = useState<string | null>(null);

  function toggleChannel(c: ChannelCode) {
    setChannels((prev) => (prev.includes(c) ? prev.filter((x) => x !== c) : [...prev, c]));
  }

  async function submit() {
    setError(null);
    const payload: EditPayload = {};
    if (rrule !== row.rrule) payload.rrule = rrule;
    if (timezone !== row.timezone) payload.timezone = timezone;
    const startsIso = startsAt ? new Date(startsAt).toISOString() : null;
    if (startsIso && startsIso !== row.starts_at) payload.starts_at = startsIso;
    if (priority !== row.priority) payload.priority = priority;
    if (!arraysEqual(channels, row.channels)) payload.channels = channels;

    if (channels.length === 0) {
      setError(t("notifications.schedules.edit.error_no_channel"));
      return;
    }

    try {
      await onSave(payload);
    } catch (e) {
      if (e instanceof ApiError) {
        const errs = e.body?.errors as Record<string, string[]> | undefined;
        const first = errs ? Object.values(errs)[0]?.[0] : undefined;
        const message = typeof e.body?.message === "string" ? e.body.message : undefined;
        setError(first ?? message ?? t("common.error_loading"));
      } else {
        setError(t("common.error_loading"));
      }
    }
  }

  return (
    <Sheet open onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        side="right"
        className="flex w-full flex-col gap-0 p-0 sm:max-w-xl"
        data-slot="schedule-edit"
      >
        <div className="flex items-start gap-3 border-b bg-muted/20 px-6 py-4">
          <div className="rounded-lg bg-primary/10 p-2.5">
            <Pencil className="size-5 text-primary" />
          </div>
          <div className="flex-1">
            <SheetHeader className="space-y-1 p-0 text-left">
              <SheetTitle className="text-base font-semibold">
                {t("notifications.schedules.edit.title")}
              </SheetTitle>
              <SheetDescription className="text-xs">
                {t("notifications.schedules.edit.subtitle")}
              </SheetDescription>
            </SheetHeader>
          </div>
        </div>

        <div className="flex-1 space-y-5 overflow-y-auto px-6 py-5">
          <FieldGroup
            title={t("notifications.schedules.edit.section_time")}
            description={t("notifications.schedules.edit.section_time_hint")}
          >
            <div className="space-y-2">
              <Label htmlFor="edit-rrule" className="text-xs font-medium">
                {t("notifications.schedules.detail.rrule")}
              </Label>
              <Input
                id="edit-rrule"
                value={rrule}
                onChange={(e) => setRrule(e.target.value)}
                className="font-mono text-xs"
                placeholder="FREQ=DAILY;BYHOUR=9;BYMINUTE=0"
              />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-2">
                <Label htmlFor="edit-timezone" className="text-xs font-medium">
                  {t("notifications.schedules.detail.timezone")}
                </Label>
                <Input
                  id="edit-timezone"
                  value={timezone}
                  onChange={(e) => setTimezone(e.target.value)}
                  placeholder="Asia/Tokyo"
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="edit-starts-at" className="text-xs font-medium">
                  {t("notifications.schedules.detail.starts_at")}
                </Label>
                <Input
                  id="edit-starts-at"
                  type="datetime-local"
                  value={startsAt}
                  onChange={(e) => setStartsAt(e.target.value)}
                />
              </div>
            </div>
          </FieldGroup>

          <FieldGroup title={t("notifications.schedules.edit.section_delivery")}>
            <div className="space-y-2">
              <Label className="text-xs font-medium">
                {t("notifications.schedules.detail.priority")}
              </Label>
              <Select value={priority} onValueChange={(v) => setPriority(v as Priority)}>
                <SelectTrigger className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {PRIORITIES.map((p) => (
                    <SelectItem key={p} value={p}>
                      {t(`notifications.schedules.priority.${p}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label className="text-xs font-medium">
                {t("notifications.schedules.detail.channels")}
              </Label>
              <div className="grid grid-cols-2 gap-2">
                {ALL_CHANNELS.map((c) => (
                  <label
                    key={c}
                    className="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-xs hover:bg-muted/40"
                  >
                    <Checkbox
                      checked={channels.includes(c)}
                      onCheckedChange={() => toggleChannel(c)}
                    />
                    <span className="font-mono">{c}</span>
                  </label>
                ))}
              </div>
            </div>
          </FieldGroup>

          {error ? (
            <Alert variant="destructive">
              <AlertCircle className="size-4" />
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          ) : null}
        </div>

        <div className="flex items-center justify-end gap-2 border-t bg-background px-6 py-3">
          <Button variant="outline" size="sm" onClick={onClose} disabled={isSaving}>
            {t("common.cancel")}
          </Button>
          <Button size="sm" onClick={submit} disabled={isSaving}>
            {isSaving ? <Spinner className="mr-2 size-3.5" /> : null}
            {t("common.save")}
          </Button>
        </div>
      </SheetContent>
    </Sheet>
  );
}

function FieldGroup({
  title,
  description,
  children,
}: {
  title: string;
  description?: string;
  children: React.ReactNode;
}) {
  return (
    <section className="space-y-3 rounded-lg border p-4">
      <div>
        <h3 className="text-sm font-semibold">{title}</h3>
        {description ? <p className="mt-0.5 text-xs text-muted-foreground">{description}</p> : null}
      </div>
      <div className="space-y-3">{children}</div>
    </section>
  );
}

function toDateTimeLocal(iso: string | null): string {
  if (!iso) return "";
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function arraysEqual<T>(a: T[], b: T[]): boolean {
  if (a.length !== b.length) return false;
  const sortedA = [...a].sort();
  const sortedB = [...b].sort();
  return sortedA.every((v, i) => v === sortedB[i]);
}
