"use client";

import { useEffect, useState } from "react";
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
import { useCreateZone } from "@/hooks/api/use-zones";

export interface ZoneFormDialogProps {
  shopSlug: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

/**
 * Modal form for creating a Zone. Replaces the previous window.prompt() chain.
 *
 * Validation matches the backend FormRequest:
 *   - code: required, max 50, alphanumeric + hyphens, unique per shop
 *   - name: required, max 255
 *   - description: optional
 *   - display_order: optional, integer ≥ 0
 *
 * Server-side errors surface via the toast inside useCreateZone().
 */
export function ZoneFormDialog({ shopSlug, open, onOpenChange }: ZoneFormDialogProps) {
  const { t } = useTranslation();
  const createZone = useCreateZone(shopSlug);

  const [code, setCode] = useState("");
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [displayOrder, setDisplayOrder] = useState("0");

  // Reset form whenever the dialog (re)opens. Avoids stale data after a
  // successful submit followed by a fresh open.
  useEffect(() => {
    if (open) {
      setCode("");
      setName("");
      setDescription("");
      setDisplayOrder("0");
    }
  }, [open]);

  const isValid = code.trim().length > 0 && name.trim().length > 0;

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!isValid || createZone.isPending) return;

    createZone.mutate(
      {
        code: code.trim(),
        name: name.trim(),
        description: description.trim() || null,
        display_order: Number.parseInt(displayOrder, 10) || 0,
      },
      {
        onSuccess: () => onOpenChange(false),
      }
    );
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t("shop.tables.zone_form.title")}</DialogTitle>
          <DialogDescription>{t("shop.tables.zone_form.description")}</DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="grid gap-3">
          <div className="grid gap-1.5">
            <label htmlFor="zone-code" className="text-xs font-medium">
              {t("shop.tables.zone_form.code")} <span className="text-destructive">*</span>
            </label>
            <Input
              id="zone-code"
              autoFocus
              required
              maxLength={50}
              value={code}
              onChange={(e) => setCode(e.target.value)}
              placeholder={t("shop.tables.zone_form.code_placeholder")}
            />
            <p className="text-[11px] text-muted-foreground">
              {t("shop.tables.zone_form.code_help")}
            </p>
          </div>

          <div className="grid gap-1.5">
            <label htmlFor="zone-name" className="text-xs font-medium">
              {t("shop.tables.zone_form.name")} <span className="text-destructive">*</span>
            </label>
            <Input
              id="zone-name"
              required
              maxLength={255}
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={t("shop.tables.zone_form.name_placeholder")}
            />
          </div>

          <div className="grid gap-1.5">
            <label htmlFor="zone-description" className="text-xs font-medium">
              {t("shop.tables.zone_form.description_field")}
            </label>
            <Input
              id="zone-description"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              placeholder={t("shop.tables.zone_form.description_placeholder")}
            />
          </div>

          <div className="grid gap-1.5">
            <label htmlFor="zone-display-order" className="text-xs font-medium">
              {t("shop.tables.zone_form.display_order")}
            </label>
            <Input
              id="zone-display-order"
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
              disabled={createZone.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button type="submit" size="sm" disabled={!isValid || createZone.isPending}>
              {createZone.isPending ? t("common.creating") : t("common.create")}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
