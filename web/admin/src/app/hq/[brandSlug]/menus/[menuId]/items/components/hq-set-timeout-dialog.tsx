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

export interface HqSetTimeoutDialogProps {
  open: boolean;
  onOpenChange: (o: boolean) => void;
  hqMenuTimeoutMinutes: number | null;
  hqBrandTimeoutMinutes: number | null;
  isPending: boolean;
  onSave: (minutes: number | null) => void;
}

export function HqSetTimeoutDialog({
  open,
  onOpenChange,
  hqMenuTimeoutMinutes,
  hqBrandTimeoutMinutes,
  isPending,
  onSave,
}: HqSetTimeoutDialogProps) {
  const { t } = useTranslation();

  const [mode, setMode] = useState<"default" | "custom">(
    hqMenuTimeoutMinutes != null ? "custom" : "default"
  );
  const [raw, setRaw] = useState(hqMenuTimeoutMinutes != null ? String(hqMenuTimeoutMinutes) : "");

  function handleOpenChange(o: boolean) {
    if (!o) {
      setMode(hqMenuTimeoutMinutes != null ? "custom" : "default");
      setRaw(hqMenuTimeoutMinutes != null ? String(hqMenuTimeoutMinutes) : "");
    }
    onOpenChange(o);
  }

  const parsed = raw.trim() === "" ? null : parseInt(raw, 10);
  const isValid =
    mode === "default" ||
    (raw.trim() !== "" && parsed != null && !Number.isNaN(parsed) && parsed >= 1);

  function handleSave() {
    if (!isValid) return;
    onSave(mode === "default" ? null : parsed!);
    onOpenChange(false);
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-sm" aria-describedby="timeout-dialog-desc">
        <DialogHeader>
          <DialogTitle>{t("hq.menus.timeout.dialog_title")}</DialogTitle>
          <DialogDescription id="timeout-dialog-desc">
            {t("hq.menus.timeout.dialog_description")}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-1">
          {/* Radio — use default */}
          <label className="flex cursor-pointer items-start gap-2.5">
            <input
              type="radio"
              name="timeout-mode"
              value="default"
              checked={mode === "default"}
              onChange={() => setMode("default")}
              className="mt-0.5 accent-primary"
            />
            <div className="space-y-0.5">
              <span className="text-sm font-medium">{t("hq.menus.timeout.use_default_radio")}</span>
              {hqBrandTimeoutMinutes != null && (
                <p className="text-xs text-muted-foreground">
                  {t("hq.menus.timeout.use_default_hint", { minutes: hqBrandTimeoutMinutes })}
                </p>
              )}
            </div>
          </label>

          {/* Radio — custom */}
          <label className="flex cursor-pointer items-start gap-2.5">
            <input
              type="radio"
              name="timeout-mode"
              value="custom"
              checked={mode === "custom"}
              onChange={() => setMode("custom")}
              className="mt-0.5 accent-primary"
            />
            <span className="text-sm font-medium">{t("hq.menus.timeout.custom_radio")}</span>
          </label>

          {/* Input — only shown when custom */}
          {mode === "custom" && (
            <div className="ml-6 space-y-1.5">
              <Label htmlFor="hq-timeout-input" className="text-xs">
                {t("hq.menus.timeout.custom_label")}
              </Label>
              <div className="flex items-center gap-2">
                <Input
                  id="hq-timeout-input"
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
                <p className="text-xs text-destructive">{t("hq.menus.timeout.minutes_hint")}</p>
              )}
            </div>
          )}
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
