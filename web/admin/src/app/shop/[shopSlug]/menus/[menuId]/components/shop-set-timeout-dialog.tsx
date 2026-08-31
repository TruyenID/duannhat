"use client";

import { useState } from "react";
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Label,
  Spinner,
} from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";

export interface ShopSetTimeoutDialogProps {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  hqBrandTimeoutMinutes: number | null;
  hqMenuTimeoutMinutes: number | null;
  shopDefaultTimeoutMinutes: number | null;
  shopMenuTimeoutMinutes: number | null;
  effectiveTimeoutMinutes: number | null;
  isPending: boolean;
  onSave: (minutes: number | null) => void;
}

export function ShopSetTimeoutDialog({
  open,
  onOpenChange,
  hqBrandTimeoutMinutes,
  hqMenuTimeoutMinutes,
  shopDefaultTimeoutMinutes,
  shopMenuTimeoutMinutes,
  effectiveTimeoutMinutes,
  isPending,
  onSave,
}: ShopSetTimeoutDialogProps) {
  const { t } = useTranslation();

  const [mode, setMode] = useState<"inherit" | "custom">(
    shopMenuTimeoutMinutes != null ? "custom" : "inherit"
  );
  const [raw, setRaw] = useState(
    shopMenuTimeoutMinutes != null ? String(shopMenuTimeoutMinutes) : ""
  );

  function handleOpenChange(o: boolean) {
    if (!o) {
      setMode(shopMenuTimeoutMinutes != null ? "custom" : "inherit");
      setRaw(shopMenuTimeoutMinutes != null ? String(shopMenuTimeoutMinutes) : "");
    }
    onOpenChange(o);
  }

  const parsed = raw.trim() === "" ? null : parseInt(raw, 10);
  const isValid =
    mode === "inherit" ||
    (raw.trim() !== "" && parsed != null && !Number.isNaN(parsed) && parsed >= 1);

  // Inherited default = highest-priority tier excluding ④ (shop per-menu)
  const inheritedDefault =
    shopDefaultTimeoutMinutes ?? hqMenuTimeoutMinutes ?? hqBrandTimeoutMinutes;
  const defaultInfoKey =
    shopDefaultTimeoutMinutes != null
      ? "shop.menus.timeout.shop_default_info"
      : "shop.menus.timeout.hq_default_info";

  function handleSave() {
    if (!isValid) return;
    onSave(mode === "inherit" ? null : parsed!);
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-sm" aria-describedby="shop-timeout-dialog-desc">
        <DialogHeader>
          <DialogTitle>{t("shop.menus.timeout.dialog_title")}</DialogTitle>
          <DialogDescription id="shop-timeout-dialog-desc" className="sr-only">
            {t("shop.menus.timeout.dialog_title")}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-1">
          {/* Default source info */}
          <p className="text-sm text-muted-foreground">
            {inheritedDefault != null
              ? t(defaultInfoKey, { minutes: String(inheritedDefault) })
              : t("shop.menus.timeout.hq_default_info_empty")}
          </p>

          {/* Radio — inherit */}
          <label className="flex cursor-pointer items-start gap-2.5">
            <input
              type="radio"
              name="shop-timeout-mode"
              value="inherit"
              checked={mode === "inherit"}
              onChange={() => setMode("inherit")}
              className="mt-0.5 accent-primary"
            />
            <span className="text-sm">
              {inheritedDefault != null
                ? t("shop.menus.timeout.use_inherited_radio", { minutes: String(inheritedDefault) })
                : t("shop.menus.timeout.use_inherited_radio_empty")}
            </span>
          </label>

          {/* Radio — custom */}
          <div className="space-y-2">
            <label className="flex cursor-pointer items-start gap-2.5">
              <input
                type="radio"
                name="shop-timeout-mode"
                value="custom"
                checked={mode === "custom"}
                onChange={() => setMode("custom")}
                className="mt-0.5 accent-primary"
              />
              <span className="text-sm">{t("shop.menus.timeout.custom_radio")}</span>
            </label>

            {mode === "custom" && (
              <div className="ml-6 space-y-1.5">
                <div className="flex items-center gap-2">
                  <Input
                    id="shop-timeout-input"
                    type="number"
                    min={1}
                    step={1}
                    value={raw}
                    onChange={(e) => setRaw(e.target.value)}
                    className={`w-24 tabular-nums${!isValid ? "border-destructive" : ""}`}
                    placeholder="30"
                  />
                  <span className="text-sm text-muted-foreground">
                    {t("common.timeout.minutes_unit_long")}
                  </span>
                </div>
                {!isValid && (
                  <p className="text-xs text-destructive">{t("shop.menus.timeout.minutes_hint")}</p>
                )}
              </div>
            )}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" size="sm" onClick={() => handleOpenChange(false)}>
            {t("common.cancel")}
          </Button>
          <Button size="sm" disabled={!isValid || isPending} onClick={handleSave}>
            {isPending && <Spinner className="mr-1.5 size-3.5" />}
            {t("common.save")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
