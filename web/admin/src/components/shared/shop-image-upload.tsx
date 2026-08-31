"use client";

import { useRef, useState } from "react";
import Image from "next/image";
import { Button, Label, Spinner } from "@godxjp/ui";
import { ImageIcon, Upload, X } from "lucide-react";
import { ApiError } from "@/lib/api";
import { shopService, type ShopImageType } from "@/services/shop-service";
import { useTranslation } from "@/providers/app-provider";
import { toast } from "sonner";

export interface ShopImageUploadProps {
  /** Brand slug — scopes the upload endpoint. */
  brandSlug: string;
  /** Which storefront image this field manages. */
  type: ShopImageType;
  /** Current stored image URL (null when unset). */
  value: string | null;
  /** Fired with the new URL after a successful upload, or null on remove. */
  onChange: (url: string | null) => void;
  label: string;
  hint?: string;
  error?: string;
  /** Disables the field (e.g. while the parent form is submitting). */
  disabled?: boolean;
  /** Notifies the parent while an upload is in flight so it can gate submit. */
  onUploadingChange?: (uploading: boolean) => void;
}

const ACCEPT = "image/jpeg,image/png,image/webp";
const MAX_BYTES = 5 * 1024 * 1024; // mirror backend `max:5120`

/**
 * #936 — preview box shape per slot. The preview mirrors the aspect ratio
 * customer-web renders that breakpoint at, so a wrong-shaped upload is
 * obvious here (letterboxed/cropped preview) instead of on the storefront.
 */
const PREVIEW_CLASS: Record<ShopImageType, string> = {
  logo: "aspect-square size-16",
  banner: "aspect-[3/1] w-40",
  banner_desktop: "aspect-[4/1] w-44",
  banner_tablet: "aspect-[8/3] w-36",
  banner_mobile: "aspect-[3/2] w-24",
};

/**
 * Single-image upload field for a shop logo or banner. Uploads immediately on
 * file select (returning a stored URL), previews the result, and supports
 * removal. The URL is persisted onto the shop by the parent form's submit.
 */
export function ShopImageUpload({
  brandSlug,
  type,
  value,
  onChange,
  label,
  hint,
  error,
  disabled,
  onUploadingChange,
}: ShopImageUploadProps) {
  const { t } = useTranslation();
  const inputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);


  function setUploadingState(next: boolean) {
    setUploading(next);
    onUploadingChange?.(next);
  }

  async function handleFile(file: File) {
    if (!ACCEPT.split(",").includes(file.type)) {
      toast.error(t("hq.shops.form.image_type_invalid"));
      return;
    }
    if (file.size > MAX_BYTES) {
      toast.error(t("hq.shops.form.image_too_large"));
      return;
    }

    setUploadingState(true);
    try {
      const url = await shopService.uploadImage(brandSlug, type, file);
      onChange(url);
    } catch (err) {
      const message =
        err instanceof ApiError ? (err.body as { message?: string })?.message : null;
      toast.error(message || t("hq.shops.form.image_upload_failed"));
    } finally {
      setUploadingState(false);
      // Reset so re-selecting the same file re-triggers onChange.
      if (inputRef.current) inputRef.current.value = "";
    }
  }

  return (
    <div className="flex flex-col gap-0.5" data-slot="shop-image-upload">
      <Label className="text-[11px] font-medium">
        {label}
        <span className="ml-1 font-normal text-muted-foreground">(optional)</span>
      </Label>

      <div className="flex items-center gap-2">
        <div
          className={`relative shrink-0 overflow-hidden rounded-md border bg-muted ${PREVIEW_CLASS[type]}`}
        >
          {value ? (
            <Image src={value} alt={label} fill sizes="160px" className="object-cover" />
          ) : (
            <div className="flex h-full w-full items-center justify-center text-muted-foreground">
              <ImageIcon className="size-5 opacity-60" />
            </div>
          )}
          {uploading && (
            <div className="absolute inset-0 flex items-center justify-center bg-background/60">
              <Spinner className="size-4" />
            </div>
          )}
        </div>

        <div className="flex flex-col gap-1">
          <input
            ref={inputRef}
            type="file"
            accept={ACCEPT}
            className="hidden"
            disabled={disabled || uploading}
            onChange={(e) => {
              const file = e.target.files?.[0];
              if (file) void handleFile(file);
            }}
          />
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-7 text-xs"
            disabled={disabled || uploading}
            onClick={() => inputRef.current?.click()}
          >
            <Upload className="mr-1 size-3.5" />
            {value ? t("hq.shops.form.image_replace") : t("hq.shops.form.image_upload")}
          </Button>
          {value && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="h-7 text-xs text-muted-foreground"
              disabled={disabled || uploading}
              onClick={() => onChange(null)}
            >
              <X className="mr-1 size-3.5" />
              {t("common.remove")}
            </Button>
          )}
        </div>
      </div>

      {hint && !error && <p className="text-[10px] text-muted-foreground">{hint}</p>}
      {error && <p className="text-[11px] text-red-500">{error}</p>}
    </div>
  );
}
