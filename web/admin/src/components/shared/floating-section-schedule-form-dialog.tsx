"use client";

import { useEffect } from "react";
import { useForm, useWatch } from "react-hook-form";
import {
  Button,
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
import type { FloatingSectionSchedule } from "@/types/models/FloatingSectionSchedule";
import { daysOfWeekToLabels, toHHMM } from "@/lib/menuSchedule";

// =========================================================================
//  Types
// =========================================================================

export interface FloatingSectionScheduleFormDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** When set, dialog is in edit mode and pre-fills fields. */
  schedule?: FloatingSectionSchedule | null;
  onSubmit: (data: FormValues) => Promise<void>;
  isSubmitting?: boolean;
}

// Mon-first display order (ISO week); values must still match backend strings.
const DAYS_DISPLAY_ORDER = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"] as const;
const ALL_DAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"] as const;

export interface FormValues {
  start_time: string;
  end_time: string;
  days: string[];
  is_active: boolean;
  start_date: string;
  end_date: string;
}

// =========================================================================
//  Component — mirrors menus/[menuId]/schedules/components/schedule-form-dialog.tsx.
//  Shared because both hq/[brandSlug]/floating-sections and
//  shop/[shopSlug]/floating-sections need the identical form.
// =========================================================================

export function FloatingSectionScheduleFormDialog({
  open,
  onOpenChange,
  schedule,
  onSubmit,
  isSubmitting,
}: FloatingSectionScheduleFormDialogProps) {
  const { t } = useTranslation();
  const isEdit = !!schedule;

  const form = useForm<FormValues>({
    defaultValues: {
      start_time: schedule ? toHHMM(schedule.start_time) : "",
      end_time: schedule ? toHHMM(schedule.end_time) : "",
      days: schedule ? daysOfWeekToLabels(schedule.days_of_week) : [],
      is_active: schedule?.is_active ?? false,
      start_date: schedule?.start_date ?? "",
      end_date: schedule?.end_date ?? "",
    },
  });

  useEffect(() => {
    if (open) {
      form.reset({
        start_time: schedule ? toHHMM(schedule.start_time) : "",
        end_time: schedule ? toHHMM(schedule.end_time) : "",
        days: schedule ? daysOfWeekToLabels(schedule.days_of_week) : [],
        is_active: schedule?.is_active ?? false,
        start_date: schedule?.start_date ?? "",
        end_date: schedule?.end_date ?? "",
      });
    }
  }, [open, schedule, form]);

  const selectedDays = useWatch({ control: form.control, name: "days" }) ?? [];
  const allSelected = selectedDays.length === ALL_DAYS.length;

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
              ? t("hq.floating_sections.schedules.dialog_edit_title")
              : t("hq.floating_sections.schedules.dialog_create_title")}
          </DialogTitle>
        </DialogHeader>

        <Form {...form}>
          <form
            onSubmit={form.handleSubmit(onSubmit)}
            className="flex flex-col gap-4"
            id="floating-section-schedule-form"
          >
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
          <Button type="submit" form="floating-section-schedule-form" disabled={isSubmitting}>
            {isSubmitting && <Spinner className="mr-1.5 size-3.5" />}
            {isEdit ? t("common.save") : t("hq.menus.schedules.add")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
