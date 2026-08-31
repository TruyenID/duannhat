"use client";

/**
 * #1674 — ghi đè tỉ lệ tích điểm cho riêng cửa hàng này.
 *
 * Ba tầng, tầng hẹp nhất thắng: **chi nhánh ?? brand ?? mặc định hệ thống**
 * (mặc định tra theo `shop_order_settings.currency_code`). Cùng khuôn với
 * `cart_timeout_minutes` ở tab bên cạnh.
 *
 * Tầng chi nhánh là tầng ĐÚNG cho một chuỗi bán ở nhiều nước: đơn vị tiền sống
 * ở cửa hàng, nên cửa hàng VN của một brand Nhật đặt tỉ lệ của mình ở đây thay
 * vì kéo cả brand lệch theo.
 *
 * Màn hình luôn nói rõ giá trị ĐANG CÓ HIỆU LỰC, kể cả khi đang kế thừa — một
 * ô trống không cho người quản lý biết khách của họ thực sự tích được bao
 * nhiêu điểm.
 *
 * Gửi lên BE luôn là CẢ CẶP: nửa cặp bị 422.
 */

import { useState } from "react";
import { useQueryClient, useQuery, useMutation } from "@tanstack/react-query";
import { toast } from "sonner";

import { apiFetch, ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";

import {
  Button,
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
  CardContent,
  Input,
  Label,
  RadioGroup,
  RadioGroupItem,
  Spinner,
} from "@godxjp/ui";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface BranchPointEarnData {
  point_earn_amount: number | null;
  point_earn_points: number | null;
  hq_brand_point_earn_amount: number | null;
  hq_brand_point_earn_points: number | null;
  effective_point_earn_amount: number | null;
  effective_point_earn_points: number | null;
}

interface BranchSettingsResponse {
  data: BranchPointEarnData;
}

type Mode = "inherit" | "custom";

const branchSettingsKeys = {
  get: (shopSlug: string) => ["shop", shopSlug, "settings", "branch"] as const,
};

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export interface PointEarnTabProps {
  shopSlug: string;
}

/** Khớp validator BE. */
const MAX_POINTS = 1_000_000;
const MAX_AMOUNT = 9_999_999_999;

export function PointEarnTab({ shopSlug }: PointEarnTabProps) {
  const { t } = useTranslation();
  const qc = useQueryClient();

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: branchSettingsKeys.get(shopSlug),
    queryFn: () => apiFetch<BranchSettingsResponse>(`/api/v1/shops/${shopSlug}/settings/branch`),
    staleTime: 60 * 1000,
    retry: false,
  });

  const settings = data?.data;

  // Ghi đè có hiệu lực = CẢ HAI vế có giá trị. Nửa cặp hiển thị như đang kế
  // thừa, đúng với cách backend tính điểm.
  const hasOverride =
    settings !== undefined &&
    settings.point_earn_amount !== null &&
    settings.point_earn_points !== null;

  const serverMode: Mode = hasOverride ? "custom" : "inherit";
  const serverAmount = hasOverride ? String(settings.point_earn_amount) : "";
  const serverPoints = hasOverride ? String(settings.point_earn_points) : "";

  const [draft, setDraft] = useState<{ mode: Mode; amount: string; points: string } | null>(null);

  const mode = draft?.mode ?? serverMode;
  const amountRaw = draft?.amount ?? serverAmount;
  const pointsRaw = draft?.points ?? serverPoints;

  const patchDraft = (next: Partial<{ mode: Mode; amount: string; points: string }>) =>
    setDraft({ mode, amount: amountRaw, points: pointsRaw, ...next });

  const amount = Number(amountRaw.trim());
  const points = Number(pointsRaw.trim());

  const amountValid =
    amountRaw.trim() !== "" && Number.isFinite(amount) && amount > 0 && amount <= MAX_AMOUNT;
  const pointsValid =
    pointsRaw.trim() !== "" && Number.isInteger(points) && points > 0 && points <= MAX_POINTS;

  const amountError =
    mode === "custom" && amountRaw.trim() !== "" && !amountValid
      ? t("shop.settings.point_earn.amount_error")
      : undefined;
  const pointsError =
    mode === "custom" && pointsRaw.trim() !== "" && !pointsValid
      ? t("shop.settings.point_earn.points_error")
      : undefined;

  const canSubmit = mode === "inherit" || (amountValid && pointsValid);

  const isDirty =
    settings !== undefined &&
    draft !== null &&
    (mode !== serverMode ||
      (mode === "custom" && (amountRaw !== serverAmount || pointsRaw !== serverPoints)));

  const saveMutation = useMutation({
    mutationFn: (body: { point_earn_amount: number | null; point_earn_points: number | null }) =>
      apiFetch<BranchSettingsResponse>(`/api/v1/shops/${shopSlug}/settings/branch`, {
        method: "PATCH",
        body: JSON.stringify(body),
      }),
    onSuccess: () => {
      setDraft(null);
      qc.invalidateQueries({ queryKey: branchSettingsKeys.get(shopSlug) });
      toast.success(t("shop.settings.point_earn.toast_saved"));
    },
    onError: (err) => {
      toast.error(
        err instanceof ApiError && err.body
          ? String((err.body as { message?: string }).message ?? err.message)
          : err instanceof Error
            ? err.message
            : t("shop.settings.point_earn.toast_error")
      );
    },
  });

  if (error) {
    return (
      <div className="flex h-full flex-col items-center justify-center gap-3 text-sm text-muted-foreground">
        <p>{t("common.error_loading")}</p>
        <Button variant="outline" size="sm" onClick={() => refetch()}>
          {t("common.retry")}
        </Button>
      </div>
    );
  }

  const handleSave = () => {
    if (!canSubmit) return;
    saveMutation.mutate(
      mode === "custom"
        ? { point_earn_amount: amount, point_earn_points: points }
        : { point_earn_amount: null, point_earn_points: null }
    );
  };

  // Cả brand lẫn chi nhánh đều để trống ⇒ đang chạy mặc định hệ thống, và giá
  // trị đó phụ thuộc đơn vị tiền nên backend không trả về một con số.
  const brandRate =
    settings?.hq_brand_point_earn_amount !== null &&
    settings?.hq_brand_point_earn_points !== null &&
    settings !== undefined
      ? t("shop.settings.point_earn.preview", {
          amount: String(settings.hq_brand_point_earn_amount),
          points: String(settings.hq_brand_point_earn_points),
        })
      : t("shop.settings.point_earn.system_default");

  const effectiveRate =
    settings?.effective_point_earn_amount !== null &&
    settings?.effective_point_earn_points !== null &&
    settings !== undefined
      ? t("shop.settings.point_earn.preview", {
          amount: String(settings.effective_point_earn_amount),
          points: String(settings.effective_point_earn_points),
        })
      : t("shop.settings.point_earn.system_default");

  return (
    <div data-slot="shop-point-earn-tab" className="max-w-xl space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t("shop.settings.point_earn.section_title")}</CardTitle>
          <CardDescription>{t("shop.settings.point_earn.description")}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-4">
          {isLoading ? (
            <div className="flex items-center gap-2 py-8 text-sm text-muted-foreground">
              <Spinner className="size-3.5" />
              {t("common.loading")}
            </div>
          ) : (
            <>
              <RadioGroup
                value={mode}
                onValueChange={(v) => patchDraft({ mode: v as Mode })}
                className="space-y-2"
              >
                <div className="flex items-start gap-3">
                  <RadioGroupItem
                    value="inherit"
                    id="shop-point-earn-inherit"
                    className="mt-0.5 shrink-0"
                  />
                  <Label
                    htmlFor="shop-point-earn-inherit"
                    className="flex-1 cursor-pointer leading-snug"
                  >
                    {t("shop.settings.point_earn.inherit_radio", { rate: brandRate })}
                  </Label>
                </div>

                <div className="flex items-start gap-3">
                  <RadioGroupItem
                    value="custom"
                    id="shop-point-earn-custom"
                    className="mt-0.5 shrink-0"
                  />
                  <Label
                    htmlFor="shop-point-earn-custom"
                    className="flex-1 cursor-pointer leading-snug"
                  >
                    {t("shop.settings.point_earn.custom_radio")}
                  </Label>
                </div>
              </RadioGroup>

              {mode === "custom" && (
                <div className="ml-6 space-y-3">
                  <div className="flex flex-wrap items-start gap-2">
                    <div className="space-y-1">
                      <Label htmlFor="shop-point-earn-amount" className="text-xs">
                        {t("shop.settings.point_earn.amount_label")}
                      </Label>
                      <Input
                        id="shop-point-earn-amount"
                        type="number"
                        min={0}
                        step="0.01"
                        inputMode="decimal"
                        value={amountRaw}
                        onChange={(e) => patchDraft({ amount: e.target.value })}
                        error={amountError}
                        className="w-32"
                      />
                    </div>

                    <span className="pt-7 text-sm text-muted-foreground">=</span>

                    <div className="space-y-1">
                      <Label htmlFor="shop-point-earn-points" className="text-xs">
                        {t("shop.settings.point_earn.points_label")}
                      </Label>
                      <Input
                        id="shop-point-earn-points"
                        type="number"
                        min={1}
                        step={1}
                        inputMode="numeric"
                        value={pointsRaw}
                        onChange={(e) => patchDraft({ points: e.target.value })}
                        error={pointsError}
                        className="w-32"
                      />
                    </div>
                  </div>

                  {amountValid && pointsValid && (
                    <p className="text-sm font-medium">
                      {t("shop.settings.point_earn.preview", {
                        amount: amountRaw.trim(),
                        points: pointsRaw.trim(),
                      })}
                    </p>
                  )}
                </div>
              )}

              {/* Giá trị đang chạy — kể cả khi đang kế thừa. Ô trống một mình
                  không cho người quản lý biết khách tích được bao nhiêu. */}
              <p className="rounded-md bg-muted px-3 py-2 text-xs">
                {t("shop.settings.point_earn.effective_hint", { rate: effectiveRate })}
              </p>

              <p className="text-xs text-muted-foreground">
                {t("shop.settings.point_earn.rounding_hint")}
              </p>
              <p className="text-xs text-muted-foreground">
                {t("shop.settings.point_earn.not_retroactive_hint")}
              </p>
            </>
          )}

          <div className="flex items-center gap-2 border-t pt-4">
            <Button
              onClick={handleSave}
              disabled={saveMutation.isPending || isLoading || !isDirty || !canSubmit}
              className="gap-2"
            >
              {saveMutation.isPending && <Spinner className="size-3.5" />}
              {t("common.save")}
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
