"use client";

/**
 * StackingConflictDialog — when adding a Happy Hour item to an order that
 * already has a coupon applied, the backend rejects with 422
 * `cannot_add_promotion_item_with_coupon`. This dialog offers staff two
 * paths: cancel the add, or auto-release the coupon and retry the add
 * (plan-019 T8.5).
 *
 * The component is purely UI — the caller wires the retry by:
 *   1. catching the `ApiError(422, "cannot_add_promotion_item_with_coupon")`
 *   2. setting `pendingConflict={...}` to open this dialog
 *   3. on `onAutoResolve`: call `orderService.releaseCoupon` then re-run
 *      the original `addItem` request.
 */

import {
  Button,
  Dialog,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Spinner,
} from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import { useTranslation } from "@/providers/app-provider";

export interface StackingConflict {
  /** Display name of the item the staff was trying to add. */
  itemName: string;
  /** Currently-applied coupon code (snapshot from order). */
  couponCode: string;
}

interface StackingConflictDialogProps {
  conflict: StackingConflict | null;
  onCancel: () => void;
  onAutoResolve: () => void | Promise<void>;
  isPending?: boolean;
}

export function StackingConflictDialog({
  conflict,
  onCancel,
  onAutoResolve,
  isPending,
}: StackingConflictDialogProps) {
  const { t } = useTranslation();
  return (
    <Dialog open={conflict !== null} onOpenChange={(open) => !open && onCancel()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <div className="flex items-center gap-1.5">
            <DialogTitle>{t("pos.promo.confirm_remove_coupon_title")}</DialogTitle>
            <HelpButton topic="stacking-conflict" className="size-7" />
          </div>
          <DialogDescription>
            {t("pos.promo.confirm_remove_coupon_body", { name: conflict?.itemName ?? "" })}
            {conflict?.couponCode && (
              <span className="ml-1 font-mono">({conflict.couponCode})</span>
            )}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" onClick={onCancel} disabled={isPending}>
            {t("pos.promo.confirm_remove_coupon_no")}
          </Button>
          <Button onClick={onAutoResolve} disabled={isPending}>
            {isPending && <Spinner className="mr-1.5 size-3.5" />}
            {t("pos.promo.confirm_remove_coupon_yes")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
