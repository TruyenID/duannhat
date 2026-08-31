"use client";

/**
 * #1674 — tỉ lệ tích điểm MẶC ĐỊNH của brand: `brands.point_earn_amount` +
 * `brands.point_earn_points`, đọc thành MỘT CÂU "<số tiền> = <số điểm>".
 *
 * Vì sao là HAI ô chứ không phải một: mẫu số cố định 1 điểm (hình dạng cũ ở
 * `config/loyalty.php`) không khai được chính sách kiểu "100 yên = 2 điểm" mà
 * không phải quy về phân số.
 *
 * Đây là tầng ② trong ba tầng: **chi nhánh ?? brand ?? mặc định hệ thống**.
 * Màn hình này CHỈ đặt mặc định, nên nó không có lựa chọn chế độ nào cả — hai
 * ô, điền thì đó là mặc định, để trống thì rơi về mặc định hệ thống. Việc
 * "kế thừa hay đặt riêng" là quyết định của CỬA HÀNG và sống ở màn hình cửa
 * hàng; bày lại lựa chọn đó ở đây chỉ làm người dùng tưởng có hai thứ khác
 * nhau để chọn.
 *
 * Hai chỗ dễ hiểu nhầm, đã ghi thẳng lên giao diện:
 *   - **Không có đơn vị tiền ở đây.** Cấu hình ở cấp BRAND, còn đơn vị tiền
 *     nằm ở từng CHI NHÁNH (`shop_order_settings.currency_code`). Chuỗi bán ở
 *     nhiều nước thì đặt mặc định ở đây rồi để chi nhánh khác nước ghi đè —
 *     100 JPY và 100 VND lệch nhau hai bậc độ lớn.
 *   - **Đổi tỉ lệ KHÔNG hồi tố.** Sổ cái điểm là append-only; điểm khách đã
 *     tích giữ nguyên, tỉ lệ mới chỉ áp cho đơn phát sinh sau đó.
 *
 * Gửi lên BE luôn là CẢ CẶP: nửa cặp bị 422 (`required_with` hai chiều).
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
  Spinner,
} from "@godxjp/ui";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface BrandSettingsData {
  point_earn_amount: number | null;
  point_earn_points: number | null;
}

interface BrandSettingsResponse {
  data: BrandSettingsData;
}

// ---------------------------------------------------------------------------
// Query keys
// ---------------------------------------------------------------------------

const brandPointEarnKeys = {
  get: (brandSlug: string) => ["hq", brandSlug, "settings", "brand"] as const,
};

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export interface BrandPointEarnTabProps {
  brandSlug: string;
}

/** Khớp `max:1000000` của validator BE. */
const MAX_POINTS = 1_000_000;
/** Khớp `max:9999999999` của validator BE. */
const MAX_AMOUNT = 9_999_999_999;

