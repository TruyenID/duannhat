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
import { toResourceSlug } from "@/lib/option-slug";
import { useCreateShop } from "@/hooks/api/use-shops";
import { BusinessHoursTimePicker } from "@/components/shared/business-hours-time-picker";
import { ShopImageUpload } from "@/components/shared/shop-image-upload";
import { type CreateShopInput, type WeeklyHoursMap } from "@/services/shop-service";
import { useTranslation } from "@/providers/app-provider";
import { toast } from "sonner";

export interface CreateShopDialogProps {
  brandSlug: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

type DayKey = "mon" | "tue" | "wed" | "thu" | "fri" | "sat" | "sun";
const DAYS: DayKey[] = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"];

interface DayState {
  open: string;
  close: string;
  closed: boolean;
}

interface FormState {
  name: string;
  slug: string;
  timezone: string;
  currency: string;
  locale: string;
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

function emptyWeekly(): Record<DayKey, DayState> {
  const out = {} as Record<DayKey, DayState>;
  for (const d of DAYS) out[d] = { ...DEFAULT_DAY };
  return out;
}

const EMPTY: FormState = {
  name: "",
  slug: "",
  timezone: "Asia/Tokyo",
  currency: "JPY",
  locale: "ja",
  address: "",
  phone: "",
  logo: null,
  img_branches: null,
  banner_desktop: null,
  banner_tablet: null,
  banner_mobile: null,
  seat_capacity: "",
  business_hours: "",
  weekly_hours: emptyWeekly(),
};

function serializeWeekly(state: Record<DayKey, DayState>): WeeklyHoursMap {
  const out: WeeklyHoursMap = {};
  for (const d of DAYS) {
    const day = state[d];
    if (day.closed) out[d] = { closed: true };
    else if (day.open || day.close) out[d] = { open: day.open, close: day.close };
  }
  return out;
}

// Shop slug is `required` AND `regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/`
// (HQ/StoreShopRequest.php:21-28), so a Japanese shop name that slugified to ""
// failed create outright. `toResourceSlug` guarantees a non-empty, regex-valid
// `shop-<hash>` — note the HYPHEN: an underscore is rejected by that regex.
function slugify(raw: string): string {
  return toResourceSlug(raw, "shop");
}

export function CreateShopDialog({ brandSlug, open, onOpenChange }: CreateShopDialogProps) {
  const { t } = useTranslation();
  const [form, setForm] = useState<FormState>(EMPTY);
  const [slugTouched, setSlugTouched] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);
  // True while a logo/banner upload is in flight — gates submit so the URL is
  // resolved before the shop is created.
  const [uploading, setUploading] = useState(false);
  // Tracks user edits — drives the unsaved-changes guard on close. Set by the
  // field helpers (update / setDay); the open effect resets it so opening the
  // dialog never counts as dirty.
  const [dirty, setDirty] = useState(false);
  const createMutation = useCreateShop(brandSlug);

  useEffect(() => {
    if (!open) {
      setFieldErrors({});
      return;
    }
    setForm(EMPTY);
    setSlugTouched(false);
    setFieldErrors({});
    setDirty(false);
  }, [open]);

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setDirty(true);
    setForm((prev) => {
      const next = { ...prev, [key]: value };
      if (key === "name" && typeof value === "string" && !slugTouched) {
        next.slug = slugify(value);
      }
      return next;
    });
    if (fieldErrors[key]) {
      setFieldErrors((prev) => {
        const next = { ...prev };
        delete next[key];
        return next;
      });
    }
  }

  function setDay<K extends keyof DayState>(day: DayKey, key: K, value: DayState[K]) {
    setDirty(true);
    setForm((prev) => ({
      ...prev,
      weekly_hours: { ...prev.weekly_hours, [day]: { ...prev.weekly_hours[day], [key]: value } },
    }));
  }

