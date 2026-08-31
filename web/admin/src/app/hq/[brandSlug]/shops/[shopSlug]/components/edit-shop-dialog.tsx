"use client";

import { useEffect, useState } from "react";
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Button,
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
  Spinner,
  Textarea,
} from "@godxjp/ui";
import { ApiError } from "@/lib/api";
import { useUpdateShop } from "@/hooks/api/use-shops";
import { BusinessHoursTimePicker } from "@/components/shared/business-hours-time-picker";
import { ShopImageUpload } from "@/components/shared/shop-image-upload";
import {
  type ShopDetail,
  type UpdateShopInput,
  type WeeklyHoursMap,
} from "@/services/shop-service";
import { useTranslation } from "@/providers/app-provider";
import { toast } from "sonner";

export interface EditShopDialogProps {
  brandSlug: string;
  shop: ShopDetail;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

type DayKey = "mon" | "tue" | "wed" | "thu" | "fri" | "sat" | "sun";
const DAYS: DayKey[] = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"];

// Common timezones for restaurant businesses
const TIMEZONES = [
  { value: "Asia/Tokyo", label: "Asia/Tokyo (GMT+9)" },
  { value: "Asia/Ho_Chi_Minh", label: "Asia/Ho_Chi_Minh (GMT+7)" },
  { value: "Asia/Bangkok", label: "Asia/Bangkok (GMT+7)" },
  { value: "Asia/Singapore", label: "Asia/Singapore (GMT+8)" },
  { value: "Asia/Hong_Kong", label: "Asia/Hong_Kong (GMT+8)" },
  { value: "Asia/Shanghai", label: "Asia/Shanghai (GMT+8)" },
  { value: "Asia/Seoul", label: "Asia/Seoul (GMT+9)" },
  { value: "America/Los_Angeles", label: "America/Los_Angeles (GMT-8)" },
  { value: "America/New_York", label: "America/New_York (GMT-5)" },
  { value: "Europe/London", label: "Europe/London (GMT+0)" },
  { value: "UTC", label: "UTC (GMT+0)" },
] as const;

interface DayState {
  open: string;
  close: string;
  closed: boolean;
}

interface FormState {
  timezone: string;
  address: string;
  phone: string;
  logo: string | null;
  img_branches: string | null;
  banner_desktop: string | null;
  banner_tablet: string | null;
  banner_mobile: string | null;
  seat_capacity: string;
  business_hours: string;
  weekly_hours: Record<DayKey, DayState>;
}

const DEFAULT_DAY: DayState = { open: "11:00", close: "22:00", closed: false };

function hydrateWeekly(wh: WeeklyHoursMap | null | undefined): Record<DayKey, DayState> {
  const out = {} as Record<DayKey, DayState>;
  for (const d of DAYS) {
    const entry = wh?.[d];
    if (!entry) {
      out[d] = { ...DEFAULT_DAY };
    } else if (entry.closed) {
      out[d] = { ...DEFAULT_DAY, closed: true };
    } else {
      out[d] = {
        open: entry.open ?? DEFAULT_DAY.open,
        close: entry.close ?? DEFAULT_DAY.close,
        closed: false,
      };
    }
  }
  return out;
}

function hydrate(shop: ShopDetail): FormState {
  return {
    timezone: shop.timezone ?? "Asia/Tokyo",
    address: shop.address ?? "",
    phone: shop.phone ?? "",
    logo: shop.logo ?? null,
    img_branches: shop.img_branches ?? null,
    banner_desktop: shop.banner_desktop ?? null,
    banner_tablet: shop.banner_tablet ?? null,
    banner_mobile: shop.banner_mobile ?? null,
    seat_capacity: shop.seat_capacity != null ? String(shop.seat_capacity) : "",
    business_hours: shop.business_hours ?? "",
    weekly_hours: hydrateWeekly(shop.weekly_hours),
  };
}

function serializeWeekly(state: Record<DayKey, DayState>): WeeklyHoursMap {
  const out: WeeklyHoursMap = {};
  for (const d of DAYS) {
    const day = state[d];
    if (day.closed) out[d] = { closed: true };
    else if (day.open || day.close) out[d] = { open: day.open, close: day.close };
  }
  return out;
}

export function EditShopDialog({ brandSlug, shop, open, onOpenChange }: EditShopDialogProps) {
  const { t } = useTranslation();
  const [form, setForm] = useState<FormState>(() => hydrate(shop));
  const [initialForm, setInitialForm] = useState<FormState>(() => hydrate(shop));
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);
  // True while a logo/banner upload is in flight — gates submit so the URL is
  // resolved before the shop is saved.
  const [uploading, setUploading] = useState(false);
  const updateMutation = useUpdateShop(brandSlug, shop.id, shop.slug);

