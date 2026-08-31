"use client";

import { useEffect, useState } from "react";
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { ApiError } from "@/lib/api";
import type { Device } from "@/types/models/Device";
import { DeviceType } from "@/types/models/enum/DeviceType";
import { getDeviceTypeLabel } from "@/types/models/enum/DeviceType";

interface DeviceFormDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  device?: Device | null;
  branches?: { id: string; name: string }[];
  showBranchSelect?: boolean;
  onSubmit: (data: {
    name: string;
    type: DeviceType;
    branch_id?: string;
    notes?: string | null;
  }) => Promise<unknown>;
}

function Field({
  label,
  required,
  error,
  children,
}: {
  label: string;
  required?: boolean;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1">
      <label className="text-xs font-medium text-muted-foreground">
        {label}
        {required && <span className="ml-0.5 text-destructive">*</span>}
      </label>
      {children}
      {error && <span className="text-xs text-destructive">{error}</span>}
    </div>
  );
}

export function DeviceFormDialog({
  open,
  onOpenChange,
  device,
  branches,
  showBranchSelect = false,
  onSubmit,
}: DeviceFormDialogProps) {
  const { t, locale } = useTranslation();
  const isEdit = !!device;

  const [name, setName] = useState("");
  const [type, setType] = useState<DeviceType>(DeviceType.Tms);
  const [branchId, setBranchId] = useState("");
  const [notes, setNotes] = useState("");
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);
  // Tracks user edits — drives the unsaved-changes guard on close. Set by each
  // field's onChange; the open effect resets it so opening never counts dirty.
  const [dirty, setDirty] = useState(false);

  useEffect(() => {
    if (open) {
      setName(device?.name ?? "");
      setType((device?.type as DeviceType) ?? DeviceType.Tms);
      setBranchId(device?.branch_id != null ? String(device.branch_id) : "");
      setNotes(device?.notes ?? "");
      setErrors({});
      setDirty(false);
    }
  }, [open, device]);

  // Guards every close path (Cancel button, the X, Esc, overlay click — all
  // route through the Dialog's onOpenChange): confirm before discarding a
  // dirty form, otherwise close immediately.
  function requestClose() {
    if (dirty && !saving) {
      setConfirmExitOpen(true);
    } else {
      onOpenChange(false);
    }
  }

  const handleSubmit = async () => {
    setErrors({});
    setSaving(true);
    try {
      await onSubmit({
        name: name.trim(),
        type,
        ...(showBranchSelect && { branch_id: branchId }),
        notes: notes.trim() || null,
      });
      onOpenChange(false);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        const fieldErrors: Record<string, string> = {};
        const body = e.body as { errors?: Record<string, string[]> };
        if (body.errors) {
          for (const [field, msgs] of Object.entries(body.errors)) {
            fieldErrors[field] = msgs[0];
          }
        }
        setErrors(fieldErrors);
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <Dialog
        open={open}
        onOpenChange={(next) => {
          // Intercept close attempts so a dirty form prompts for confirmation;
          // opening (next === true) always passes through.
          if (next) {
            onOpenChange(true);
          } else {
            requestClose();
          }
        }}
      >
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>{isEdit ? t("common.edit") : t("common.create")}</DialogTitle>
            <DialogDescription>
              {isEdit ? device.name : getDeviceTypeLabel(type, locale)}
            </DialogDescription>
          </DialogHeader>

          <div className="flex flex-col gap-3 py-2">
            <Field label={t("common.name")} required error={errors.name}>
              <Input
                value={name}
                onChange={(e) => {
                  setDirty(true);
                  setName(e.target.value);
                }}
                placeholder="TMS-Reception"
              />
            </Field>

            <Field label="Type" required error={errors.type}>
              <Select
                value={type}
                onValueChange={(v) => {
                  setDirty(true);
                  setType(v as DeviceType);
                }}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {Object.values(DeviceType).map((dt) => (
                    <SelectItem key={dt} value={dt}>
                      {getDeviceTypeLabel(dt, locale)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>

            {showBranchSelect && branches && (
              <Field label="Branch" required error={errors.branch_id}>
                <Select
                  value={branchId}
                  onValueChange={(v) => {
                    setDirty(true);
                    setBranchId(v);
                  }}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="-- Select --" />
                  </SelectTrigger>
                  <SelectContent>
                    {branches.map((b) => (
                      <SelectItem key={b.id} value={b.id}>
                        {b.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
            )}

            <Field label="Notes" error={errors.notes}>
              <Input
                value={notes}
                onChange={(e) => {
                  setDirty(true);
                  setNotes(e.target.value);
                }}
                placeholder="Optional notes..."
              />
            </Field>
          </div>

          <DialogFooter>
            <Button variant="outline" size="sm" onClick={requestClose} disabled={saving}>
              {t("common.cancel")}
            </Button>
            <Button size="sm" onClick={handleSubmit} disabled={saving}>
              {saving && <Spinner className="mr-2 size-3.5" />}
              {t("common.save")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Unsaved-changes guard — confirm before discarding a dirty form. */}
      <AlertDialog open={confirmExitOpen} onOpenChange={setConfirmExitOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.products.unsaved.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("hq.products.unsaved.desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={saving}>
              {t("hq.products.unsaved.continue_editing")}
            </AlertDialogCancel>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() => {
                setConfirmExitOpen(false);
                onOpenChange(false);
              }}
              disabled={saving}
            >
              {t("hq.products.unsaved.exit_without_saving")}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
