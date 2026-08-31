"use client";

import { useState } from "react";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiFetch, ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  Input,
  Label,
  Spinner,
} from "@godxjp/ui";

/**
 * Ngưỡng lệch tiền mặt trước khi đối soát BA CHÂN kêu (#2937).
 *
 * ## 0 là giá trị HỢP LỆ, không phải "chưa cấu hình"
 *
 * 0 nghĩa là **báo mọi lệch**, và đó là lựa chọn hợp lệ của brand. Màn hình này
 * KHÔNG được coi ô trống và số 0 là một — backend phân biệt bằng `null` vs `0`,
 * và ép 0 về mặc định là âm thầm cướp mất lựa chọn của họ.
 *
 * ## Vì sao theo brand chứ không một con số cho tất cả
 *
 * Bài học `SettlementAlertService`: một ngưỡng chung sẽ **hoặc câm với quán này
 * hoặc la hét với quán kia**. Một quán bán 50 đơn/ngày và một quán bán 2000
 * đơn/ngày không chịu được cùng con số — và ngưỡng sai chiều nào cũng giết cảnh
 * báo: quá chặt thì người ta tắt, quá lỏng thì nó không bắt được gì.
 */

interface BrandSettingsData {
  cash_variance_tolerance_minor: number;
}

interface BrandSettingsResponse {
  data: BrandSettingsData;
}

const brandSettingsKeys = {
  get: (brandSlug: string) => ["hq", brandSlug, "settings", "brand"] as const,
};

const MIN_MINOR = 0;
const MAX_MINOR = 1_000_000;

export interface BrandCashVarianceTabProps {
  brandSlug: string;
}

export function BrandCashVarianceTab({ brandSlug }: BrandCashVarianceTabProps) {
  const { t } = useTranslation();
  const qc = useQueryClient();

  const { data, isLoading, error } = useQuery({
    queryKey: brandSettingsKeys.get(brandSlug),
    queryFn: () => apiFetch<BrandSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`),
    staleTime: 60 * 1000,
    // Đây là tham số điều khiển cảnh báo TIỀN — hỏng phải thấy ngay, không
    // được thử lại im lặng rồi hiện một con số cũ như thể nó là hiện tại.
    retry: false,
  });

  const settings = data?.data;

  // Bản nháp của người dùng; `null` = chưa gõ gì, hiển thị giá trị máy chủ.
  //
  // SUY RA thay vì đồng bộ bằng `useEffect`: một effect gọi `setState` sẽ chạy
  // lại mỗi lần `settings` đổi tham chiếu và ghi đè thứ người dùng đang gõ dở
  // ngay giữa lượt refetch. Ở một ô điều khiển cảnh báo tiền thì đó là mất dữ
  // liệu người dùng, không chỉ là nháy màn hình.
  const [draft, setDraft] = useState<string | null>(null);
  const raw = draft ?? (settings !== undefined ? String(settings.cash_variance_tolerance_minor) : "");
  const setRaw = setDraft;

  const parsed = (() => {
    const trimmed = raw.trim();
    // Ô TRỐNG ≠ 0. Trống là "chưa nhập gì", và lưu nó thành 0 sẽ bật cảnh báo
    // cho mọi lệch mà người dùng không hề chọn.
    if (trimmed === "") return null;
    const n = Number(trimmed);
    return Number.isInteger(n) ? n : null;
  })();

  const rangeError =
    parsed === null || parsed < MIN_MINOR || parsed > MAX_MINOR
      ? t("hq.brand.settings.cash_variance.range_error")
      : undefined;

  const isDirty = settings !== undefined && parsed !== null && parsed !== settings.cash_variance_tolerance_minor;

  const saveMutation = useMutation({
    mutationFn: (value: number) =>
      apiFetch<BrandSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`, {
        method: "PATCH",
        body: JSON.stringify({ cash_variance_tolerance_minor: value }),
      }),
    onSuccess: () => {
      // Trả nháp về `null` để ô hiển thị lại từ máy chủ — nếu giữ nháp, một
      // lượt lưu hỏng phía sau vẫn hiện số người dùng gõ như thể đã lưu.
      setDraft(null);
      qc.invalidateQueries({ queryKey: brandSettingsKeys.get(brandSlug) });
      toast.success(t("hq.brand.settings.cash_variance.toast_saved"));
    },
    onError: (err) => {
      toast.error(err instanceof ApiError ? err.message : t("common.error"));
    },
  });

  if (isLoading) {
    return (
      <div className="flex justify-center py-12">
        <Spinner />
      </div>
    );
  }

  if (error) {
    return (
      <div className="rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive">
        {error instanceof ApiError ? error.message : t("common.error")}
      </div>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("hq.brand.settings.cash_variance.section_title")}</CardTitle>
        <CardDescription>{t("hq.brand.settings.cash_variance.section_help")}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="space-y-2 max-w-xs">
          <Label htmlFor="cash-variance">{t("hq.brand.settings.cash_variance.field_label")}</Label>
          <Input
            id="cash-variance"
            type="number"
            inputMode="numeric"
            min={MIN_MINOR}
            max={MAX_MINOR}
            value={raw}
            onChange={(e) => setRaw(e.target.value)}
          />
          {rangeError ? <p className="text-sm text-destructive">{rangeError}</p> : null}
          {/* 0 là lựa chọn hợp lệ — nói rõ, đừng để người dùng đoán. */}
          <p className="text-sm text-muted-foreground">
            {t("hq.brand.settings.cash_variance.zero_hint")}
          </p>
        </div>

        <Button
          onClick={() => parsed !== null && saveMutation.mutate(parsed)}
          disabled={!isDirty || rangeError !== undefined || saveMutation.isPending}
        >
          {t("common.save")}
        </Button>
      </CardContent>
    </Card>
  );
}
