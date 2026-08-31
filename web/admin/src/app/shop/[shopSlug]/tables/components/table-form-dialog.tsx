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
import { useCreateTable, useUpdateTable } from "@/hooks/api/use-tables";
import type { TableResource, ZoneResource } from "@/types/shop";
import { cn } from "@/lib/utils";

export interface TableFormDialogProps {
  shopSlug: string;
  zones: ZoneResource[];
  /**
   * When set, the dialog edits this table instead of creating a new one.
   * Only shop-created tables are editable (issue #890 / BR-T09 — HQ-origin
   * tables 409 on the backend and the edit action is hidden in the UI).
   */
  table?: TableResource | null;
  /**
   * Optionally pre-select a zone (used when the user clicks "Add table"
   * from inside a specific ZoneSection).
   */
  defaultZoneId?: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

/**
 * Modal form for creating / editing a Table.
 *
 * Validation matches the backend FormRequest:
 *   - zone_id: required, must belong to the resolved shop
 *   - code: required, max 50, alphanumeric + hyphens, unique per shop
 *   - name: optional, max 255
 *   - seat_count: required, integer 1..1000
 */
export function TableFormDialog({
  shopSlug,
  zones,
  table,
  defaultZoneId,
  open,
  onOpenChange,
}: TableFormDialogProps) {
  const { t } = useTranslation();
  const isEdit = !!table;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {isEdit ? t("shop.tables.table_form.edit_title") : t("shop.tables.table_form.title")}
          </DialogTitle>
          <DialogDescription>
            {isEdit
              ? t("shop.tables.table_form.edit_description")
              : t("shop.tables.table_form.description")}
          </DialogDescription>
        </DialogHeader>

        {/* Remount the form per open/target so useState initializers reseed it
            without a setState-in-effect. */}
        {open && (
          <TableForm
            key={table?.id ?? `new-${defaultZoneId ?? ""}`}
            shopSlug={shopSlug}
            zones={zones}
            table={table}
            defaultZoneId={defaultZoneId}
            onOpenChange={onOpenChange}
          />
        )}
      </DialogContent>
    </Dialog>
  );
}

interface TableFormProps {
  shopSlug: string;
  zones: ZoneResource[];
  table?: TableResource | null;
  defaultZoneId?: string;
  onOpenChange: (open: boolean) => void;
}

function TableForm({ shopSlug, zones, table, defaultZoneId, onOpenChange }: TableFormProps) {
  const { t } = useTranslation();
  const createTable = useCreateTable(shopSlug);
  const updateTable = useUpdateTable(shopSlug);
  const isEdit = !!table;
  const isPending = createTable.isPending || updateTable.isPending;

  const [zoneId, setZoneId] = useState(table?.zone.id ?? defaultZoneId ?? zones[0]?.id ?? "");
  const [code, setCode] = useState(table?.code ?? "");
  const [name, setName] = useState(table?.name ?? "");
  const [seatCount, setSeatCount] = useState(String(table?.seat_count ?? 4));

  const noZones = zones.length === 0;
  const seats = Number.parseInt(seatCount, 10);
  const isValid =
    !noZones &&
    zoneId.length > 0 &&
    code.trim().length > 0 &&
    Number.isFinite(seats) &&
    seats >= 1 &&
    seats <= 1000;

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!isValid || isPending) return;

    const payload = {
      zone_id: zoneId,
      code: code.trim(),
      name: name.trim() || null,
      seat_count: seats,
    };

    if (isEdit && table) {
      updateTable.mutate(
        { id: table.id, data: payload },
        { onSuccess: () => onOpenChange(false) }
      );
    } else {
      createTable.mutate(payload, { onSuccess: () => onOpenChange(false) });
    }
  };

  return (
    <form onSubmit={handleSubmit} className="grid gap-3">
      <div className="grid gap-1.5">
        <label htmlFor="table-zone" className="text-xs font-medium">
          {t("shop.tables.table_form.zone")} <span className="text-destructive">*</span>
        </label>
        <select
          id="table-zone"
          required
          disabled={noZones}
          value={zoneId}
          onChange={(e) => setZoneId(e.target.value)}
          className={cn(
            "h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs transition-colors",
            "focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none",
            "disabled:cursor-not-allowed disabled:opacity-50",
            "dark:bg-input/30"
          )}
        >
          {noZones && (
            <option value="" disabled>
              {t("shop.tables.table_form.zone_placeholder")}
            </option>
          )}
          {zones.map((z) => (
            <option key={z.id} value={z.id}>
              {z.code} — {z.name}
            </option>
          ))}
        </select>
        {noZones && (
          <p className="text-[11px] text-muted-foreground">
            {t("shop.tables.table_form.zone_required_hint")}
          </p>
        )}
      </div>

      <div className="grid gap-1.5">
        <label htmlFor="table-code" className="text-xs font-medium">
          {t("shop.tables.table_form.code")} <span className="text-destructive">*</span>
        </label>
        <Input
          id="table-code"
          autoFocus
          required
          maxLength={50}
          value={code}
          onChange={(e) => setCode(e.target.value)}
          placeholder={t("shop.tables.table_form.code_placeholder")}
        />
        <p className="text-[11px] text-muted-foreground">
          {t("shop.tables.table_form.code_help")}
        </p>
      </div>

      <div className="grid gap-1.5">
        <label htmlFor="table-name" className="text-xs font-medium">
          {t("shop.tables.table_form.name")}
        </label>
        <Input
          id="table-name"
          maxLength={255}
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder={t("shop.tables.table_form.name_placeholder")}
        />
      </div>

      <div className="grid gap-1.5">
        <label htmlFor="table-seats" className="text-xs font-medium">
          {t("shop.tables.table_form.seat_count")} <span className="text-destructive">*</span>
        </label>
        <Input
          id="table-seats"
          type="number"
          required
          min={1}
          max={1000}
          value={seatCount}
          onChange={(e) => setSeatCount(e.target.value)}
        />
        <p className="text-[11px] text-muted-foreground">
          {t("shop.tables.table_form.seat_count_help")}
        </p>
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
