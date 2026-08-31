"use client";

/**
 * Plan-039 — Share-bill mode selector for counter-pay orders.
 *
 * Customer-web shows this bottom sheet from /order-success when an order is:
 *   - counter-pay (no Stripe intent yet — `orderData.is_counter_pay === true`)
 *   - not yet paid (`orderData.split_mode_locked === false`)
 *
 * Save → `POST /api/v1/customer/orders/{id}/split-mode` → BE persists the
 * choice on `customer_orders.split_mode`. KioskOrderResource exposes the
 * same field; when the cashier scans the QR, `app/bill.tsx` reads it and
 * routes straight to the matching allocation page (skipping the
 * /split-options chooser).
 *
 * `custom` is intentionally NOT offered here — counter-pay has no
 * per-person amount input UI on customer-web (ADR-1 in plan-039 DESIGN.md).
 * BE rejects custom for counter-pay with HTTP 422 regardless of what the
 * client sends.
 */

import { useState } from "react";
import { Check, ListChecks, Loader2, Users } from "lucide-react";
import { useTranslations } from "next-intl";

import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { apiFetch, ApiError } from "@/lib/api";

export type SplitMode = "even" | "by_items";

interface SplitBillSheetProps {
  orderId: string;
  /** Persisted split_mode from `orderData.split_mode` (null when not yet chosen). */
  initialMode: SplitMode | null;
  /** When true, both options are disabled and the locked copy is shown. */
  locked: boolean;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Called after a successful POST so the parent can update local state. */
  onSaved: (mode: SplitMode) => void;
}

const MODES: ReadonlyArray<{
  id: SplitMode;
  Icon: typeof Users;
  labelKey: string;
  descriptionKey: string;
}> = [
  {
    id: "even",
    Icon: Users,
    labelKey: "byPeople",
    descriptionKey: "byPeopleDesc",
  },
  {
    id: "by_items",
    Icon: ListChecks,
    labelKey: "byItems",
    descriptionKey: "byItemsDesc",
  },
];

export default function SplitBillSheet({
  orderId,
  initialMode,
  locked,
  open,
  onOpenChange,
  onSaved,
}: SplitBillSheetProps) {
  const t = useTranslations("splitBill");
  const [pending, setPending] = useState<SplitMode | null>(null);
  const [error, setError] = useState<string | null>(null);

  const handleSelect = async (mode: SplitMode) => {
    if (locked || pending !== null) return;
    setPending(mode);
    setError(null);
    try {
      await apiFetch(`/api/v1/customer/orders/${orderId}/split-mode`, {
        method: "POST",
        body: JSON.stringify({ split_mode: mode }),
        silent401: true,
      });
      onSaved(mode);
      onOpenChange(false);
    } catch (err) {
      // 409 SPLIT_MODE_LOCKED — kiosk has already started paying. Surface
      // a specific message so the customer understands re-edit is gone.
      if (err instanceof ApiError && err.status === 409) {
        setError(t("lockedAtKiosk"));
      } else {
        setError(t("saveFailed"));
      }
    } finally {
      setPending(null);
    }
  };

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent
        data-slot="split-bill-sheet"
        side="bottom"
        className="rounded-t-3xl p-0"
      >
        <SheetHeader className="border-b px-6 py-4 text-left">
          <SheetTitle className="text-lg font-semibold">{t("title")}</SheetTitle>
          <SheetDescription className="text-sm text-neutral-600">
            {t("description")}
          </SheetDescription>
        </SheetHeader>

        <div className="space-y-3 px-6 py-5">
          {MODES.map(({ id, Icon, labelKey, descriptionKey }) => {
            const selected = initialMode === id;
            const isPending = pending === id;
            return (
              <button
                key={id}
                type="button"
                aria-pressed={selected}
                disabled={locked || pending !== null}
                onClick={() => handleSelect(id)}
                className={[
                  "flex w-full items-center gap-3 rounded-2xl border p-4 text-left transition",
                  selected
                    ? "border-[#2D8336] bg-[#F0FDF4]"
                    : "border-neutral-200 hover:border-neutral-300",
                  locked || pending !== null
                    ? "cursor-not-allowed opacity-60"
                    : "cursor-pointer",
                ].join(" ")}
              >
                <span
                  className={[
                    "flex size-10 items-center justify-center rounded-full",
                    selected
                      ? "bg-[#2D8336] text-white"
                      : "bg-neutral-100 text-neutral-700",
                  ].join(" ")}
                >
                  <Icon className="size-5" />
                </span>
                <span className="flex-1">
                  <span className="block text-base font-semibold text-neutral-900">
                    {t(labelKey)}
                  </span>
                  <span className="mt-0.5 block text-xs text-neutral-600">
                    {t(descriptionKey)}
                  </span>
                </span>
                {isPending ? (
                  <Loader2 className="size-5 animate-spin text-neutral-500" />
                ) : selected ? (
                  <Check className="size-5 text-[#2D8336]" />
                ) : null}
              </button>
            );
          })}

          {error && (
            <p
              role="alert"
              className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700"
            >
              {error}
            </p>
          )}

          {locked && !error && (
            <p className="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">
              {t("lockedAtKiosk")}
            </p>
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}
