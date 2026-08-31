"use client";

/**
 * ApplyTaxTypeDialog (#1074) — bulk-assign a tax type to every product in
 * one category, in a single action. This is how an operator fixes the
 * "beer inherited the reduced rate" class of catalog bug: open the alcohol
 * category, pick STANDARD, apply.
 *
 * Picking the inherit option clears the per-product override so the
 * products fall back to branch/brand default resolution — mirrors the
 * per-product select in ProductSidebar (same sentinel trick: Radix Select
 * cannot carry an empty-string value).
 */

import { useState } from "react";
import { toast } from "sonner";
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { useTaxTypeLookup } from "@/hooks/api/use-tax-types";
import { useApplyCategoryTaxType } from "@/hooks/api/use-categories";

const TAX_INHERIT_SENTINEL = "__inherit__";

export interface ApplyTaxTypeDialogProps {
  brandSlug: string;
  categoryId: string;
  categoryName: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function ApplyTaxTypeDialog({
  brandSlug,
  categoryId,
  categoryName,
  open,
  onOpenChange,
}: ApplyTaxTypeDialogProps) {
  const { t } = useTranslation();
  const [taxTypeId, setTaxTypeId] = useState<string | null>(null);

  const { data: lookup, isLoading: taxTypesLoading } = useTaxTypeLookup(brandSlug);
  const taxTypes = lookup?.data ?? [];

  const applyMutation = useApplyCategoryTaxType(brandSlug);

  const handleApply = () => {
    applyMutation.mutate(
      { id: categoryId, taxTypeId },
      {
        onSuccess: (result) => {
          toast.success(
            taxTypeId === null
              ? t("toast.category.tax_type_cleared", { n: result.data.updated })
              : t("toast.category.tax_type_assigned", { n: result.data.updated })
          );
          onOpenChange(false);
        },
      }
    );
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t("hq.categories.apply_tax.title")}</DialogTitle>
          <DialogDescription>
            {t("hq.categories.apply_tax.description", { name: categoryName })}
          </DialogDescription>
        </DialogHeader>

        <Select
          value={taxTypeId ?? TAX_INHERIT_SENTINEL}
          onValueChange={(v) => setTaxTypeId(v === TAX_INHERIT_SENTINEL ? null : v)}
          disabled={taxTypesLoading || applyMutation.isPending}
        >
          <SelectTrigger className="h-9 w-full">
            <SelectValue placeholder={taxTypesLoading ? t("common.loading") : undefined} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={TAX_INHERIT_SENTINEL}>
              {t("hq.categories.apply_tax.inherit")}
            </SelectItem>
            {taxTypes.map((tt) => (
              <SelectItem key={tt.id} value={tt.id}>
                {tt.name} · {t("hq.tax_types.rate_display", { rate: String(tt.rate) })}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <p className="text-[11px] leading-relaxed text-muted-foreground">
          {t("hq.categories.apply_tax.hint")}
        </p>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={applyMutation.isPending}
          >
            {t("common.cancel")}
          </Button>
          <Button onClick={handleApply} disabled={applyMutation.isPending || taxTypesLoading}>
            {applyMutation.isPending ? t("common.loading") : t("hq.categories.apply_tax.apply")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
