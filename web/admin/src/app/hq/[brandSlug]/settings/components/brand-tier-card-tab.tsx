"use client";

import { useEffect, useRef, useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
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
  Label,
  Spinner,
} from "@godxjp/ui";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface BrandTierCardSettingsData {
  /** {tier_key: file_id} — đúng thứ PATCH ngược lại. */
  customer_tier_card_backgrounds: Record<string, string>;
  /** {tier_key: url} — CHỈ để xem trước; server giải ra lúc đọc. */
  customer_tier_card_background_urls: Record<string, string | null>;
  /** Thang hạng đang cấu hình ở backend, thấp → cao. */
  membership_tiers: string[];
}

interface BrandTierCardSettingsResponse {
  data: BrandTierCardSettingsData;
}

interface FileResource {
  id: string;
  url: string | null;
}

interface FileUploadResponse {
  data: FileResource;
}

/** Ảnh đã chọn cho một hạng: id để lưu, url để xem trước. */
interface TierBackground {
  fileId: string;
  url: string | null;
}

// ---------------------------------------------------------------------------
// Query keys
// ---------------------------------------------------------------------------

const brandTierCardKeys = {
  get: (brandSlug: string) => ["hq", brandSlug, "settings", "brand"] as const,
};

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export interface BrandTierCardTabProps {
  brandSlug: string;
}

