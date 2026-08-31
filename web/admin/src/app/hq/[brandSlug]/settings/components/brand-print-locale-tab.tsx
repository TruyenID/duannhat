"use client";

/**
 * Ngôn ngữ in mặc định cả chuỗi — `brand_order_policies.default_print_label_locale`.
 *
 * Áp dụng cho mọi cửa hàng trong brand chưa tự chọn ngôn ngữ in riêng
 * (`shop_order_settings.print_label_locale = null`). Nhờ vậy một chuỗi 20 cửa
 * hàng đặt MỘT lần ở đây thay vì vào từng shop đặt 20 lần.
 *
 * Cloud resolve shop ?? HQ trước khi gửi xuống workstation, nên workstation chỉ
 * thấy một giá trị đã chốt và vẫn in đúng ngôn ngữ khi MẤT MẠNG (giá trị nằm
 * sẵn trong SQLite local của máy).
 *
 * "Không ép" (null) = mỗi cửa hàng tự rơi về mặc định chi nhánh của mình.
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
  Label,
  RadioGroup,
  RadioGroupItem,
  Spinner,
} from "@godxjp/ui";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** Đúng tập locale toàn hệ thống (omnify.yaml `locale.locales`). */
type PrintLabelLocale = "ja" | "en" | "vi";

const PRINT_LABEL_LOCALES: PrintLabelLocale[] = ["ja", "en", "vi"];

/** Giá trị radio khi HQ không ép ngôn ngữ nào (gửi null lên BE). */
const NO_DEFAULT = "none";

interface BrandSettingsData {
  default_print_label_locale: PrintLabelLocale | null;
}

interface BrandSettingsResponse {
  data: BrandSettingsData;
}

// ---------------------------------------------------------------------------
// Query keys
// ---------------------------------------------------------------------------

const brandPrintLocaleKeys = {
  get: (brandSlug: string) => ["hq", brandSlug, "settings", "brand"] as const,
};

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export interface BrandPrintLocaleTabProps {
  brandSlug: string;
}

export function BrandPrintLocaleTab({ brandSlug }: BrandPrintLocaleTabProps) {
  const { t } = useTranslation();
  const qc = useQueryClient();

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: brandPrintLocaleKeys.get(brandSlug),
    queryFn: () => apiFetch<BrandSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`),
    staleTime: 60 * 1000,
    retry: false,
  });

  const settings = data?.data;
  const serverValue = settings?.default_print_label_locale ?? NO_DEFAULT;

  // `null` = untouched → render the server value. Selecting a radio sets a
  // local draft; saving invalidates the query, which resets the draft so the
  // freshly-fetched value shows through. This avoids the setState-in-effect
  // cascade an "effect syncs props into state" version would cause.
  const [draft, setDraft] = useState<string | null>(null);
  const locale = draft ?? serverValue;
  const setLocale = (v: string) => setDraft(v);

  const saveMutation = useMutation({
    mutationFn: (value: PrintLabelLocale | null) =>
      apiFetch<BrandSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`, {
        method: "PATCH",
        body: JSON.stringify({ default_print_label_locale: value }),
      }),
    onSuccess: () => {
      // Drop the draft so the refetched server value becomes the source again.
      setDraft(null);
      qc.invalidateQueries({ queryKey: brandPrintLocaleKeys.get(brandSlug) });
      toast.success(t("hq.brand.settings.print_locale.toast_saved"));
    },
    onError: (err) => {
      toast.error(
        err instanceof ApiError && err.body
          ? String((err.body as { message?: string }).message ?? err.message)
          : err instanceof Error
            ? err.message
            : t("hq.brand.settings.print_locale.toast_error")
      );
    },
  });

  // Transient failures get an inline retry rather than a full-page crash — the
  // brand policy is not worth losing the whole screen over.
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

  const isDirty = settings !== undefined && draft !== null && draft !== serverValue;

  return (
    <div data-slot="brand-print-locale-tab" className="max-w-xl space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("hq.brand.settings.print_locale.section_title")}
          </CardTitle>
          <CardDescription>{t("hq.brand.settings.print_locale.description")}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-4">
          {isLoading ? (
            <div className="flex items-center gap-2 py-8 text-sm text-muted-foreground">
              <Spinner className="size-3.5" />
              {t("common.loading")}
            </div>
          ) : (
            <RadioGroup value={locale} onValueChange={setLocale} className="space-y-2">
              <div className="flex items-start gap-3">
                <RadioGroupItem
                  value={NO_DEFAULT}
                  id="brand-print-locale-none"
                  className="mt-0.5 shrink-0"
                />
                <Label
                  htmlFor="brand-print-locale-none"
                  className="flex-1 cursor-pointer leading-snug"
                >
                  {t("hq.brand.settings.print_locale.none_radio")}
                </Label>
              </div>
              {PRINT_LABEL_LOCALES.map((code) => (
                <div key={code} className="flex items-start gap-3">
                  <RadioGroupItem
                    value={code}
                    id={`brand-print-locale-${code}`}
                    className="mt-0.5 shrink-0"
                  />
                  <Label
                    htmlFor={`brand-print-locale-${code}`}
                    className="flex-1 cursor-pointer leading-snug"
                  >
                    {t(`hq.brand.settings.print_locale.option_${code}`)}
                  </Label>
                </div>
              ))}
            </RadioGroup>
          )}

          <p className="text-xs text-muted-foreground">
            {t("hq.brand.settings.print_locale.hint")}
          </p>

          <div className="flex items-center gap-2 border-t pt-4">
            <Button
              onClick={() =>
                saveMutation.mutate(locale === NO_DEFAULT ? null : (locale as PrintLabelLocale))
              }
              disabled={saveMutation.isPending || isLoading || !isDirty}
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