  useEffect(() => {
    if (open) {
      const next = hydrate(shop);
      setForm(next);
      setInitialForm(next);
      setFieldErrors({});
    }
  }, [open, shop]);

  // Dirty check against the hydrated baseline — drives the unsaved-changes
  // guard on close (Cancel / X / Esc / overlay).
  const isDirty = JSON.stringify(form) !== JSON.stringify(initialForm);

  function requestClose() {
    if (isDirty && !updateMutation.isPending) {
      setConfirmExitOpen(true);
    } else {
      onOpenChange(false);
    }
  }

  function setDay<K extends keyof DayState>(day: DayKey, key: K, value: DayState[K]) {
    setForm((prev) => ({
      ...prev,
      weekly_hours: { ...prev.weekly_hours, [day]: { ...prev.weekly_hours[day], [key]: value } },
    }));
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFieldErrors({});

    const seatRaw = form.seat_capacity.trim();
    const seat = seatRaw === "" ? null : Number.parseInt(seatRaw, 10);
    if (seat !== null && (!Number.isFinite(seat) || seat < 0)) {
      setFieldErrors({ seat_capacity: [t("hq.shops.form.seat_capacity_invalid")] });
      return;
    }

    const payload: UpdateShopInput = {
      timezone: form.timezone || null,
      address: form.address.trim() || null,
      phone: form.phone.trim() || null,
      logo: form.logo,
      // #936 — không còn ô nhập banner legacy, nhưng vẫn gửi lại giá trị cũ
      // để shop chưa upload banner theo breakpoint không bị mất ảnh đang dùng.
      img_branches: form.img_branches,
      banner_desktop: form.banner_desktop,
      banner_tablet: form.banner_tablet,
      banner_mobile: form.banner_mobile,
      seat_capacity: seat,
      business_hours: form.business_hours.trim() || null,
      weekly_hours: serializeWeekly(form.weekly_hours),
    };

    try {
      await updateMutation.mutateAsync(payload);
      toast.success(t("toast.shop.updated"));
      onOpenChange(false);
    } catch (err) {
      if (err instanceof ApiError) {
        const body = err.body as { errors?: Record<string, string[]>; message?: string };

        // 422 Validation errors - show field errors
        if (err.status === 422 && body.errors) {
          setFieldErrors(body.errors);
          toast.error(t("toast.shop.validation_failed"));
          return;
        }

        // Other errors - show specific message from backend
        const errorMessage = body.message || err.message || t("toast.shop.update_failed");
        toast.error(errorMessage);
        return;
      }

      // Unknown error
      toast.error(t("toast.shop.update_failed"));
    }
  }

