"use client";

import { useState } from "react";
import {
  Alert,
  AlertDescription,
  AlertTitle,
  Badge,
  Button,
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
  Skeleton,
  Spinner,
  StatusBadge,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  TimePicker,
} from "@godxjp/ui";
import { EllipsisVertical, Pencil, Power, RotateCcw } from "lucide-react";
import { useTranslation } from "@/providers/app-provider";
import {
  useShopFloatingSectionSchedules,
  useToggleShopFloatingSectionSchedule,
  useOverrideShopFloatingSectionScheduleTime,
  useResetShopFloatingSectionScheduleTimeOverride,
} from "@/hooks/api/use-shop-floating-sections";
import type { FloatingSectionSchedule } from "@/types/models/FloatingSectionSchedule";
import { daysOfWeekToLabels, labelsToDaysOfWeek, toHHMM, toHHMMSS } from "@/lib/menuSchedule";

const DAYS_DISPLAY_ORDER = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"] as const;
const ALL_DAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"] as const;

// Owns its own local form state, lazily initialized from `schedule` — the
// parent remounts this via `key={schedule.id}` on open, so no effect is
// needed to re-sync state when the target schedule changes.
function EditScheduleTimeDialogContent({
  schedule,
  onSave,
  onCancel,
  isSubmitting,
}: {
  schedule: FloatingSectionSchedule;
  onSave: (startTime: string, endTime: string, days: string[]) => void;
  onCancel: () => void;
  isSubmitting: boolean;
}) {
  const { t } = useTranslation();
  const [startTime, setStartTime] = useState(() => toHHMM(schedule.start_time));
  const [endTime, setEndTime] = useState(() => toHHMM(schedule.end_time));
  const [days, setDays] = useState<string[]>(() => daysOfWeekToLabels(schedule.days_of_week));

  const allSelected = days.length === ALL_DAYS.length;

  function toggleDay(day: string) {
    setDays((prev) => (prev.includes(day) ? prev.filter((d) => d !== day) : [...prev, day]));
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle className="text-base">
          {t("hq.floating_sections.schedules.action_edit_time")}
        </DialogTitle>
      </DialogHeader>

      <div className="flex flex-col gap-4">
        <div className="grid grid-cols-2 gap-3">
          <div className="flex flex-col gap-1.5">
            <label className="text-xs font-medium text-muted-foreground">
              {t("hq.menus.schedules.field_start_time")}
            </label>
            <TimePicker value={startTime} onChange={setStartTime} format24h />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-xs font-medium text-muted-foreground">
              {t("hq.menus.schedules.field_end_time")}
            </label>
            <TimePicker value={endTime} onChange={setEndTime} format24h />
          </div>
        </div>

        <div className="flex flex-col gap-1.5">
          <div className="flex items-center justify-between">
            <label className="text-xs font-medium text-muted-foreground">
              {t("hq.menus.schedules.field_days")}
            </label>
            <button
              type="button"
              onClick={() => setDays(allSelected ? [] : [...ALL_DAYS])}
              className="text-xs text-primary hover:underline"
            >
              {allSelected ? t("hq.menus.schedules.deselect_all") : t("hq.menus.schedules.select_all")}
            </button>
          </div>
          <div className="grid grid-cols-7 gap-1.5">
            {DAYS_DISPLAY_ORDER.map((day) => {
              const checked = days.includes(day);
              return (
                <button
                  key={day}
                  type="button"
                  onClick={() => toggleDay(day)}
                  className={[
                    "flex h-9 items-center justify-center rounded-md border text-xs font-medium transition-colors select-none",
                    checked
                      ? "border-primary bg-primary text-primary-foreground"
                      : "border-input bg-background text-foreground hover:bg-muted",
                  ].join(" ")}
                >
                  {day}
                </button>
              );
            })}
          </div>
        </div>
      </div>

      <DialogFooter>
        <Button variant="outline" size="sm" onClick={onCancel} disabled={isSubmitting}>
          {t("common.cancel")}
        </Button>
        <Button
          size="sm"
          onClick={() => onSave(startTime, endTime, days)}
          disabled={!startTime || !endTime || days.length === 0 || isSubmitting}
        >
          {isSubmitting && <Spinner className="mr-1.5 size-3.5" />}
          {t("common.save")}
        </Button>
      </DialogFooter>
    </>
  );
}

