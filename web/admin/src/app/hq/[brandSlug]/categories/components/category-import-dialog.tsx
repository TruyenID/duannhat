"use client";

import { useState } from "react";

import { Button } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@godxjp/ui";
import { useImportCategories } from "@/hooks/api/use-categories";
import { Spinner } from "@godxjp/ui";
import { categoryService, type CategoryImportResult } from "@/services/category-service";
import { useTranslation } from "@/providers/app-provider";

interface CategoryImportDialogProps {
  brandSlug: string;
  brandId: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function CategoryImportDialog({
  brandSlug,
  brandId,
  open,
  onOpenChange,
}: CategoryImportDialogProps) {
  const { t } = useTranslation();
  const [file, setFile] = useState<File | null>(null);
  const [result, setResult] = useState<CategoryImportResult | null>(null);
  const importMutation = useImportCategories(brandSlug);

  function reset() {
    setFile(null);
    setResult(null);
  }

  async function handleSubmit() {
    if (!file) return;
    try {
      const r = await importMutation.mutateAsync({ file, brand_id: brandId });
      setResult(r);
    } catch {
      // Toast surfaced by hook
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
          <DialogTitle>{t("hq.categories.import.title")}</DialogTitle>
          <DialogDescription>{t("hq.categories.import.desc")}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-3 text-sm">
          <a
            href={categoryService.templateUrl(brandSlug)}
            download
            className="text-xs text-primary hover:underline"
          >
            {t("hq.categories.import.download_template")}
          </a>

          <Input
            type="file"
            accept=".csv,text/csv"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            disabled={importMutation.isPending}
          />

          {result && (
            <div className="rounded-md border bg-muted/30 p-2 text-xs">
              <div className="font-medium">
                {t("hq.categories.import.imported", { n: imported })}
              </div>
              {result.skipped !== undefined && (
                <div>{t("hq.categories.import.skipped", { n: result.skipped })}</div>
              )}
              {errors.length > 0 && (
                <div className="mt-1.5 max-h-40 overflow-y-auto">
                  <div className="font-medium text-destructive">
                    {t("hq.categories.import.errors", { n: errors.length })}
                  </div>
                  <ul className="ml-3 list-disc">
                    {errors.map((err, i) => (
                      <li key={i}>
                        {t("hq.categories.import.error_row", {
                          row: err.row,
                          message: err.message,
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
