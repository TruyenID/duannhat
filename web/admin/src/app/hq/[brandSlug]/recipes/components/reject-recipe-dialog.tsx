"use client";

/**
 * RejectRecipeDialog — collects the rejection reason before POSTing
 * /api/v1/hq/{brandSlug}/recipes/{recipe}/reject.
 *
 * Toast success + failure both fire from useRejectRecipe; this component
 * just gates the reason input, min-length (non-empty trim) and max 1000.
 */

import { useEffect, useState } from "react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@godxjp/ui";
import { Button } from "@godxjp/ui";
import { Textarea } from "@godxjp/ui";
import { Spinner } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { useRejectRecipe } from "@/hooks/api/use-recipes";

export interface RejectRecipeDialogProps {
  brandSlug: string;
  recipeId: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

const MAX_REASON = 1000;

export function RejectRecipeDialog({
  brandSlug,
  recipeId,
  open,
  onOpenChange,
}: RejectRecipeDialogProps) {
  const { t } = useTranslation();
  const [reason, setReason] = useState("");
  const reject = useRejectRecipe(brandSlug);

  useEffect(() => {
    if (!open) setReason("");
  }, [open]);

  const trimmed = reason.trim();
  const canSubmit = trimmed.length > 0 && trimmed.length <= MAX_REASON;

  async function handleConfirm() {
    if (!recipeId || !canSubmit) return;
    try {
      await reject.mutateAsync({ id: recipeId, reason: trimmed });
      onOpenChange(false);
    } catch {
      // toast.error fired by the hook.
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{t("hq.recipes.reject_dialog.title")}</DialogTitle>
          <DialogDescription>{t("hq.recipes.reject_dialog.description")}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-1.5 py-2">
          <label className="text-xs font-medium text-muted-foreground">
            {t("hq.recipes.reject_dialog.reason_label")}
          </label>
          <Textarea
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            maxLength={MAX_REASON}
            placeholder={t("hq.recipes.reject_dialog.reason_placeholder")}
            rows={5}
            autoFocus
            className="field-sizing-fixed"
          />
          <div className="text-right text-[11px] text-muted-foreground">
            {trimmed.length} / {MAX_REASON}
          </div>
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => onOpenChange(false)}
            disabled={reject.isPending}
          >
            {t("common.cancel")}
          </Button>
          <Button
            type="button"
            variant="destructive"
            size="sm"
            onClick={handleConfirm}
            disabled={!canSubmit || reject.isPending}
          >
            {reject.isPending && <Spinner className="mr-1.5 size-3.5" />}
            {t("hq.recipes.reject_dialog.confirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