// Shop-side sibling of hq/[brandSlug]/floating-sections/[sectionId]/components/
// schedules-section.tsx — read + toggle + time-override. HQ still owns
// create/delete and the calendar date range; the shop may only override the
// displayed start_time/end_time/days_of_week on its own clone row (survives
// until reset — see FloatingSectionScheduleService::overrideTime).
export function ShopFloatingSectionSchedulesSection({
  shopSlug,
  sectionId,
}: {
  shopSlug: string;
  sectionId: string;
}) {
  const { t } = useTranslation();

  const { data, isLoading, isError, refetch } = useShopFloatingSectionSchedules(
    shopSlug,
    sectionId
  );
  const schedules: FloatingSectionSchedule[] = data?.data ?? [];

  const toggleMutation = useToggleShopFloatingSectionSchedule(shopSlug, sectionId);
  const overrideMutation = useOverrideShopFloatingSectionScheduleTime(shopSlug, sectionId);
  const resetMutation = useResetShopFloatingSectionScheduleTimeOverride(shopSlug, sectionId);

  const [editingSchedule, setEditingSchedule] = useState<FloatingSectionSchedule | null>(null);

  function handleSaveOverride(startTime: string, endTime: string, days: string[]) {
    if (!editingSchedule) return;
    overrideMutation.mutate(
      {
        scheduleId: editingSchedule.id,
        data: {
          start_time: toHHMMSS(startTime),
          end_time: toHHMMSS(endTime),
          days_of_week: labelsToDaysOfWeek(days),
        },
      },
      { onSuccess: () => setEditingSchedule(null) }
    );
  }

  return (
    <div className="px-4 py-3">
      <div className="mb-2.5 flex items-center gap-2">
        <span className="text-sm font-semibold">{t("hq.floating_sections.schedules.tab")}</span>
        {!isLoading && schedules.length > 0 && (
          <Badge variant="outline" className="h-5 text-[10px]">
            {schedules.length}
          </Badge>
        )}
      </div>

      {isLoading ? (
        <div className="space-y-1.5">
          {[1, 2].map((i) => (
            <Skeleton key={i} className="h-9 w-full" />
          ))}
        </div>
      ) : isError ? (
        <Alert variant="destructive" className="py-2">
          <AlertTitle className="text-xs">{t("common.error_loading")}</AlertTitle>
          <AlertDescription>
            <Button
              variant="outline"
              size="sm"
              className="mt-1 h-6 text-xs"
              onClick={() => refetch()}
            >
              {t("common.retry")}
            </Button>
          </AlertDescription>
        </Alert>
      ) : schedules.length === 0 ? (
        <div className="rounded-md border border-dashed px-3 py-3 text-center text-xs text-muted-foreground">
          {t("hq.floating_sections.schedules.empty_desc")}
        </div>
      ) : (
        <div className="rounded-md border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="h-8 w-10 text-xs">{t("shop.menu.detail.no_col")}</TableHead>
                <TableHead className="h-8 text-xs">
                  {t("hq.floating_sections.schedules.col_time_hq")}
                </TableHead>
                <TableHead className="h-8 text-xs">
                  {t("hq.floating_sections.schedules.col_time_shop")}
                </TableHead>
                <TableHead className="h-8 text-xs">
                  {t("hq.floating_sections.schedules.col_days")}
                </TableHead>
                <TableHead className="h-8 w-24 text-xs">{t("common.status")}</TableHead>
                <TableHead className="h-8 w-14 text-right text-xs">{t("common.action")}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {schedules.map((s, idx) => {
                const master = s.masterSchedule;
                return (
                  <TableRow key={s.id}>
                    <TableCell className="py-2 text-xs text-muted-foreground tabular-nums">
                      {idx + 1}
                    </TableCell>
                    <TableCell className="py-2 text-sm tabular-nums text-muted-foreground">
                      {master ? `${master.start_time.slice(0, 5)} – ${master.end_time.slice(0, 5)}` : "—"}
                      {s.start_date || s.end_date ? (
                        <span className="mt-0.5 block text-[10px] text-muted-foreground">
                          {(s.start_date ?? "…") + " → " + (s.end_date ?? "…")}
                        </span>
                      ) : null}
                    </TableCell>
                    <TableCell className="py-2 text-sm tabular-nums">
                      {s.is_time_overridden ? (
                        `${s.start_time.slice(0, 5)} – ${s.end_time.slice(0, 5)}`
                      ) : (
                        <span className="text-muted-foreground">—</span>
                      )}
                    </TableCell>
                    <TableCell className="py-2">
                      <div className="flex flex-wrap gap-1">
                        {s.days_of_week_labels.map((day) => (
                          <Badge key={day} variant="outline" className="h-5 text-[10px]">
                            {day}
                          </Badge>
                        ))}
                      </div>
                    </TableCell>
                    <TableCell className="py-2">
                      <StatusBadge status={s.is_active ? "active" : "inactive"} />
                    </TableCell>
                    <TableCell className="py-2 text-right">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon" className="size-7">
                            <EllipsisVertical className="size-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem onClick={() => setEditingSchedule(s)}>
                            <Pencil className="mr-2 size-3.5" />{" "}
                            {t("hq.floating_sections.schedules.action_edit_time")}
                          </DropdownMenuItem>
                          {s.is_time_overridden && (
                            <DropdownMenuItem
                              onClick={() => resetMutation.mutate(s.id)}
                              disabled={resetMutation.isPending}
                            >
                              <RotateCcw className="mr-2 size-3.5" />{" "}
                              {t("hq.floating_sections.schedules.action_reset_time")}
                            </DropdownMenuItem>
                          )}
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            onClick={() => toggleMutation.mutate(s.id)}
                            disabled={toggleMutation.isPending}
                          >
                            <Power className="mr-2 size-3.5" />
                            {s.is_active ? t("common.deactivate") : t("common.activate")}
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </div>
      )}

      <Dialog
        open={!!editingSchedule}
        onOpenChange={(o) => {
          if (!o && !overrideMutation.isPending) setEditingSchedule(null);
        }}
      >
        <DialogContent className="sm:max-w-md" aria-describedby={undefined}>
          {editingSchedule && (
            <EditScheduleTimeDialogContent
              key={editingSchedule.id}
              schedule={editingSchedule}
              onSave={handleSaveOverride}
              onCancel={() => setEditingSchedule(null)}
              isSubmitting={overrideMutation.isPending}
            />
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