export function BrandPointEarnTab({ brandSlug }: BrandPointEarnTabProps) {
  const { t } = useTranslation();
  const qc = useQueryClient();

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: brandPointEarnKeys.get(brandSlug),
    queryFn: () => apiFetch<BrandSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`),
    staleTime: 60 * 1000,
    retry: false,
  });

  const settings = data?.data;

  // Mặc định thật = CẢ HAI cùng có giá trị. Nửa cặp (dữ liệu cũ, hoặc một lần
  // sửa tay trong DB) hiển thị như chưa đặt — đúng với cách BE tính điểm, vì
  // nó bỏ qua tầng nửa cặp và rơi xuống tầng dưới.
  const serverConfigured =
    settings !== undefined &&
    settings.point_earn_amount !== null &&
    settings.point_earn_points !== null;

  const serverAmount = serverConfigured ? String(settings.point_earn_amount) : "";
  const serverPoints = serverConfigured ? String(settings.point_earn_points) : "";

  // `null` = chưa đụng vào → render thẳng giá trị server. Lưu xong thì
  // invalidate query và xoá draft, nên giá trị mới fetch về hiện lên — tránh
  // chuỗi setState-trong-effect.
  const [draft, setDraft] = useState<{ amount: string; points: string } | null>(null);

  const amountRaw = draft?.amount ?? serverAmount;
  const pointsRaw = draft?.points ?? serverPoints;

  const patchDraft = (next: Partial<{ amount: string; points: string }>) =>
    setDraft({ amount: amountRaw, points: pointsRaw, ...next });

  const amount = Number(amountRaw.trim());
  const points = Number(pointsRaw.trim());

  const amountFilled = amountRaw.trim() !== "";
  const pointsFilled = pointsRaw.trim() !== "";
  const bothEmpty = !amountFilled && !pointsFilled;

  const amountValid = amountFilled && Number.isFinite(amount) && amount > 0 && amount <= MAX_AMOUNT;
  const pointsValid =
    pointsFilled && Number.isInteger(points) && points > 0 && points <= MAX_POINTS;

  // Chỉ báo lỗi khi người dùng đã gõ vào — ô trống mà đã đỏ lòm thì đọc như
  // một sự cố, chứ không như một ô chưa điền.
  const amountError =
    amountFilled && !amountValid ? t("hq.brand.settings.point_earn.amount_error") : undefined;
  const pointsError =
    pointsFilled && !pointsValid ? t("hq.brand.settings.point_earn.points_error") : undefined;

  // Nửa cặp không lưu được: BE trả 422, và một brand có tiền mà không có điểm
  // thì không phải một tỉ lệ.
  const halfFilled = amountFilled !== pointsFilled;
  const canSubmit = bothEmpty || (amountValid && pointsValid);

  const isDirty =
    settings !== undefined &&
    draft !== null &&
    (amountRaw !== serverAmount || pointsRaw !== serverPoints);

  const saveMutation = useMutation({
    mutationFn: (body: BrandSettingsData) =>
      apiFetch<BrandSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`, {
        method: "PATCH",
        body: JSON.stringify(body),
      }),
    onSuccess: () => {
      setDraft(null);
      qc.invalidateQueries({ queryKey: brandPointEarnKeys.get(brandSlug) });
      toast.success(t("hq.brand.settings.point_earn.toast_saved"));
    },
    onError: (err) => {
      toast.error(
        err instanceof ApiError && err.body
          ? String((err.body as { message?: string }).message ?? err.message)
          : err instanceof Error
            ? err.message
            : t("hq.brand.settings.point_earn.toast_error")
      );
    },
  });

  // Lỗi tạm thời được retry tại chỗ thay vì sập cả trang — một cài đặt brand
  // không đáng để mất nguyên màn hình.
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
    // Cả cặp, luôn luôn — kể cả khi xoá mặc định.
    saveMutation.mutate(
      bothEmpty
        ? { point_earn_amount: null, point_earn_points: null }
        : { point_earn_amount: amount, point_earn_points: points }
    );
  };

  return (
    <div data-slot="brand-point-earn-tab" className="max-w-xl space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("hq.brand.settings.point_earn.section_title")}
          </CardTitle>
          <CardDescription>{t("hq.brand.settings.point_earn.description")}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-4">
          {isLoading ? (
            <div className="flex items-center gap-2 py-8 text-sm text-muted-foreground">
              <Spinner className="size-3.5" />
              {t("common.loading")}
            </div>
          ) : (
            <>
              <div className="flex flex-wrap items-start gap-2">
                <div className="space-y-1">
                  <Label htmlFor="brand-point-earn-amount" className="text-xs">
                    {t("hq.brand.settings.point_earn.amount_label")}
                  </Label>
                  <Input
                    id="brand-point-earn-amount"
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
                  <Label htmlFor="brand-point-earn-points" className="text-xs">
                    {t("hq.brand.settings.point_earn.points_label")}
                  </Label>
                  <Input
                    id="brand-point-earn-points"
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
                  {t("hq.brand.settings.point_earn.preview", {
                    amount: amountRaw.trim(),
                    points: pointsRaw.trim(),
                  })}
                </p>
              )}

              {/* Nửa cặp: nói thẳng vì sao nút Lưu không bấm được, thay vì để
                  người dùng đoán. */}
              {halfFilled && !amountError && !pointsError && (
                <p className="text-xs text-destructive">
                  {t("hq.brand.settings.point_earn.pair_required")}
                </p>
              )}

              <p className="text-xs text-muted-foreground">
                {t("hq.brand.settings.point_earn.empty_hint")}
              </p>
              <p className="text-xs text-muted-foreground">
                {t("hq.brand.settings.point_earn.currency_hint")}
              </p>
              <p className="text-xs text-muted-foreground">
                {t("hq.brand.settings.point_earn.rounding_hint")}
              </p>
              <p className="text-xs text-muted-foreground">
                {t("hq.brand.settings.point_earn.not_retroactive_hint")}
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
