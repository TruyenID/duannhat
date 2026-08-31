"use client";

import { X } from "lucide-react";
import { useEffect } from "react";
import { useForm, useWatch } from "react-hook-form";
import {
  Button,
  Checkbox,
  Dialog,
  DialogClose,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  TimePicker,
} from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import type { MenuSchedule } from "@/types/models/MenuSchedule";
import { daysOfWeekToLabels, toHHMM } from "@/lib/menuSchedule";

// =========================================================================
//  Types
// =========================================================================

export interface ScheduleFormDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** When set, dialog is in edit mode and pre-fills fields. */
  schedule?: MenuSchedule | null;
  onSubmit: (data: FormValues) => Promise<void>;
  isSubmitting?: boolean;
}

// Mon-first display order (ISO week); values must still match backend strings.
const DAYS_DISPLAY_ORDER = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"] as const;
const ALL_DAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"] as const;

/** How a row repeats (#1979). Must match MenuScheduleRecurrenceEnum on the API. */
export type RecurrenceKind = "Weekly" | "Monthly" | "SpecificDates";

export const RECURRENCE_KINDS: RecurrenceKind[] = ["Weekly", "Monthly", "SpecificDates"];

export interface FormValues {
  start_time: string;
  end_time: string;
  days: string[];
  is_active: boolean;
  // Optional calendar-date bounds (TC-MSCH-103). Empty string = no bound.
  start_date: string;
  end_date: string;
  /**
   * ONE kind per row (#1979). "Both a monthly rule and some one-off dates" is
   * expressed as two schedule rows, which already OR together — not as a
   * combining rule inside one row, which would have to answer what "Monday AND
   * the 15th" means.
   */
  recurrence_kind: RecurrenceKind;
  /** Day numbers 1–31, for Monthly. */
  days_of_month: number[];
  /** `YYYY-MM-DD`, for SpecificDates. */
  specific_dates: string[];
}

/** Bitmask (bit0 = the 1st) ⇄ ascending day numbers. */
export function daysOfMonthToList(mask: number): number[] {
  const days: number[] = [];
  for (let bit = 0; bit <= 30; bit++) {
    if ((mask >> bit) & 1) days.push(bit + 1);
  }
  return days;
}

export function listToDaysOfMonth(days: number[]): number {
  return days.reduce((mask, day) => mask | (1 << (day - 1)), 0);
}

// =========================================================================
//  Component
// =========================================================================

