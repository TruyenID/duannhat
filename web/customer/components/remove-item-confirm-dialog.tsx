"use client";

import { Trash2 } from "lucide-react";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

interface RemoveItemConfirmDialogProps {
  /** Open when a removal is pending confirmation. */
  open: boolean;
  /** Called when the dialog requests to close (cancel / backdrop / esc). */
  onOpenChange: (open: boolean) => void;
  /** Called when the user confirms the removal. */
  onConfirm: () => void;
}

/**
 * Shared "Xoá món?" confirmation dialog. Any surface that removes an item from
 * the cart (cart drawer, dine-in confirm, takeaway checkout, mobile checkout)
 * gates the trash action behind this so a tap never deletes without asking.
 * Reuses the `cart` i18n namespace keys already shipped for the cart drawer.
 */
export function RemoveItemConfirmDialog({
  open,
  onOpenChange,
  onConfirm,
}: RemoveItemConfirmDialogProps) {
  const t = useTranslations("cart");
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        showCloseButton={false}
        className="z-[60] max-w-[340px] gap-0 overflow-hidden p-0"
        overlayClassName="z-[60] max-md:bg-black/60"
      >
        <div className="flex flex-col items-center gap-3 px-6 pt-6 pb-2 text-center">
          <div className="flex h-14 w-14 items-center justify-center rounded-full bg-red-50">
            <Trash2 className="h-7 w-7 text-red-500" />
          </div>
          <DialogHeader className="items-center gap-1.5">
            <DialogTitle className="text-lg font-bold text-foreground">
              {t("removeItemConfirmTitle")}
            </DialogTitle>
            <DialogDescription className="text-center text-sm leading-relaxed text-muted-foreground">
              {t("removeItemConfirmMessage")}
            </DialogDescription>
          </DialogHeader>
        </div>
        <DialogFooter className="mt-4 flex-row gap-2 border-t-0 bg-transparent px-6 pb-6 pt-2">
          <Button
            variant="outline"
            className="h-11 flex-1 rounded-xl text-sm font-semibold"
            onClick={() => onOpenChange(false)}
          >
            {t("clearAllCancel")}
          </Button>
          <Button
            className="h-11 flex-1 rounded-xl bg-red-500 text-sm font-semibold text-white hover:bg-red-600"
            onClick={onConfirm}
          >
            {t("removeItemConfirm")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
