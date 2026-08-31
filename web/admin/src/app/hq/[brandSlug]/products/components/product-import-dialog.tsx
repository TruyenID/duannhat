"use client";

import { useRef, useState } from "react";
import { toast } from "sonner";
import { Upload } from "lucide-react";

import { Button } from "@godxjp/ui";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@godxjp/ui";
import { useImportProducts } from "@/hooks/api/use-products";
import { Spinner } from "@godxjp/ui";
import { productService, type ProductImportResult } from "@/services/product-service";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";

interface ProductImportDialogProps {
  brandSlug: string;
  brandId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function ProductImportDialog({
  brandSlug,
  brandId,
  open,
  onOpenChange,
}: ProductImportDialogProps) {
  const { t } = useTranslation();
  const [file, setFile] = useState<File | null>(null);
  const [result, setResult] = useState<ProductImportResult | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const importMutation = useImportProducts(brandSlug);

  function reset() {
    setFile(null);
    setResult(null);
    if (fileInputRef.current) fileInputRef.current.value = "";
  }

  function notifyOutcome(r: ProductImportResult) {
    const created = r.imported ?? r.created_count ?? r.success_count ?? 0;
    const failed = r.error_count ?? r.errors?.length ?? 0;

    if (created > 0 && failed === 0) {
      toast.success(t("product.import.toast_success", { n: created }));
    } else if (created > 0 && failed > 0) {
      toast.warning(t("product.import.toast_partial", { ok: created, fail: failed }));
    } else if (created === 0 && failed > 0) {
      toast.error(t("product.import.toast_failed", { n: failed }));
    }
  }

  async function handleSubmit() {
    if (!file) return;
    try {
      const r = await importMutation.mutateAsync({ file, brand_id: brandId });
      setResult(r);
      notifyOutcome(r);
    } catch (err) {
      // BE returns 422 with per-row errors when the file imported but some
      // rows failed. ApiError.body shape is `{success, message, data: {...}}`
      // — we unwrap `data` and render it the same way as the success case.
      const data =
        err instanceof ApiError ? (err.body.data as ProductImportResult | undefined) : undefined;
      if (err instanceof ApiError && err.status === 422 && Array.isArray(data?.errors)) {
        setResult(data);
        notifyOutcome(data);
        return;
      }
      // Non-validation error (network / 500) → no per-row detail.
      toast.error((err as Error).message || t("product.import.toast_failed", { n: 0 }));
    }
  }

  function handleClose(next: boolean) {
    if (!next) {
      reset();
    }
    onOpenChange(next);
  }

  const errors = result?.errors ?? [];
  const imported = result?.imported ?? result?.created_count ?? result?.success_count ?? 0;

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t("product.import.title")}</DialogTitle>
          <DialogDescription>{t("product.import.desc")}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-3 text-sm">
          <button
            type="button"
            onClick={() => {
              productService.downloadTemplate(brandSlug).catch((e) => {
                toast.error((e as Error).message || t("product.import.toast_failed", { n: 0 }));
              });
            }}
            className="text-left text-xs text-primary hover:underline"
          >
            {t("product.import.download_template")}
          </button>

          {/* Custom file picker: native `<input type="file">` rendered by
              Chrome ships a small "Choose File" button whose only clickable
              region is the button glyph itself — the rest of the row is dead
              space, which users misread as "the picker doesn't work". Use a
              full-width Button that forwards the click to a hidden input. */}
          <div className="flex items-center gap-2 rounded-md border border-input bg-input-background px-3 py-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 shrink-0 gap-1.5"
              onClick={() => fileInputRef.current?.click()}
              disabled={importMutation.isPending}
            >
              <Upload className="size-3.5" />
              {t("product.import.choose_file")}
            </Button>
            <span className="truncate text-xs text-muted-foreground" title={file?.name}>
              {file?.name ?? t("product.import.no_file")}
            </span>
            <input
              ref={fileInputRef}
              type="file"
              accept=".csv,text/csv"
              className="hidden"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              disabled={importMutation.isPending}
            />
          </div>

          {result && (
            <div className="rounded-md border bg-muted/30 p-2 text-xs">
              <div className="font-medium">{t("product.import.imported", { n: imported })}</div>
              {result.skipped !== undefined && (
                <div>{t("product.import.skipped", { n: result.skipped })}</div>
              )}
              {errors.length > 0 && (
                <div className="mt-1.5 max-h-60 space-y-1 overflow-y-auto">
                  <div className="font-medium text-destructive">
                    {t("product.import.errors", { n: errors.length })}
                  </div>
                  {/* BE shape: errors[].errors is an array of strings per row.
                      Join them into one comma-separated message per row so the
                      table-like rendering stays compact. */}
                  <ul className="ml-3 list-disc">
                    {errors.map((err) => (
                      <li key={err.row}>
                        {t("product.import.error_row", {
                          row: err.row,
                          message: err.errors.join("; "),
                        })}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          )}
        </div>

        <DialogFooter>
          {result ? (
            <Button size="sm" onClick={() => handleClose(false)}>
              {t("common.done")}
            </Button>
          ) : (
            <>
              <Button
                variant="outline"
                size="sm"
                onClick={() => handleClose(false)}
                disabled={importMutation.isPending}
              >
                {t("common.cancel")}
              </Button>
              <Button size="sm" onClick={handleSubmit} disabled={!file || importMutation.isPending}>
                {importMutation.isPending && <Spinner className="mr-1.5 size-3.5" />}
                {t("common.import")}
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
