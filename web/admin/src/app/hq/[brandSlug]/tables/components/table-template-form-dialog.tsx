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
import {
  useCreateTableTemplate,
  useUpdateTableTemplate,
} from "@/hooks/api/use-table-templates";
import type { TableTemplateResource, ZoneTemplateResource } from "@/types/hq-tables";
import type { ShopListItem } from "@/services/shop-service";
import { cn } from "@/lib/utils";

export interface TableTemplateFormDialogProps {
  brandSlug: string;
  /** Branches of the brand — options for the "Chi nhánh áp dụng" select. */
  shops: ShopListItem[];
  zoneTemplates: ZoneTemplateResource[];
  /** When set, the dialog edits this template instead of creating a new one. */
  template?: TableTemplateResource | null;
  /**
   * Optionally pre-select a zone template (used when the user clicks
   * "Add table" from inside a specific zone section).
   */
  defaultZoneTemplateId?: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

/**
 * Modal form for creating / editing an HQ table template (issue #890).
 *
 * Validation matches the backend FormRequest:
 *   - zone_template_id: required, must belong to the brand
 *   - code: required, max 50, alphanumeric + hyphens, unique per brand
 *   - name: optional, max 255
 *   - seat_count: integer ≥ 1
 */
export function TableTemplateFormDialog({
  brandSlug,
  shops,
  zoneTemplates,
  template,
  defaultZoneTemplateId,
  open,
  onOpenChange,
}: TableTemplateFormDialogProps) {
  const { t } = useTranslation();
  const isEdit = !!template;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {isEdit ? t("hq.tables.table_form.edit_title") : t("hq.tables.table_form.title")}
          </DialogTitle>
          <DialogDescription>{t("hq.tables.table_form.description")}</DialogDescription>
        </DialogHeader>

        {/* Remount the form per open/target so useState initializers reseed it
            without a setState-in-effect. */}
        {open && (
          <TableTemplateForm
            key={template?.id ?? `new-${defaultZoneTemplateId ?? ""}`}
            brandSlug={brandSlug}
            shops={shops}
            zoneTemplates={zoneTemplates}
            template={template}
            defaultZoneTemplateId={defaultZoneTemplateId}
            onOpenChange={onOpenChange}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

interface TableTemplateFormProps {
  brandSlug: string;
  shops: ShopListItem[];
  zoneTemplates: ZoneTemplateResource[];
  template?: TableTemplateResource | null;
  defaultZoneTemplateId?: string;
  onOpenChange: (open: boolean) => void;
}

function TableTemplateForm({
  brandSlug,
  shops,
  zoneTemplates,
  template,
  defaultZoneTemplateId,
  onOpenChange,
}: TableTemplateFormProps) {
  const { t } = useTranslation();
  const createTemplate = useCreateTableTemplate(brandSlug);
  const updateTemplate = useUpdateTableTemplate(brandSlug);
  const isEdit = !!template;
  const isPending = createTemplate.isPending || updateTemplate.isPending;

  const [zoneTemplateId, setZoneTemplateId] = useState(
    template?.zone_template.id ?? defaultZoneTemplateId ?? zoneTemplates[0]?.id ?? ""
  );
  const [code, setCode] = useState(template?.code ?? "");
  const [name, setName] = useState(template?.name ?? "");
  const [seatCount, setSeatCount] = useState(String(template?.seat_count ?? 4));
  // "" = tất cả chi nhánh (brand-wide).
  const [branchId, setBranchId] = useState(template?.branch?.id ?? "");

  const noZones = zoneTemplates.length === 0;
  const seats = Number.parseInt(seatCount, 10);
  const isValid =
    !noZones &&
    zoneTemplateId.length > 0 &&
    code.trim().length > 0 &&
    Number.isFinite(seats) &&
    seats >= 1 &&
    seats <= 1000;

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!isValid || isPending) return;

    const payload = {
      zone_template_id: zoneTemplateId,
      code: code.trim(),
      name: name.trim() || null,
      seat_count: seats,
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
        <label htmlFor="table-template-zone" className="text-xs font-medium">
          {t("hq.tables.table_form.zone")} <span className="text-destructive">*</span>
        </label>
        <select
          id="table-template-zone"
          required
          disabled={noZones}
          value={zoneTemplateId}
          onChange={(e) => setZoneTemplateId(e.target.value)}
          className={cn(
            "h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs transition-colors",
            "focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
            "disabled:cursor-not-allowed disabled:opacity-50",
            "dark:bg-input/30"
          )}
        >
          {noZones && (
            <option value="" disabled>
              {t("hq.tables.table_form.zone_placeholder")}
            </option>
          )}
          {zoneTemplates.map((z) => (
            <option key={z.id} value={z.id}>
              {z.code} — {z.name}
            </option>
          ))}
        </select>
        {noZones && (
          <p className="text-[11px] text-muted-foreground">
            {t("hq.tables.table_form.zone_required_hint")}
          </p>
        )}
      </div>

      <div className="grid gap-1.5">
        <label htmlFor="table-template-code" className="text-xs font-medium">
          {t("hq.tables.table_form.code")} <span className="text-destructive">*</span>
        </label>
        <Input
          id="table-template-code"
          autoFocus
          required
          maxLength={50}
          value={code}
          onChange={(e) => setCode(e.target.value)}
          placeholder={t("hq.tables.table_form.code_placeholder")}
        />
        <p className="text-[11px] text-muted-foreground">{t("hq.tables.table_form.code_help")}</p>
      </div>

      <div className="grid gap-1.5">
        <label htmlFor="table-template-name" className="text-xs font-medium">
          {t("hq.tables.table_form.name")}
        </label>
        <Input
          id="table-template-name"
          maxLength={255}
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder={t("hq.tables.table_form.name_placeholder")}
        />
      </div>

      <div className="grid gap-1.5">
        <label htmlFor="table-template-branch" className="text-xs font-medium">
          {t("hq.tables.form.branch")}
        </label>
        <select
          id="table-template-branch"
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
        <label htmlFor="table-template-seats" className="text-xs font-medium">
          {t("hq.tables.table_form.seat_count")} <span className="text-destructive">*</span>
        </label>
        <Input
          id="table-template-seats"
          type="number"
          required
          min={1}
          max={1000}
          value={seatCount}
          onChange={(e) => setSeatCount(e.target.value)}
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