export function ScheduleFormDialog({
  open,
  onOpenChange,
  schedule,
  onSubmit,
  isSubmitting,
}: ScheduleFormDialogProps) {
  const { t } = useTranslation();
  const isEdit = !!schedule;

  const form = useForm<FormValues>({
    defaultValues: {
      start_time: schedule ? toHHMM(schedule.start_time) : "",
      end_time: schedule ? toHHMM(schedule.end_time) : "",
      days: schedule ? daysOfWeekToLabels(schedule.days_of_week) : [],
      // New rows start ACTIVE. The old default was false, which meant clicking
      // "Add schedule" and saving switched the menu OFF everywhere: a menu with
      // NO schedule rows is treated as always-on, but one paused row is enough
      // to make it "scheduled, with nothing active". Nothing warned about it.
      is_active: schedule?.is_active ?? true,
      start_date: schedule?.start_date ?? "",
      end_date: schedule?.end_date ?? "",
      recurrence_kind: (schedule?.recurrence_kind as RecurrenceKind) ?? "Weekly",
      days_of_month: daysOfMonthToList(schedule?.days_of_month ?? 0),
      specific_dates: schedule?.specific_dates ?? [],
    },
  });

  useEffect(() => {
    if (open) {
      form.reset({
        start_time: schedule ? toHHMM(schedule.start_time) : "",
        end_time: schedule ? toHHMM(schedule.end_time) : "",
        days: schedule ? daysOfWeekToLabels(schedule.days_of_week) : [],
        is_active: schedule?.is_active ?? true,
        // These four were missing, so reopening the dialog reset them to
        // undefined and an edit that touched only the times silently cleared
        // the row's dates.
        start_date: schedule?.start_date ?? "",
        end_date: schedule?.end_date ?? "",
        recurrence_kind: (schedule?.recurrence_kind as RecurrenceKind) ?? "Weekly",
        days_of_month: daysOfMonthToList(schedule?.days_of_month ?? 0),
        specific_dates: schedule?.specific_dates ?? [],
      });
    }
  }, [open, schedule, form]);

  const selectedDays = useWatch({ control: form.control, name: "days" }) ?? [];
  const allSelected = selectedDays.length === ALL_DAYS.length;
  const kind = useWatch({ control: form.control, name: "recurrence_kind" }) ?? "Weekly";

  function handleToggleAll() {
    form.setValue("days", allSelected ? [] : [...ALL_DAYS], { shouldValidate: true });
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="sm:max-w-lg"
        aria-describedby={undefined}
        onOpenAutoFocus={(e) => e.preventDefault()}
      >
        <DialogHeader>
          <DialogTitle className="text-base">
            {isEdit
              ? t("hq.menus.schedules.dialog_edit_title")
              : t("hq.menus.schedules.dialog_create_title")}
          </DialogTitle>
        </DialogHeader>

        <Form {...form}>
          <form
            onSubmit={form.handleSubmit(onSubmit)}
            className="flex flex-col gap-4"
            id="schedule-form"
          >
            {/* Time range — side by side */}
            <div className="grid grid-cols-2 gap-3">
              <FormField
                control={form.control}
                name="start_time"
                rules={{ required: true }}
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>
                      {t("hq.menus.schedules.field_start_time")}
                      <span className="ml-0.5 text-destructive">*</span>
                    </FormLabel>
                    <FormControl>
                      <TimePicker value={field.value} onChange={field.onChange} format24h />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="end_time"
                rules={{
                  required: true,
                  validate: (val) => {
                    const start = form.getValues("start_time");
                    if (start && val && val <= start) {
                      return t("hq.menus.schedules.error_end_before_start");
                    }
                    return true;
                  },
                }}
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>
                      {t("hq.menus.schedules.field_end_time")}
                      <span className="ml-0.5 text-destructive">*</span>
                    </FormLabel>
                    <FormControl>
                      <TimePicker value={field.value} onChange={field.onChange} format24h />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>

            {/* Recurrence kind (#1979) — one kind per row */}
            <FormField
              control={form.control}
              name="recurrence_kind"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t("hq.menus.schedules.field_recurrence")}</FormLabel>
                  <div className="grid grid-cols-3 gap-1.5">
                    {RECURRENCE_KINDS.map((k) => (
                      <button
                        key={k}
                        type="button"
                        onClick={() => field.onChange(k)}
                        className={[
                          "flex h-9 items-center justify-center rounded-md border px-1 text-xs font-medium transition-colors select-none",
                          field.value === k
                            ? "border-primary bg-primary text-primary-foreground"
                            : "border-input bg-background text-foreground hover:bg-muted",
                        ].join(" ")}
                      >
                        {t(`hq.menus.schedules.recurrence_${k.toLowerCase()}`)}
                      </button>
                    ))}
                  </div>
                  <p className="text-[11px] text-muted-foreground">
                    {t("hq.menus.schedules.recurrence_hint")}
                  </p>
                </FormItem>
              )}
            />

            {/* Days of week — toggle chips, Mon→Sun. Weekly rows only (#1979). */}
            {kind === "Weekly" && (
            <FormField
              control={form.control}
              name="days"
              rules={{
                validate: (val) => val.length > 0 || t("hq.menus.schedules.error_no_days"),
              }}
              render={({ field }) => (
                <FormItem>
                  <div className="flex items-center justify-between">
                    <FormLabel>
                      {t("hq.menus.schedules.field_days")}
                      <span className="ml-0.5 text-destructive">*</span>
                    </FormLabel>
                    <button
                      type="button"
                      onClick={handleToggleAll}
                      className="text-xs text-primary hover:underline"
                    >
                      {allSelected
                        ? t("hq.menus.schedules.deselect_all")
                        : t("hq.menus.schedules.select_all")}
                    </button>
                  </div>
                  <div className="grid grid-cols-7 gap-1.5">
                    {DAYS_DISPLAY_ORDER.map((day) => {
                      const checked = field.value.includes(day);
                      return (
                        <button
                          key={day}
                          type="button"
                          onClick={() => {
                            const next = checked
                              ? field.value.filter((d: string) => d !== day)
                              : [...field.value, day];
                            field.onChange(next);
                          }}
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
                  <FormMessage />
                </FormItem>
              )}
            />
            )}

            {kind === "Monthly" && (
              <FormField
                control={form.control}
                name="days_of_month"
                rules={{
                  validate: (val) =>
                    kind !== "Monthly" || val.length > 0 || t("hq.menus.schedules.error_no_days_of_month"),
                }}
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>
                      {t("hq.menus.schedules.field_days_of_month")}
                      <span className="ml-0.5 text-destructive">*</span>
                    </FormLabel>
                    <div className="grid grid-cols-7 gap-1.5">
                      {Array.from({ length: 31 }, (_, i) => i + 1).map((day) => {
                        const checked = field.value.includes(day);
                        return (
                          <button
                            key={day}
                            type="button"
                            onClick={() =>
                              field.onChange(
                                checked
                                  ? field.value.filter((d: number) => d !== day)
                                  : [...field.value, day].sort((a, b) => a - b)
                              )
                            }
                            className={[
                              "flex h-9 items-center justify-center rounded-md border text-xs font-medium tabular-nums transition-colors select-none",
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
                    <p className="text-[11px] text-muted-foreground">
                      {t("hq.menus.schedules.days_of_month_hint")}
                    </p>
                    <FormMessage />
                  </FormItem>
                )}
              />
            )}

            {kind === "SpecificDates" && (
              <FormField
                control={form.control}
                name="specific_dates"
                rules={{
                  validate: (val) =>
                    kind !== "SpecificDates" || val.length > 0 || t("hq.menus.schedules.error_no_specific_dates"),
                }}
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>
                      {t("hq.menus.schedules.field_specific_dates")}
                      <span className="ml-0.5 text-destructive">*</span>
                    </FormLabel>
                    <div className="flex flex-wrap gap-1.5">
                      {field.value.map((date: string) => (
                        <button
                          key={date}
                          type="button"
                          onClick={() => field.onChange(field.value.filter((d: string) => d !== date))}
                          className="flex h-8 items-center gap-1 rounded-md border border-primary bg-primary px-2 text-xs font-medium tabular-nums text-primary-foreground"
                          aria-label={t("common.remove")}
                        >
                          {date} <X className="size-3" />
                        </button>
                      ))}
                    </div>
                    <input
                      type="date"
                      // Cleared after each pick so the same input can add the
                      // next date — a value left behind reads as "this date is
                      // selected" when it is only "this date was typed".
                      value=""
                      onChange={(e) => {
                        const picked = e.target.value;
                        if (picked && !field.value.includes(picked)) {
                          field.onChange([...field.value, picked].sort());
                        }
                      }}
                      className="h-9 w-full rounded-md border border-input bg-background px-2 text-sm tabular-nums focus:ring-1 focus:ring-ring focus:outline-none"
                    />
                    <p className="text-[11px] text-muted-foreground">
                      {t("hq.menus.schedules.specific_dates_hint")}
                    </p>
                    <FormMessage />
                  </FormItem>
                )}
              />
            )}

            {/* Optional calendar-date range (TC-MSCH-103) */}
            <div className="grid grid-cols-2 gap-3">
              <FormField
                control={form.control}
                name="start_date"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t("hq.menus.schedules.field_start_date")}</FormLabel>
                    <FormControl>
                      <Input type="date" value={field.value} onChange={field.onChange} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="end_date"
                rules={{
                  validate: (val) => {
                    const start = form.getValues("start_date");
                    if (start && val && val < start) {
                      return t("hq.menus.schedules.error_end_date_before_start");
                    }
                    return true;
                  },
                }}
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t("hq.menus.schedules.field_end_date")}</FormLabel>
                    <FormControl>
                      <Input type="date" value={field.value} onChange={field.onChange} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </div>
            <p className="-mt-2 text-xs text-muted-foreground">
              {t("hq.menus.schedules.date_range_hint")}
            </p>

            {/* Status — Select replacing Switch */}
            <FormField
              control={form.control}
              name="is_active"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t("hq.menus.schedules.field_active")}</FormLabel>
                  <Select
                    value={field.value ? "active" : "inactive"}
                    onValueChange={(v) => field.onChange(v === "active")}
                  >
                    <FormControl>
                      <SelectTrigger className="h-9">
                        <SelectValue />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      <SelectItem value="inactive">{t("common.inactive")}</SelectItem>
                      <SelectItem value="active">{t("common.active")}</SelectItem>
                    </SelectContent>
                  </Select>
                  <FormMessage />
                </FormItem>
              )}
            />
          </form>
        </Form>

        <DialogFooter>
          <DialogClose asChild>
            <Button variant="outline" type="button" disabled={isSubmitting}>
              {t("common.cancel")}
            </Button>
          </DialogClose>
          <Button type="submit" form="schedule-form" disabled={isSubmitting}>
            {isSubmitting && <Spinner className="mr-1.5 size-3.5" />}
            {isEdit ? t("common.save") : t("hq.menus.schedules.add")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
