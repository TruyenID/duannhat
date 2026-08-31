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
	  Input,
	  Label,
	  Spinner,
	} from "@godxjp/ui";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface BrandCustomerSettingsData {
  /**
   * #2047 — CHỈ để xem trước; server giải ra lúc đọc từ `*_logo_file_id`.
   * KHÔNG gửi lại — gửi lại là ghim host của lúc upload, đúng lỗi cũ.
   */
  customer_header_logo_url: string | null;
  customer_order_logo_url: string | null;
  /** #2047 — id File, đây mới là thứ PATCH ngược lại (GET/PATCH đối xứng). */
  customer_header_logo_file_id: string | null;
  customer_order_logo_file_id: string | null;
  customer_order_subtitle: string | null;
  /** #1152 — インボイス T+13 (brand default; branch may override). */
  invoice_registration_number: string | null;
}

interface BrandCustomerSettingsResponse {
  data: BrandCustomerSettingsData;
}

interface FileResource {
  id: string;
  url: string | null;
  status: string | null;
  is_permanent: boolean;
}

interface FileUploadResponse {
  data: FileResource;
}

// ---------------------------------------------------------------------------
// Query keys
// ---------------------------------------------------------------------------

const brandCustomerSettingsKeys = {
  get: (brandSlug: string) => ["hq", brandSlug, "settings", "brand"] as const,
};

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

export interface BrandCustomerSettingsTabProps {
  brandSlug: string;
}