export function BrandTierCardTab({ brandSlug }: BrandTierCardTabProps) {
  const { t } = useTranslation();
  const qc = useQueryClient();

  const [backgrounds, setBackgrounds] = useState<Record<string, TierBackground>>({});
  const [uploadingTier, setUploadingTier] = useState<string | null>(null);
  const pendingTierRef = useRef<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  const { data, isLoading, error } = useQuery({
    queryKey: brandTierCardKeys.get(brandSlug),
    queryFn: () =>
      apiFetch<BrandTierCardSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`),
    staleTime: 60 * 1000,
    retry: false,
  });

  const settings = data?.data;
  const tiers = settings?.membership_tiers ?? [];

  useEffect(() => {
    if (!settings) return;

    const next: Record<string, TierBackground> = {};
    for (const [tier, fileId] of Object.entries(settings.customer_tier_card_backgrounds ?? {})) {
      next[tier] = { fileId, url: settings.customer_tier_card_background_urls?.[tier] ?? null };
    }
    setBackgrounds(next);
  }, [settings]);

  const savedIds = settings?.customer_tier_card_backgrounds ?? {};
  const currentIds = Object.fromEntries(
    Object.entries(backgrounds).map(([tier, bg]) => [tier, bg.fileId]),
  );
  const isDirty =
    settings !== undefined && JSON.stringify(savedIds) !== JSON.stringify(currentIds);

  const saveMutation = useMutation({
    mutationFn: (body: Record<string, string | null>) =>
      apiFetch<BrandTierCardSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`, {
        method: "PATCH",
        body: JSON.stringify({ customer_tier_card_backgrounds: body }),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: brandTierCardKeys.get(brandSlug) });
      toast.success(t("hq.brand.settings.tier_card.toast_saved"));
    },
    onError: (err) => {
      toast.error(
        err instanceof ApiError && err.body
          ? String((err.body as { message?: string }).message ?? err.message)
          : err instanceof Error
            ? err.message
            : t("hq.brand.settings.tier_card.toast_error"),
      );
    },
  });

  /**
   * Gửi map ĐẦY ĐỦ theo thang hạng: hạng nào đã gỡ ảnh thì gửi null để server
   * xoá khoá. Chỉ gửi các hạng còn ảnh thì hạng vừa gỡ sẽ giữ nguyên giá trị cũ
   * — bỏ ảnh xong bấm Lưu mà ảnh vẫn còn là kiểu lỗi người dùng không tự đoán ra.
   */
  const handleSave = () => {
    const body: Record<string, string | null> = {};
    for (const tier of tiers) {
      body[tier] = backgrounds[tier]?.fileId ?? null;
    }
    saveMutation.mutate(body);
  };

  const uploadFor = async (tier: string, file: File) => {
    setUploadingTier(tier);
    try {
      const formData = new FormData();
      formData.append("file", file);
      formData.append("collection", "customer_tier_card_background");

      const uploadRes = await apiFetch<FileUploadResponse>("/api/v1/files/upload", {
        method: "POST",
        body: formData,
      });

      // KHÔNG gọi `make-permanent` ở đây: server tự giữ file lại khi lưu cấu
      // hình. Ảnh chỉ trở thành vĩnh viễn khi nó thật sự được dùng, nên chọn
      // nhầm rồi bỏ đi không để lại rác trong kho.
      setBackgrounds((prev) => ({
        ...prev,
        [tier]: { fileId: uploadRes.data.id, url: uploadRes.data.url },
      }));

      toast.success(t("hq.brand.settings.tier_card.uploaded"));
    } catch (err) {
      toast.error(
        err instanceof ApiError && err.body
          ? String((err.body as { message?: string }).message ?? err.message)
          : err instanceof Error
            ? err.message
            : t("hq.brand.settings.tier_card.toast_error"),
      );
    } finally {
      setUploadingTier(null);
    }
  };

  const clearTier = (tier: string) => {
    setBackgrounds((prev) => {
      const next = { ...prev };
      delete next[tier];
      return next;
    });
  };

  const tierLabel = (tier: string) => {
    const key = `hq.brand.settings.tier_card.tiers.${tier}`;
    const label = t(key);
    // Hạng mới thêm ở backend mà chưa có bản dịch thì hiện thẳng khoá, đừng
    // hiện chuỗi key thô kiểu "hq.brand.settings…".
    return label === key ? tier : label;
  };

  if (error) {
    throw error;
  }

  return (
    <div data-slot="brand-tier-card-tab" className="max-w-2xl space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("hq.brand.settings.tier_card.section_title")}
          </CardTitle>
          <CardDescription>{t("hq.brand.settings.tier_card.description")}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-6">
          {isLoading ? (
            <div className="flex items-center gap-2 py-8 text-sm text-muted-foreground">
              <Spinner className="size-3.5" />
              {t("common.loading")}
            </div>
          ) : (
            <>
              <p className="text-xs text-muted-foreground">
                {t("hq.brand.settings.tier_card.fallback_hint")}
              </p>

              {tiers.map((tier) => {
                const bg = backgrounds[tier];
                return (
                  <div key={tier} className="space-y-2">
                    <Label>{tierLabel(tier)}</Label>

                    <div className="flex flex-wrap items-center gap-4">
                      {/*
                        Khung xem trước theo đúng tỉ lệ thẻ thật bên customer-web,
                        có cả chữ đè lên: ảnh tối làm mất chữ là lỗi chỉ nhìn ra
                        được khi xem trước đúng bối cảnh.
                      */}
                      <div
                        className="relative aspect-[16/5] w-64 shrink-0 overflow-hidden rounded-xl border bg-gradient-to-br from-[#F0DCA9] via-[#DFC084] to-[#C9A45E]"
                        style={
                          bg?.url
                            ? {
                                backgroundImage: `url(${bg.url})`,
                                backgroundSize: "cover",
                                backgroundPosition: "center",
                              }
                            : undefined
                        }
                      >
                        <div className="absolute inset-0 bg-gradient-to-r from-black/35 to-transparent" />
                        <div className="relative flex h-full flex-col justify-end p-3">
                          <span className="text-[11px] font-bold text-white drop-shadow">
                            {tierLabel(tier)}
                          </span>
                          <span className="text-sm font-bold text-white drop-shadow">
                            8.500 Point
                          </span>
                        </div>
                      </div>

                      <div className="space-y-1">
                        <div className="flex gap-2">
                          <Button
                            type="button"
                            size="sm"
                            className="gap-2"
                            disabled={uploadingTier !== null}
                            onClick={() => {
                              pendingTierRef.current = tier;
                              fileInputRef.current?.click();
                            }}
                          >
                            {uploadingTier === tier && <Spinner className="size-3.5" />}
                            {t("hq.brand.settings.tier_card.upload")}
                          </Button>

                          {bg && (
                            <Button
                              type="button"
                              size="sm"
                              variant="outline"
                              disabled={uploadingTier !== null}
                              onClick={() => clearTier(tier)}
                            >
                              {t("hq.brand.settings.tier_card.clear")}
                            </Button>
                          )}
                        </div>
                        <p className="text-[11px] text-muted-foreground">
                          {t("hq.brand.settings.tier_card.upload_help")}
                        </p>
                      </div>
                    </div>
                  </div>
                );
              })}

              <input
                ref={fileInputRef}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  const tier = pendingTierRef.current;
                  event.target.value = "";
                  pendingTierRef.current = null;

                  if (!file || !tier) {
                    return;
                  }

                  void uploadFor(tier, file);
                }}
              />

              <div className="flex justify-end">
                <Button
                  type="button"
                  onClick={handleSave}
                  disabled={!isDirty || saveMutation.isPending || uploadingTier !== null}
                  className="gap-2"
                >
                  {saveMutation.isPending && <Spinner className="size-3.5" />}
                  {t("common.save")}
                </Button>
              </div>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
