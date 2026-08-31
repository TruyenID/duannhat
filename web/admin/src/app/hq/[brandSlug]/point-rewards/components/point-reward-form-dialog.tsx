"use client";

/**
 * PointRewardFormDialog — tạo/sửa một phần thưởng đổi điểm (#1514).
 *
 * Theo khuôn `FaqFormDialog`: nội dung đa ngữ qua `<Input translatable />`,
 * chặn đóng khi form đang dở, lỗi 422 hiện ngay dưới ô nhập.
 *
 * Hai chỗ dễ hiểu nhầm, đã ghi thẳng lên giao diện:
 *   - **Tồn kho để trống = KHÔNG GIỚI HẠN**, khác hẳn nhập `0` (tạo trước,
 *     chưa mở bán).
 *   - **Điều kiện phục vụ chỉ để hiển thị** cho khách. Không tầng nào cưỡng
 *     chế nó lúc thanh toán, nên đừng dùng nó như một hàng rào (BR-PR07).
 *
 * Ảnh upload NGAY khi chọn file (vào kho tạm 24h), nhưng chỉ thành vĩnh viễn
 * khi bấm Lưu — bỏ dở form thì file tạm tự hết hạn, không cần dọn.
 */

import { useRef, useState } from "react";
import Image from "next/image";
import { Gift, Upload, X } from "lucide-react";

import {
  Button,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Switch,
  Textarea,
} from "@godxjp/ui";
import type { TranslatableValue } from "@godxjp/ui";
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@godxjp/ui";

import { ApiError } from "@/lib/api";
import { useCreatePointReward, useUpdatePointReward } from "@/hooks/api/use-point-rewards";
import {
  pointRewardService,
  type CreatePointRewardInput,
  type PointReward,
  type PointRewardServiceCondition,
  type UpdatePointRewardInput,
} from "@/services/point-reward-service";
import { useTranslation } from "@/providers/app-provider";
import { SUPPORTED_LOCALES, emptyLocaleMap } from "@/types/models/payload-helpers";
import { toast } from "sonner";

/** Trần của `point_reward_translations.name` (string 255). */
const NAME_MAX = 255;
const ACCEPT = "image/jpeg,image/png,image/webp";
/** Khớp `maxSize: 5120` trong schema YAML. */
const MAX_BYTES = 5 * 1024 * 1024;

export interface PointRewardFormDialogProps {
  brandSlug: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Phần thưởng đang sửa; null/undefined = chế độ tạo mới. */
  reward?: PointReward | null;
}

interface FormState {
  name: Record<string, string>;
  description: Record<string, string>;
  cost_points: string;
  discount_type: "fixed" | "percent";
  discount_value: string;
  max_discount_cap: string;
  min_order_subtotal: string;
  valid_days: string;
  /** Chuỗi rỗng = không giới hạn. */
  stock_quantity: string;
  service_condition: PointRewardServiceCondition;
  is_active: boolean;
  sort_order: string;
  image_url: string | null;
  image_file_id: string | null;
  /** Ảnh có bị đụng tới trong phiên này không — quyết định có gửi khoá hay không. */
  image_touched: boolean;
}

function emptyForm(): FormState {
  return {
    name: emptyLocaleMap(),
    description: emptyLocaleMap(),
    cost_points: "",
    discount_type: "fixed",
    discount_value: "",
    max_discount_cap: "",
    min_order_subtotal: "0",
    valid_days: "30",
    stock_quantity: "",
    service_condition: "both",
    is_active: true,
    sort_order: "0",
    image_url: null,
    image_file_id: null,
    image_touched: false,
  };
}

function hydrateForm(reward: PointReward): FormState {
  const name = emptyLocaleMap();
  const description = emptyLocaleMap();

  for (const locale of SUPPORTED_LOCALES) {
    name[locale] = reward.translations?.[locale]?.name ?? "";
    description[locale] = reward.translations?.[locale]?.description ?? "";
  }

  return {
    name,
    description,
    cost_points: String(reward.cost_points),
    discount_type: reward.discount_type,
    discount_value: String(reward.discount_value ?? ""),
    max_discount_cap: reward.max_discount_cap === null ? "" : String(reward.max_discount_cap),
    min_order_subtotal: String(reward.min_order_subtotal ?? "0"),
    valid_days: String(reward.valid_days),
    stock_quantity: reward.stock_quantity === null ? "" : String(reward.stock_quantity),
    service_condition: reward.service_condition,
    is_active: reward.is_active,
    sort_order: String(reward.sort_order),
    image_url: reward.image_url,
    image_file_id: reward.image_file_id,
    image_touched: false,
  };
}

