"use client";

import { Button } from "@godxjp/ui";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@godxjp/ui";
import { Spinner } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { useApplyTableDefaults, useTableDefaultsPreview } from "@/hooks/api/use-table-defaults";

export interface ApplyDefaultsDialogProps {
  shopSlug: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

/**
 * "Nhận bàn mặc định từ HQ" (issue #890) — previews how many zones/tables the
 * brand's HQ templates would add to this shop, then applies the copy on
 * confirm. The backend is idempotent by code: existing codes are skipped and
 * shop-created tables are never touched, so re-applying is always safe.
 */
export function ApplyDefaultsDialog({ shopSlug, open, onOpenChange }: ApplyDefaultsDialogProps) {
  const { t } = useTranslation();
  const preview = useTableDefaultsPreview(shopSlug, open);
  const applyDefaults = useApplyTableDefaults(shopSlug);

  const nothingToApply =
    preview.data != null && preview.data.zones.create === 0 && preview.data.tables.create === 0;

  const handleApply = () => {
    if (applyDefaults.isPending) return;
    applyDefaults.mutate(undefined, { onSuccess: () => onOpenChange(false) });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t("shop.tables.defaults.title")}</DialogTitle>
          <DialogDescription>{t("shop.tables.defaults.description")}</DialogDescription>
        </DialogHeader>

        {preview.isLoading && (
          <div className="flex items-center gap-2 py-2 text-sm text-muted-foreground">
            <Spinner className="size-4" />
            {t("shop.tables.defaults.loading_preview")}
          </div>
        )}

        {preview.isError && (
          <p className="py-2 text-sm text-destructive">
            {t("shop.tables.defaults.preview_failed")}
          </p>
        )}

        {preview.data && (
          <div className="grid gap-2 py-1 text-sm">
            <div className="flex items-center justify-between rounded-md border px-3 py-2">
              <span>{t("shop.tables.defaults.zones_to_create")}</span>
              <span className="font-semibold">{preview.data.zones.create}</span>
            </div>
            <div className="flex items-center justify-between rounded-md border px-3 py-2">
              <span>{t("shop.tables.defaults.tables_to_create")}</span>
              <span className="font-semibold">{preview.data.tables.create}</span>
            </div>
            {(preview.data.zones.skip > 0 || preview.data.tables.skip > 0) && (
              <p className="text-xs text-muted-foreground">
                {t("shop.tables.defaults.skip_note", {
                  zones: preview.data.zones.skip,
                  tables: preview.data.tables.skip,
                })}
              </p>
            )}
            {nothingToApply && (
              <p className="text-xs text-muted-foreground">
                {t("shop.tables.defaults.nothing_to_apply")}
              </p>
            )}
          </div>
        )}

        <DialogFooter>
          <Button
            variant="outline"
            size="sm"
            onClick={() => onOpenChange(false)}
            disabled={applyDefaults.isPending}
          >
            {t("common.cancel")}
          </Button>
          <Button
            size="sm"
            onClick={handleApply}
            disabled={!preview.data || nothingToApply || applyDefaults.isPending}
          >
            {applyDefaults.isPending && <Spinner className="mr-1.5 size-3.5" />}
            {t("shop.tables.defaults.apply")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