export function BrandCustomerSettingsTab({ brandSlug }: BrandCustomerSettingsTabProps) {
  const { t } = useTranslation();
  const qc = useQueryClient();

  // #2047 — url để XEM TRƯỚC, fileId là thứ được lưu. Cùng hình dạng với
  // `brand-tier-card-tab.tsx` (#1772).
  const [logoUrl, setLogoUrl] = useState("");
  const [orderLogoUrl, setOrderLogoUrl] = useState("");
  const [logoFileId, setLogoFileId] = useState<string | null>(null);
  const [orderLogoFileId, setOrderLogoFileId] = useState<string | null>(null);
  const [regNumber, setRegNumber] = useState("");
  const [isUploadingHeader, setIsUploadingHeader] = useState(false);
  const [isUploadingOrder, setIsUploadingOrder] = useState(false);
  const headerFileInputRef = useRef<HTMLInputElement | null>(null);
  const orderFileInputRef = useRef<HTMLInputElement | null>(null);

  const { data, isLoading, error } = useQuery({
    queryKey: brandCustomerSettingsKeys.get(brandSlug),
    queryFn: () =>
      apiFetch<BrandCustomerSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`),
    staleTime: 60 * 1000,
    retry: false,
  });

  const settings = data?.data;

  useEffect(() => {
    if (!settings) return;
    setLogoUrl(settings.customer_header_logo_url ?? "");
    setOrderLogoUrl(settings.customer_order_logo_url ?? "");
    setLogoFileId(settings.customer_header_logo_file_id ?? null);
    setOrderLogoFileId(settings.customer_order_logo_file_id ?? null);
    setRegNumber(settings.invoice_registration_number ?? "");
  }, [settings]);

  // #2047 — so theo file id, KHÔNG theo url: url là giá trị server giải ra lúc
  // đọc nên nó đổi khi cấu hình disk đổi, và so theo nó sẽ báo dirty giả.
  const isDirty =
    settings !== undefined &&
	    (logoFileId !== (settings.customer_header_logo_file_id ?? null) ||
	      orderLogoFileId !== (settings.customer_order_logo_file_id ?? null) ||
	      regNumber !== (settings.invoice_registration_number ?? ""));

  const saveMutation = useMutation({
    mutationFn: (body: Partial<BrandCustomerSettingsData>) =>
      apiFetch<BrandCustomerSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/brand`, {
        method: "PATCH",
        body: JSON.stringify(body),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: brandCustomerSettingsKeys.get(brandSlug) });
      toast.success(t("hq.brand.settings.customer.toast_saved"));
    },
    onError: (err) => {
      toast.error(
        err instanceof ApiError && err.body
          ? String((err.body as { message?: string }).message ?? err.message)
          : err instanceof Error
            ? err.message
            : t("hq.brand.settings.customer.toast_error"),
      );
    },
  });

  const handleSave = () => {
    const trimmedReg = regNumber.trim();
    // #1153 — accept either national format (JP T+13 · VN mã số thuế); the
    // server resolves the org's country profile and rejects the wrong one.
    if (
      trimmedReg !== "" &&
      !/^T\d{13}$/.test(trimmedReg) &&
      !/^\d{10}(-\d{3})?$/.test(trimmedReg)
    ) {
      toast.error(t("hq.brand.settings.customer.reg_number_invalid"));
      return;
    }

    // #2047 — gửi ID, không gửi URL. URL tuyệt đối ghim host của lúc upload nên
    // đổi CDN/tunnel là gãy hàng loạt; id để server giải lại lúc đọc.
    saveMutation.mutate({
      customer_header_logo_file_id: logoFileId,
      customer_order_logo_file_id: orderLogoFileId,
      invoice_registration_number: trimmedReg === "" ? null : trimmedReg,
    });
  };

  const uploadLogo = async (
    file: File,
    collection: string,
    setUrl: (url: string) => void,
    setFileId: (fileId: string) => void,
    setUploading: (val: boolean) => void,
  ) => {
    setUploading(true);
    try {
      const formData = new FormData();
      formData.append("file", file);
      formData.append("collection", collection);

      const uploadRes = await apiFetch<FileUploadResponse>("/api/v1/files/upload", {
        method: "POST",
        body: formData,
      });

      const uploaded = uploadRes.data;

      // #2047 — KHÔNG nuốt lỗi ở đây nữa. Bản cũ bọc try/catch và chỉ
      // console.error, kèm ghi chú "best-effort; the logo URL is still usable" —
      // điều đó chỉ đúng trong 12 giờ, tới khi `omnify:cleanup-files` quét file
      // vẫn còn `status=temporary`. Sau đó logo mất vĩnh viễn mà không có gì
      // báo, đúng triệu chứng đã thấy trên production. Ném ra để catch bên dưới
      // báo lỗi và người dùng biết mà thử lại.
      //
      // Server cũng tự make-permanent lúc PATCH (BrandSettingsService), nên đây
      // là lớp phòng thứ hai chứ không phải chỗ duy nhất giữ file.
      await apiFetch<FileUploadResponse>(`/api/v1/files/${uploaded.id}/make-permanent`, {
        method: "POST",
      });

      if (uploaded.url) {
        setUrl(uploaded.url);
      }
      setFileId(uploaded.id);

      toast.success(t("hq.brand.settings.customer.logo_uploaded"));
    } catch (err) {
      toast.error(
        err instanceof ApiError && err.body
          ? String((err.body as { message?: string }).message ?? err.message)
          : err instanceof Error
            ? err.message
            : t("hq.brand.settings.customer.toast_error"),
      );
    } finally {
      setUploading(false);
    }
  };

  if (error) {
    throw error;
  }

  return (
    <div data-slot="brand-customer-settings-tab" className="max-w-xl space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("hq.brand.settings.customer.section_title")}
          </CardTitle>
          <CardDescription>
            {t("hq.brand.settings.customer.description")}
          </CardDescription>
        </CardHeader>

        <CardContent className="space-y-6">
          {isLoading ? (
            <div className="flex items-center gap-2 py-8 text-sm text-muted-foreground">
              <Spinner className="size-3.5" />
              {t("common.loading")}
            </div>
          ) : (
            <>
              <div className="space-y-2">
                <Label htmlFor="customer-header-logo">
                  {t("hq.brand.settings.customer.header_logo_label")}
                </Label>
                <div className="flex items-center gap-4">
                  {logoUrl ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                      id="customer-header-logo"
                      src={logoUrl}
                      alt=""
                      className="h-10 w-auto rounded border bg-muted object-contain"
                    />
                  ) : (
                    <div
                      id="customer-header-logo"
                      className="flex h-10 w-32 items-center justify-center rounded border border-dashed text-xs text-muted-foreground"
                    >
                      {t("hq.brand.settings.customer.header_logo_label")}
                    </div>
                  )}
                  <div>
                    <Button
                      type="button"
                      size="sm"
                      className="gap-2"
                      disabled={isUploadingHeader}
                      onClick={() => headerFileInputRef.current?.click()}
                    >
                      {isUploadingHeader && <Spinner className="size-3.5" />}
                      {t("hq.brand.settings.customer.header_logo_upload")}
                    </Button>
                    <p className="mt-1 text-[11px] text-muted-foreground">
                      {t("hq.brand.settings.customer.header_logo_help")}
                    </p>
                  </div>
                </div>
                <input
                  ref={headerFileInputRef}
                  type="file"
                  accept="image/*"
                  className="hidden"
                  onChange={(event) => {
                    const file = event.target.files?.[0];
                    if (!file) {
                      return;
                    }

                    void uploadLogo(
                      file,
                      "customer_header_logo",
                      setLogoUrl,
                      setLogoFileId,
                      setIsUploadingHeader,
                    );
                    event.target.value = "";
                  }}
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="customer-order-logo">
                  {t("hq.brand.settings.customer.order_logo_label")}
                </Label>
                <div className="flex items-center gap-4">
                  {orderLogoUrl ? (
                    // eslint-disable-next-line @next/next/no-img-element
                    <img
                      id="customer-order-logo"
                      src={orderLogoUrl}
                      alt=""
                      className="h-10 w-auto rounded border bg-muted object-contain"
                    />
                  ) : (
                    <div
                      id="customer-order-logo"
                      className="flex h-10 w-32 items-center justify-center rounded border border-dashed text-xs text-muted-foreground"
                    >
                      {t("hq.brand.settings.customer.order_logo_label")}
                    </div>
                  )}
                  <div>
                    <Button
                      type="button"
                      size="sm"
                      className="gap-2"
                      disabled={isUploadingOrder}
                      onClick={() => orderFileInputRef.current?.click()}
                    >
                      {isUploadingOrder && <Spinner className="size-3.5" />}
                      {t("hq.brand.settings.customer.order_logo_upload")}
                    </Button>
                    <p className="mt-1 text-[11px] text-muted-foreground">
                      {t("hq.brand.settings.customer.order_logo_help")}
                    </p>
                  </div>
                </div>
                <input
                  ref={orderFileInputRef}
                  type="file"
                  accept="image/*"
                  className="hidden"
                  onChange={(event) => {
                    const file = event.target.files?.[0];
                    if (!file) {
                      return;
                    }

                    void uploadLogo(
                      file,
                      "customer_order_logo",
                      setOrderLogoUrl,
                      setOrderLogoFileId,
                      setIsUploadingOrder,
                    );
                    event.target.value = "";
                  }}
                />
              </div>
            </>
          )}
        </CardContent>
      </Card>

      {/* #1152 — インボイス T+13 registration number (brand default). */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            {t("hq.brand.settings.customer.reg_number_title")}
          </CardTitle>
          <CardDescription>
            {t("hq.brand.settings.customer.reg_number_description")}
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Input
            value={regNumber}
            onChange={(e) => setRegNumber(e.target.value)}
            placeholder="T1234567890123"
            maxLength={14}
            disabled={isLoading}
            className="max-w-xs font-mono"
          />
          <p className="mt-1 text-[11px] text-muted-foreground">
            {t("hq.brand.settings.customer.reg_number_help")}
          </p>
        </CardContent>
      </Card>

      <div className="flex items-center justify-end gap-3">
        {isDirty && (
          <span className="text-xs text-muted-foreground">
            {t("settings.order.unsaved_changes")}
          </span>
        )}
        <Button
          size="sm"
          className="h-8 gap-1.5 text-xs"
          disabled={saveMutation.isPending || isLoading || !isDirty}
          onClick={handleSave}
        >
          {saveMutation.isPending && <Spinner className="size-3.5" />}
          {t("common.save_changes")}
        </Button>
      </div>
    </div>
  );
}