export function PointRewardFormDialog({
  brandSlug,
  open,
  onOpenChange,
  reward,
}: PointRewardFormDialogProps) {
  const { t } = useTranslation();
  const isEdit = !!reward;
  const fileInputRef = useRef<HTMLInputElement>(null);

  const [form, setForm] = useState<FormState>(emptyForm);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);
  const [dirty, setDirty] = useState(false);
  const [uploading, setUploading] = useState(false);

  const createMutation = useCreatePointReward(brandSlug);
  const updateMutation = useUpdatePointReward(brandSlug);
  const isPending = createMutation.isPending || updateMutation.isPending;

  // Re-hydrate mỗi lần mở (và khi đổi dòng đang sửa) bằng pattern "chỉnh state
  // lúc render khi prop đổi" — tránh chuỗi setState-trong-effect.
  const hydrationKey = open ? `${reward?.id ?? "create"}` : null;
  const [prevHydrationKey, setPrevHydrationKey] = useState<string | null>(null);
  if (hydrationKey !== prevHydrationKey) {
    setPrevHydrationKey(hydrationKey);
    if (hydrationKey !== null) {
      setForm(reward ? hydrateForm(reward) : emptyForm());
      setDirty(false);
    }
    setFieldErrors({});
  }

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setDirty(true);
    setForm((prev) => ({ ...prev, [key]: value }));
    if (fieldErrors[key as string]) {
      setFieldErrors((prev) => {
        const next = { ...prev };
        delete next[key as string];
        return next;
      });
    }
  }

  function requestClose() {
    if (dirty && !isPending) {
      setConfirmExitOpen(true);
    } else {
      onOpenChange(false);
    }
  }

  async function handleFilePick(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = ""; // cho phép chọn lại cùng một file
    if (!file) return;

    if (file.size > MAX_BYTES) {
      toast.error(t("hq.point_rewards.error.image_too_large"));
      return;
    }

    setUploading(true);
    try {
      const res = await pointRewardService.uploadImage(file);
      setDirty(true);
      setForm((prev) => ({
        ...prev,
        image_url: res.data.url,
        image_file_id: res.data.id,
        image_touched: true,
      }));
    } catch {
      toast.error(t("hq.point_rewards.error.image_upload_failed"));
    } finally {
      setUploading(false);
    }
  }

  function removeImage() {
    setDirty(true);
    setForm((prev) => ({
      ...prev,
      image_url: null,
      image_file_id: null,
      image_touched: true,
    }));
  }

  const hasAnyName = SUPPORTED_LOCALES.some((locale) => form.name[locale]?.trim());

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFieldErrors({});

    if (!hasAnyName) {
      setFieldErrors({ "ja.name": [t("hq.point_rewards.error.name_required")] });
      return;
    }

    const payload: CreatePointRewardInput = {
      cost_points: Number(form.cost_points),
      discount_type: form.discount_type,
      discount_value: Number(form.discount_value),
      // BR-PR04 — trần chỉ có nghĩa với percent; gửi kèm khi fixed là 422.
      max_discount_cap:
        form.discount_type === "percent" && form.max_discount_cap.trim() !== ""
          ? Number(form.max_discount_cap)
          : null,
      min_order_subtotal: Number(form.min_order_subtotal || 0),
      valid_days: Number(form.valid_days || 30),
      // Ô trống = không giới hạn ⇒ null, KHÔNG phải 0.
      stock_quantity: form.stock_quantity.trim() === "" ? null : Number(form.stock_quantity),
      service_condition: form.service_condition,
      is_active: form.is_active,
      sort_order: Number(form.sort_order || 0),
    };

    // Chỉ gửi `image_file_id` khi ảnh thực sự bị đụng tới. Gửi luôn giá trị cũ
    // sẽ bắt backend sync lại file mỗi lần lưu, mà `syncFiles` có nhánh xoá.
    if (form.image_touched) {
      payload.image_file_id = form.image_file_id;
    }

    for (const locale of SUPPORTED_LOCALES) {
      const name = form.name[locale]?.trim() ?? "";
      const description = form.description[locale]?.trim() ?? "";

      if (name === "" && description === "") continue;

      payload[locale] = {
        ...(name !== "" ? { name } : {}),
        ...(description !== "" ? { description } : {}),
      };
    }

    try {
      if (isEdit && reward) {
        await updateMutation.mutateAsync({ id: reward.id, data: payload as UpdatePointRewardInput });
      } else {
        await createMutation.mutateAsync(payload);
      }
      onOpenChange(false);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { errors?: Record<string, string[]> };
        if (body.errors) setFieldErrors(body.errors);
      }
    }
  }

  return (
    <>
      <Dialog
        open={open}
        onOpenChange={(next) => {
          if (next) {
            onOpenChange(true);
          } else {
            requestClose();
          }
        }}
      >
        <DialogContent className="flex max-h-[90vh] flex-col gap-0 sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>
              {isEdit
                ? t("hq.point_rewards.dialog.edit_title")
                : t("hq.point_rewards.dialog.create_title")}
            </DialogTitle>
            <DialogDescription>
              {isEdit
                ? t("hq.point_rewards.dialog.edit_desc")
                : t("hq.point_rewards.dialog.create_desc")}
            </DialogDescription>
          </DialogHeader>

          <form
            onSubmit={handleSubmit}
            className="flex flex-1 flex-col gap-3 overflow-y-auto px-1 py-3 text-sm"
          >
            <Field
              label={t("hq.point_rewards.field.name")}
              required
              error={
                fieldErrors["ja.name"]?.[0] ??
                fieldErrors["en.name"]?.[0] ??
                fieldErrors["vi.name"]?.[0]
              }
            >
              <Input
                translatable
                value={form.name as TranslatableValue}
                onChange={(value) => update("name", value as Record<string, string>)}
                maxLength={NAME_MAX}
                placeholder={t("hq.point_rewards.field.name_placeholder")}
              />
            </Field>

            <Field label={t("hq.point_rewards.field.description")}>
              <Textarea
                translatable
                value={form.description as TranslatableValue}
                onChange={(value) => update("description", value as Record<string, string>)}
                rows={2}
                placeholder={t("hq.point_rewards.field.description_placeholder")}
              />
            </Field>

            {/* Ảnh thẻ */}
            <Field label={t("hq.point_rewards.field.image")}>
              <div className="flex items-center gap-3">
                <div className="relative aspect-[4/3] w-24 shrink-0 overflow-hidden rounded-md border bg-muted">
                  {form.image_url ? (
                    <Image
                      src={form.image_url}
                      alt=""
                      fill
                      sizes="96px"
                      className="object-cover"
                      unoptimized
                    />
                  ) : (
                    <div className="flex h-full w-full items-center justify-center">
                      <Gift className="size-6 text-muted-foreground/40" />
                    </div>
                  )}
                </div>

                <div className="flex flex-col gap-1.5">
                  <input
                    ref={fileInputRef}
                    type="file"
                    accept={ACCEPT}
                    className="hidden"
                    onChange={handleFilePick}
                  />
                  <div className="flex gap-2">
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      className="h-7 gap-1 text-xs"
                      disabled={uploading || isPending}
                      onClick={() => fileInputRef.current?.click()}
                    >
                      {uploading ? <Spinner className="size-3" /> : <Upload className="size-3" />}
                      {t("hq.point_rewards.action.upload_image")}
                    </Button>
                    {form.image_url && (
                      <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        className="h-7 gap-1 text-xs"
                        disabled={uploading || isPending}
                        onClick={removeImage}
                      >
                        <X className="size-3" />
                        {t("common.remove")}
                      </Button>
                    )}
                  </div>
                  <span className="text-[11px] text-muted-foreground">
                    {t("hq.point_rewards.field.image_hint")}
                  </span>
                </div>
              </div>
            </Field>

            <div className="grid grid-cols-2 gap-3">
              <Field
                label={t("hq.point_rewards.field.cost_points")}
                required
                error={fieldErrors["cost_points"]?.[0]}
              >
                <Input
                  type="number"
                  min={1}
                  value={form.cost_points}
                  onChange={(e) => update("cost_points", e.target.value)}
                />
              </Field>

              <Field label={t("hq.point_rewards.field.service_condition")}>
                <Select
                  value={form.service_condition}
                  onValueChange={(v) =>
                    update("service_condition", v as PointRewardServiceCondition)
                  }
                >
                  <SelectTrigger className="h-8 text-xs">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="both">{t("hq.point_rewards.condition.both")}</SelectItem>
                    <SelectItem value="dine_in">
                      {t("hq.point_rewards.condition.dine_in")}
                    </SelectItem>
                    <SelectItem value="takeaway">
                      {t("hq.point_rewards.condition.takeaway")}
                    </SelectItem>
                  </SelectContent>
                </Select>
                <span className="text-[11px] text-muted-foreground">
                  {t("hq.point_rewards.field.service_condition_hint")}
                </span>
              </Field>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <Field label={t("hq.point_rewards.field.discount_type")}>
                <Select
                  value={form.discount_type}
                  onValueChange={(v) => update("discount_type", v as "fixed" | "percent")}
                >
                  <SelectTrigger className="h-8 text-xs">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="fixed">{t("hq.point_rewards.discount.fixed")}</SelectItem>
                    <SelectItem value="percent">
                      {t("hq.point_rewards.discount.percent")}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </Field>

              <Field
                label={t("hq.point_rewards.field.discount_value")}
                required
                error={fieldErrors["discount_value"]?.[0]}
              >
                <Input
                  type="number"
                  min={0}
                  step="0.01"
                  value={form.discount_value}
                  onChange={(e) => update("discount_value", e.target.value)}
                />
              </Field>
            </div>

            <div className="grid grid-cols-2 gap-3">
              {/* Trần giảm giá chỉ hiện với percent — BR-PR04. Hiện luôn cả
                  với fixed thì người nhập điền vào rồi ăn 422. */}
              {form.discount_type === "percent" && (
                <Field
                  label={t("hq.point_rewards.field.max_discount_cap")}
                  error={fieldErrors["max_discount_cap"]?.[0]}
                >
                  <Input
                    type="number"
                    min={0}
                    step="0.01"
                    value={form.max_discount_cap}
                    onChange={(e) => update("max_discount_cap", e.target.value)}
                  />
                </Field>
              )}

              <Field label={t("hq.point_rewards.field.min_order_subtotal")}>
                <Input
                  type="number"
                  min={0}
                  step="0.01"
                  value={form.min_order_subtotal}
                  onChange={(e) => update("min_order_subtotal", e.target.value)}
                />
              </Field>
            </div>

            <div className="grid grid-cols-3 gap-3">
              <Field
                label={t("hq.point_rewards.field.stock_quantity")}
                error={fieldErrors["stock_quantity"]?.[0]}
              >
                <Input
                  type="number"
                  min={0}
                  value={form.stock_quantity}
                  placeholder={t("hq.point_rewards.field.stock_unlimited")}
                  onChange={(e) => update("stock_quantity", e.target.value)}
                />
                <span className="text-[11px] text-muted-foreground">
                  {t("hq.point_rewards.field.stock_hint")}
                </span>
              </Field>

              <Field label={t("hq.point_rewards.field.valid_days")}>
                <Input
                  type="number"
                  min={1}
                  value={form.valid_days}
                  onChange={(e) => update("valid_days", e.target.value)}
                />
              </Field>

              <Field label={t("hq.point_rewards.field.sort_order")}>
                <Input
                  type="number"
                  value={form.sort_order}
                  onChange={(e) => update("sort_order", e.target.value)}
                />
              </Field>
            </div>

            <div className="flex items-center gap-2 pt-1">
              <Switch
                checked={form.is_active}
                onCheckedChange={(v) => update("is_active", v)}
                id="pr-active"
              />
              <label htmlFor="pr-active" className="text-xs font-medium">
                {t("hq.point_rewards.field.is_active")}
              </label>
            </div>
          </form>

          <DialogFooter>
            <Button type="button" variant="outline" size="sm" onClick={requestClose}>
              {t("common.cancel")}
            </Button>
            <Button
              type="button"
              size="sm"
              disabled={isPending || uploading}
              onClick={handleSubmit}
            >
              {isPending && <Spinner className="mr-1 size-3" />}
              {t("common.save")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={confirmExitOpen} onOpenChange={setConfirmExitOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("common.discard_changes")}</AlertDialogTitle>
            <AlertDialogDescription>{t("common.discard_changes_desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              onClick={() => {
                setConfirmExitOpen(false);
                onOpenChange(false);
              }}
            >
              {t("common.discard")}
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
  children,
}: {
  label: string;
  required?: boolean;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1">
      <label className="text-xs font-medium text-muted-foreground">
        {label}
        {required && <span className="ml-0.5 text-destructive">*</span>}
      </label>
      {children}
      {error && <span className="text-xs text-destructive">{error}</span>}
    </div>
  );
}