  return (
    <>
      <Dialog
        open={open}
        onOpenChange={(next) => {
          // Intercept close attempts so a dirty form prompts for confirmation;
          // opening (next === true) always passes through.
          if (next) {
            onOpenChange(true);
          } else {
            requestClose();
          }
        }}
      >
        <DialogContent
          data-slot="edit-shop-dialog"
          className="max-h-[90vh] overflow-y-auto sm:max-w-[500px]"
        >
          <DialogHeader>
            <DialogTitle>{t("hq.shops.edit.title")}</DialogTitle>
            <DialogDescription>{t("hq.shops.edit.description")}</DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSubmit} className="flex flex-col gap-2.5 py-2 text-sm">
            <Field label={t("hq.shops.form.timezone")} error={fieldErrors.timezone?.[0]} optional>
              <Select
                value={form.timezone}
                onValueChange={(v) => setForm({ ...form, timezone: v })}
              >
                <SelectTrigger className="h-9 w-full">
                  <SelectValue placeholder={t("hq.shops.form.select_timezone")} />
                </SelectTrigger>
                <SelectContent>
                  {TIMEZONES.map((tz) => (
                    <SelectItem key={tz.value} value={tz.value}>
                      {tz.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>

            <Field label={t("common.address")} error={fieldErrors.address?.[0]} optional>
              <Textarea
                className="field-sizing-fixed text-xs"
                value={form.address}
                onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) =>
                  setForm((p) => ({ ...p, address: e.target.value }))
                }
                maxLength={500}
                rows={2}
                placeholder={t("hq.shops.form.address_placeholder")}
              />
            </Field>

            <ShopImageUpload
              brandSlug={brandSlug}
              type="logo"
              value={form.logo}
              onChange={(url) => setForm((p) => ({ ...p, logo: url }))}
              label={t("hq.shops.form.logo")}
              hint={t("hq.shops.form.logo_hint")}
              disabled={updateMutation.isPending}
              onUploadingChange={setUploading}
            />

            <ShopImageUpload
              brandSlug={brandSlug}
              type="banner_desktop"
              value={form.banner_desktop}
              onChange={(url) => setForm((p) => ({ ...p, banner_desktop: url }))}
              label={t("hq.shops.form.banner_desktop")}
              hint={t("hq.shops.form.banner_desktop_hint")}
              disabled={updateMutation.isPending}
              onUploadingChange={setUploading}
            />

            <ShopImageUpload
              brandSlug={brandSlug}
              type="banner_tablet"
              value={form.banner_tablet}
              onChange={(url) => setForm((p) => ({ ...p, banner_tablet: url }))}
              label={t("hq.shops.form.banner_tablet")}
              hint={t("hq.shops.form.banner_tablet_hint")}
              disabled={updateMutation.isPending}
              onUploadingChange={setUploading}
            />

            <ShopImageUpload
              brandSlug={brandSlug}
              type="banner_mobile"
              value={form.banner_mobile}
              onChange={(url) => setForm((p) => ({ ...p, banner_mobile: url }))}
              label={t("hq.shops.form.banner_mobile")}
              hint={t("hq.shops.form.banner_mobile_hint")}
              disabled={updateMutation.isPending}
              onUploadingChange={setUploading}
            />

            <div className="grid grid-cols-2 gap-2.5">
              <Field label={t("common.phone")} error={fieldErrors.phone?.[0]} optional>
                <Input
                  type="tel"
                  inputMode="tel"
                  className="h-8 text-xs"
                  value={form.phone}
                  onChange={(e: React.ChangeEvent<HTMLInputElement>) => {
                    // Format quốc tế E.164: `+` + tối đa 15 digit (country code + number).
                    // - Strip mọi ký tự ngoài: digits, `+`, space, dash, ngoặc đơn
                    // - Chỉ giữ 1 `+` ở đầu
                    // - Tự động prepend `+` nếu user gõ digit đầu tiên mà chưa có
                    // - Giới hạn cứng 15 digits (chuẩn E.164) — quá thì REJECT (giữ giá trị cũ)
                    let cleaned = e.target.value.replace(/[^\d+\-\s()]/g, "");
                    if (cleaned.includes("+")) {
                      const startsWithPlus = cleaned.startsWith("+");
                      cleaned = cleaned.replace(/\+/g, "");
                      if (startsWithPlus) cleaned = "+" + cleaned;
                    }
                    const digitCount = cleaned.replace(/\D/g, "").length;
                    if (digitCount > 15) return; // chặn nhập thêm
                    if (digitCount > 0 && !cleaned.startsWith("+")) {
                      cleaned = "+" + cleaned;
                    }
                    setForm((p) => ({ ...p, phone: cleaned }));
                  }}
                  maxLength={30}
                  placeholder="+81 3-1234-5678"
                />
              </Field>

              <Field
                label={t("hq.shops.form.seat_capacity")}
                error={fieldErrors.seat_capacity?.[0]}
                optional
              >
                <Input
                  className="h-8 text-xs"
                  type="number"
                  min={0}
                  value={form.seat_capacity}
                  onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                    setForm((p) => ({ ...p, seat_capacity: e.target.value }))
                  }
                  placeholder="0"
                />
              </Field>
            </div>

            <Field
              label={t("hq.shops.form.business_hours")}
              error={fieldErrors.business_hours?.[0]}
              hint={t("hq.shops.form.business_hours_hint")}
              optional
            >
              {/* Component shared có internal state riêng cho open/close — fix
                bug cũ: regex strict parse mỗi render khiến giờ bị reset khi
                user mới nhập 1 nửa pattern "HH:MM - HH:MM". */}
              <BusinessHoursTimePicker
                value={form.business_hours}
                onChange={(v) => setForm((p) => ({ ...p, business_hours: v }))}
              />
            </Field>

            <div className="flex flex-col gap-1">
              <Label className="text-[11px] font-medium">{t("hq.shops.form.weekly_hours")}</Label>
              <div className="divide-y rounded-md border">
                {DAYS.map((d) => (
                  <div key={d} className="flex items-center gap-1.5 px-2 py-1">
                    <span className="w-9 text-[11px] font-medium">{t(`common.day.${d}`)}</span>
                    <Checkbox
                      className="size-3.5"
                      checked={form.weekly_hours[d].closed}
                      onCheckedChange={(v) => setDay(d, "closed", v === true)}
                    />
                    <span className="text-[10px] text-muted-foreground">
                      {t("hq.shops.form.day_closed")}
                    </span>
                    <div className="ml-auto flex items-center gap-1">
                      <Input
                        type="time"
                        className="h-6 w-20 text-[11px]"
                        value={form.weekly_hours[d].open}
                        disabled={form.weekly_hours[d].closed}
                        onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                          setDay(d, "open", e.target.value)
                        }
                      />
                      <span className="text-[11px] text-muted-foreground">–</span>
                      <Input
                        type="time"
                        className="h-6 w-20 text-[11px]"
                        value={form.weekly_hours[d].close}
                        disabled={form.weekly_hours[d].closed}
                        onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                          setDay(d, "close", e.target.value)
                        }
                      />
                    </div>
                  </div>
                ))}
              </div>
            </div>

            <DialogFooter className="pt-1.5">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={requestClose}
                disabled={updateMutation.isPending}
              >
                {t("common.cancel")}
              </Button>
              <Button type="submit" size="sm" disabled={updateMutation.isPending || uploading}>
                {updateMutation.isPending && <Spinner className="mr-1.5 size-3.5" />}
                {t("common.save")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Unsaved-changes guard — confirm before discarding edits on close. */}
      <AlertDialog open={confirmExitOpen} onOpenChange={setConfirmExitOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.products.unsaved.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("hq.products.unsaved.desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={updateMutation.isPending}>
              {t("hq.products.unsaved.continue_editing")}
            </AlertDialogCancel>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => {
                setConfirmExitOpen(false);
                onOpenChange(false);
              }}
              disabled={updateMutation.isPending}
            >
              {t("hq.products.unsaved.exit_without_saving")}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}

function Field({
  label,
  error,
  hint,
  optional = false,
  children,
}: {
  label: string;
  error?: string;
  hint?: string;
  optional?: boolean;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-col gap-0.5">
      <Label className="text-[11px] font-medium">
        {label}
        {optional && <span className="ml-1 font-normal text-muted-foreground">(optional)</span>}
      </Label>
      {children}
      {hint && !error && <p className="text-[10px] text-muted-foreground">{hint}</p>}
      {error && <p className="text-[11px] text-red-500">{error}</p>}
    </div>
  );
}