  // Guards every close path (Cancel button, the X, Esc, overlay click — all
  // route through the Dialog's onOpenChange): confirm before discarding a
  // dirty form, otherwise close immediately.
  function requestClose() {
    if (dirty && !createMutation.isPending) {
      setConfirmExitOpen(true);
    } else {
      onOpenChange(false);
    }
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

    const payload: CreateShopInput = {
      name: form.name.trim(),
      slug: form.slug.trim(),
      timezone: form.timezone,
      currency: form.currency,
      locale: form.locale,
      address: form.address.trim() || null,
      phone: form.phone.trim() || null,
      logo: form.logo,
      // #936 — shop mới chỉ đặt banner theo breakpoint; banner legacy giữ
      // null và chỉ còn tồn tại cho dữ liệu cũ.
      img_branches: form.img_branches,
      banner_desktop: form.banner_desktop,
      banner_tablet: form.banner_tablet,
      banner_mobile: form.banner_mobile,
      seat_capacity: seat,
      business_hours: form.business_hours.trim() || null,
      weekly_hours: serializeWeekly(form.weekly_hours),
    };

    try {
      await createMutation.mutateAsync(payload);
      toast.success(t("toast.shop.created"));
      onOpenChange(false);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { errors?: Record<string, string[]> };
        if (body.errors) {
          setFieldErrors(body.errors);
          return;
        }
      }
      // Shop creation is Console-first: a 401/403/502 carries a specific
      // message (token expired, not org admin, Console unreachable…). Surface
      // it so the failure is diagnosable instead of a generic "create failed".
      const message = err instanceof ApiError ? (err.body as { message?: string })?.message : null;
      toast.error(message || t("toast.shop.create_failed"));
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
          data-slot="create-shop-dialog"
          className="flex max-h-[90vh] flex-col gap-0 sm:max-w-lg"
        >
          <DialogHeader>
            <DialogTitle>{t("hq.shops.create")}</DialogTitle>
            <DialogDescription>{t("hq.shops.form.description")}</DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSubmit} className="flex min-h-0 flex-1 flex-col">
            <div className="flex flex-1 flex-col gap-3 overflow-y-auto px-1 py-3 text-sm">
              <Field label={t("hq.shops.form.name")} required error={fieldErrors.name?.[0]}>
                <Input
                  value={form.name}
                  onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                    update("name", e.target.value)
                  }
                  required
                  maxLength={100}
                  autoFocus
                />
              </Field>

              <Field label={t("hq.shops.form.slug")} required error={fieldErrors.slug?.[0]}>
                <Input
                  value={form.slug}
                  onChange={(e: React.ChangeEvent<HTMLInputElement>) => {
                    setSlugTouched(true);
                    update("slug", e.target.value);
                  }}
                  required
                  maxLength={100}
                  placeholder="e.g. shibuya"
                />
              </Field>

              <Field label={t("hq.shops.form.timezone")} error={fieldErrors.timezone?.[0]}>
                <Select value={form.timezone} onValueChange={(v) => update("timezone", v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="Asia/Tokyo">Asia/Tokyo</SelectItem>
                    <SelectItem value="Asia/Ho_Chi_Minh">Asia/Ho_Chi_Minh</SelectItem>
                    <SelectItem value="UTC">UTC</SelectItem>
                  </SelectContent>
                </Select>
              </Field>

              <Field label={t("hq.shops.form.currency")} error={fieldErrors.currency?.[0]}>
                <Select value={form.currency} onValueChange={(v) => update("currency", v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="JPY">JPY</SelectItem>
                    <SelectItem value="VND">VND</SelectItem>
                    <SelectItem value="USD">USD</SelectItem>
                  </SelectContent>
                </Select>
              </Field>

              <Field label={t("hq.shops.form.locale")} error={fieldErrors.locale?.[0]}>
                <Select value={form.locale} onValueChange={(v) => update("locale", v)}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="ja">日本語 (ja)</SelectItem>
                    <SelectItem value="en">English (en)</SelectItem>
                    <SelectItem value="vi">Tiếng Việt (vi)</SelectItem>
                  </SelectContent>
                </Select>
              </Field>

              <Field label={t("common.address")} error={fieldErrors.address?.[0]}>
                <Textarea
                  value={form.address}
                  onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) =>
                    update("address", e.target.value)
                  }
                  maxLength={500}
                  className="field-sizing-fixed"
                  rows={2}
                />
              </Field>

              <ShopImageUpload
                brandSlug={brandSlug}
                type="logo"
                value={form.logo}
                onChange={(url) => update("logo", url)}
                label={t("hq.shops.form.logo")}
                hint={t("hq.shops.form.logo_hint")}
                disabled={createMutation.isPending}
                onUploadingChange={setUploading}
              />

              <ShopImageUpload
                brandSlug={brandSlug}
                type="banner_desktop"
                value={form.banner_desktop}
                onChange={(url) => update("banner_desktop", url)}
                label={t("hq.shops.form.banner_desktop")}
                hint={t("hq.shops.form.banner_desktop_hint")}
                disabled={createMutation.isPending}
                onUploadingChange={setUploading}
              />

              <ShopImageUpload
                brandSlug={brandSlug}
                type="banner_tablet"
                value={form.banner_tablet}
                onChange={(url) => update("banner_tablet", url)}
                label={t("hq.shops.form.banner_tablet")}
                hint={t("hq.shops.form.banner_tablet_hint")}
                disabled={createMutation.isPending}
                onUploadingChange={setUploading}
              />

              <ShopImageUpload
                brandSlug={brandSlug}
                type="banner_mobile"
                value={form.banner_mobile}
                onChange={(url) => update("banner_mobile", url)}
                label={t("hq.shops.form.banner_mobile")}
                hint={t("hq.shops.form.banner_mobile_hint")}
                disabled={createMutation.isPending}
                onUploadingChange={setUploading}
              />

              <div className="grid grid-cols-2 gap-3">
                <Field label={t("common.phone")} error={fieldErrors.phone?.[0]}>
                  <Input
                    type="tel"
                    inputMode="tel"
                    value={form.phone}
                    onChange={(e: React.ChangeEvent<HTMLInputElement>) => {
                      // Format quốc tế E.164: `+` + tối đa 15 digit. Auto-prepend `+`
                      // khi user gõ digit đầu, cap 15 digits. Xem edit-shop-dialog
                      // để xem comment chi tiết.
                      let cleaned = e.target.value.replace(/[^\d+\-\s()]/g, "");
                      if (cleaned.includes("+")) {
                        const startsWithPlus = cleaned.startsWith("+");
                        cleaned = cleaned.replace(/\+/g, "");
                        if (startsWithPlus) cleaned = "+" + cleaned;
                      }
                      const digitCount = cleaned.replace(/\D/g, "").length;
                      if (digitCount > 15) return;
                      if (digitCount > 0 && !cleaned.startsWith("+")) {
                        cleaned = "+" + cleaned;
                      }
                      update("phone", cleaned);
                    }}
                    maxLength={30}
                    placeholder="+81 3-1234-5678"
                  />
                </Field>

                <Field
                  label={t("hq.shops.form.seat_capacity")}
                  error={fieldErrors.seat_capacity?.[0]}
                >
                  <Input
                    type="number"
                    min={0}
                    value={form.seat_capacity}
                    onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                      update("seat_capacity", e.target.value)
                    }
                    placeholder="0"
                  />
                </Field>
              </div>

              <Field
                label={t("hq.shops.form.business_hours")}
                error={fieldErrors.business_hours?.[0]}
                hint={t("hq.shops.form.business_hours_hint")}
              >
                {/* Component shared có internal state riêng cho open/close — fix
                bug cũ: regex strict parse mỗi render khiến giờ bị reset khi
                user mới nhập 1 nửa pattern "HH:MM - HH:MM". */}
                <BusinessHoursTimePicker
                  value={form.business_hours}
                  onChange={(v) => update("business_hours", v)}
                />
              </Field>

              <div className="flex flex-col gap-1.5">
                <Label className="text-xs font-medium">{t("hq.shops.form.weekly_hours")}</Label>
                <div className="divide-y rounded-md border">
                  {DAYS.map((d) => (
                    <div key={d} className="flex items-center gap-2 px-2 py-1.5">
                      <span className="w-10 text-xs font-medium">{t(`common.day.${d}`)}</span>
                      <Checkbox
                        checked={form.weekly_hours[d].closed}
                        onCheckedChange={(v) => setDay(d, "closed", v === true)}
                      />
                      <span className="text-[11px] text-muted-foreground">
                        {t("hq.shops.form.day_closed")}
                      </span>
                      <div className="ml-auto flex items-center gap-1">
                        <Input
                          type="time"
                          className="h-7 w-24 text-xs"
                          value={form.weekly_hours[d].open}
                          disabled={form.weekly_hours[d].closed}
                          onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                            setDay(d, "open", e.target.value)
                          }
                        />
                        <span className="text-xs text-muted-foreground">–</span>
                        <Input
                          type="time"
                          className="h-7 w-24 text-xs"
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
            </div>

            <DialogFooter className="border-t pt-3">
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={requestClose}
                disabled={createMutation.isPending}
              >
                {t("common.cancel")}
              </Button>
              <Button type="submit" size="sm" disabled={createMutation.isPending || uploading}>
                {createMutation.isPending && <Spinner className="mr-1.5 size-3.5" />}
                {t("common.create")}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Unsaved-changes guard — confirm before discarding a dirty form. */}
      <AlertDialog open={confirmExitOpen} onOpenChange={setConfirmExitOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.products.unsaved.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("hq.products.unsaved.desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={createMutation.isPending}>
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
              disabled={createMutation.isPending}
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
  required,
  error,
  hint,
  children,
}: {
  label: string;
  required?: boolean;
  error?: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1">
      <Label className="text-xs font-medium">
        {label}
        {required && <span className="ml-0.5 text-red-500">*</span>}
      </Label>
      {children}
      {hint && !error && <p className="text-[11px] text-muted-foreground">{hint}</p>}
      {error && <p className="text-xs text-red-500">{error}</p>}
    </div>
  );
}
