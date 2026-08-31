"use client";

import { useState } from "react";
import { Button } from "@godxjp/ui";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { useCreateZoneTemplate, useUpdateZoneTemplate } from "@/hooks/api/use-zone-templates";
import type { ZoneTemplateResource } from "@/types/hq-tables";
import type { ShopListItem } from "@/services/shop-service";
import { cn } from "@/lib/utils";

export interface ZoneTemplateFormDialogProps {
  brandSlug: string;
  /** Branches of the brand — options for the "Chi nhánh áp dụng" select. */
  shops: ShopListItem[];
  /** When set, the dialog edits this template instead of creating a new one. */
  template?: ZoneTemplateResource | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

/**
 * Modal form for creating / editing an HQ zone template (issue #890).
 *
 * Validation matches the backend FormRequest:
 *   - code: required, max 50, alphanumeric + hyphens, unique per brand
 *   - name: required, max 255
 *   - description: optional
 *   - display_order: optional, integer ≥ 0
 */
export function ZoneTemplateFormDialog({
  brandSlug,
  shops,
  template,
  open,
  onOpenChange,
}: ZoneTemplateFormDialogProps) {
  const { t } = useTranslation();
  const isEdit = !!template;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {isEdit ? t("hq.tables.zone_form.edit_title") : t("hq.tables.zone_form.title")}
          </DialogTitle>
          <DialogDescription>{t("hq.tables.zone_form.description")}</DialogDescription>
        </DialogHeader>

        {/* Remount the form per open/target so useState initializers reseed it
            without a setState-in-effect. */}
        {open && (
          <ZoneTemplateForm
            key={template?.id ?? "new"}
            brandSlug={brandSlug}
            shops={shops}
            template={template}
            onOpenChange={onOpenChange}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

interface ZoneTemplateFormProps {
  brandSlug: string;
  shops: ShopListItem[];
  template?: ZoneTemplateResource | null;
  onOpenChange: (open: boolean) => void;
}

function ZoneTemplateForm({ brandSlug, shops, template, onOpenChange }: ZoneTemplateFormProps) {
  const { t } = useTranslation();
  const createTemplate = useCreateZoneTemplate(brandSlug);
  const updateTemplate = useUpdateZoneTemplate(brandSlug);
  const isEdit = !!template;
  const isPending = createTemplate.isPending || updateTemplate.isPending;

  const [code, setCode] = useState(template?.code ?? "");
  const [name, setName] = useState(template?.name ?? "");
  const [description, setDescription] = useState(template?.description ?? "");
  const [displayOrder, setDisplayOrder] = useState(String(template?.display_order ?? 0));
  // "" = tất cả chi nhánh (brand-wide).
  const [branchId, setBranchId] = useState(template?.branch?.id ?? "");

  const isValid = code.trim().length > 0 && name.trim().length > 0;

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!isValid || isPending) return;

    const payload = {
      code: code.trim(),
      name: name.trim(),
      description: description.trim() || null,
      display_order: Number.parseInt(displayOrder, 10) || 0,
      branch_id: branchId || null,
    };

    if (isEdit && template) {
      updateTemplate.mutate(
        { id: template.id, data: payload },
        { onSuccess: () => onOpenChange(false) }
      );
    } else {
      createTemplate.mutate(payload, { onSuccess: () => onOpenChange(false) });
    }
  };

  return (
    <form onSubmit={handleSubmit} className="grid gap-3">
      <div className="grid gap-1.5">
        <label htmlFor="zone-template-code" className="text-xs font-medium">
          {t("hq.tables.zone_form.code")} <span className="text-destructive">*</span>
        </label>
        <Input
          id="zone-template-code"
          autoFocus
          required
          maxLength={50}
          value={code}
          onChange={(e) => setCode(e.target.value)}
          placeholder={t("hq.tables.zone_form.code_placeholder")}
        />
        <p className="text-[11px] text-muted-foreground">{t("hq.tables.zone_form.code_help")}</p>
      </div>

      <div className="grid gap-1.5">
        <label htmlFor="zone-template-name" className="text-xs font-medium">
          {t("hq.tables.zone_form.name")} <span className="text-destructive">*</span>
        </label>
        <Input
          id="zone-template-name"
          required
          maxLength={255}
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder={t("hq.tables.zone_form.name_placeholder")}
        />
      </div>

      <div className="grid gap-1.5">
        <label htmlFor="zone-template-description" className="text-xs font-medium">
          {t("hq.tables.zone_form.description_field")}
        </label>
        <Input
          id="zone-template-description"
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          placeholder={t("hq.tables.zone_form.description_placeholder")}
        />
      </div>

      <div className="grid gap-1.5">
        <label htmlFor="zone-template-branch" className="text-xs font-medium">
          {t("hq.tables.form.branch")}
        </label>
        <select
          id="zone-template-branch"
          value={branchId}
          onChange={(e) => setBranchId(e.target.value)}
          className={cn(
                "h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs transition-colors",
                "focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
                "dark:bg-input/30"
              )}
        >
          <option value="">{t("hq.tables.form.all_branches")}</option>
          {shops.map((shop) => (
            <option key={shop.id} value={shop.id}>
              {shop.name}
            </option>
          ))}
        </select>
        <p className="text-[11px] text-muted-foreground">{t("hq.tables.form.branch_help")}</p>
      </div>

      <div className="grid gap-1.5">
        <label htmlFor="zone-template-display-order" className="text-xs font-medium">
          {t("hq.tables.zone_form.display_order")}
        </label>
        <Input
          id="zone-template-display-order"
          type="number"
          min={0}
          value={displayOrder}
          onChange={(e) => setDisplayOrder(e.target.value)}
        />
      </div>

      <DialogFooter className="pt-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => onOpenChange(false)}
          disabled={isPending}
        >
          {t("common.cancel")}
        </Button>
        <Button type="submit" size="sm" disabled={!isValid || isPending}>
          {isEdit
            ? isPending
              ? t("common.saving")
              : t("common.save")
            : isPending
              ? t("common.creating")
              : t("common.create")}
        </Button>
      </DialogFooter>
    </form>
  );
}
