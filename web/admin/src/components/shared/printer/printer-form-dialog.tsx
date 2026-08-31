"use client";

import { useEffect, useState } from "react";
import {
  AlertDialog,
  AlertDialogAction,
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
import type { Printer } from "@/types/models/Printer";
import {
  PrinterConnectionType,
  getPrinterConnectionTypeLabel,
} from "@/types/models/enum/PrinterConnectionType";
import { PrinterCutType, getPrinterCutTypeLabel } from "@/types/models/enum/PrinterCutType";
import { PrinterRole, getPrinterRoleLabel } from "@/types/models/enum/PrinterRole";

const PAPER_WIDTHS = [58, 80] as const;

export interface PrinterFormDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  printer?: Printer | null;
  onSubmit: (data: {
    name: string;
    roles: PrinterRole[];
    connection_type: PrinterConnectionType;
    address: string;
    paper_width: number;
    cut_type: PrinterCutType;
    notes?: string | null;
  }) => Promise<unknown>;
}

function Field({
  label,
  required,
  error,
  hint,
  children,
}: {
  label: string;
  required?: boolean;
  error?: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-col gap-1">
      <label className="text-xs font-medium text-muted-foreground">
        {label}
        {required && <span className="ml-0.5 text-destructive">*</span>}
      </label>
      {hint && <span className="text-xs text-muted-foreground">{hint}</span>}
      {children}
      {error && <span className="text-xs text-destructive">{error}</span>}
    </div>
  );
}

export function PrinterFormDialog({
  open,
  onOpenChange,
  printer,
  onSubmit,
}: PrinterFormDialogProps) {
  const { t, locale } = useTranslation();
  const isEdit = !!printer;

  const [name, setName] = useState("");
  const [roles, setRoles] = useState<PrinterRole[]>([PrinterRole.KitchenPrinter]);
  const [connectionType, setConnectionType] = useState<PrinterConnectionType>(
    PrinterConnectionType.Network
  );
  const [address, setAddress] = useState("");
  const [paperWidth, setPaperWidth] = useState<number>(80);
  const [cutType, setCutType] = useState<PrinterCutType>(PrinterCutType.Full);
  const [notes, setNotes] = useState("");
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);
  const [dirty, setDirty] = useState(false);

  useEffect(() => {
    if (open) {
      setName(printer?.name ?? "");
      setRoles(
        Array.isArray(printer?.roles) && printer.roles.length > 0
          ? (printer.roles as PrinterRole[])
          : [PrinterRole.KitchenPrinter]
      );
      setConnectionType(
        (printer?.connection_type as PrinterConnectionType) ?? PrinterConnectionType.Network
      );
      setAddress(printer?.address ?? "");
      setPaperWidth(Number(printer?.paper_width ?? 80));
      setCutType((printer?.cut_type as PrinterCutType) ?? PrinterCutType.Full);
      setNotes(printer?.notes ?? "");
      setErrors({});
      setDirty(false);
    }
  }, [open, printer]);

  function requestClose() {
    if (dirty && !saving) {
      setConfirmExitOpen(true);
    } else {
      onOpenChange(false);
    }
  }

  /** A printer may hold several roles at once — toggle, don't replace. */
  function toggleRole(role: PrinterRole) {
    setDirty(true);
    setRoles((prev) => (prev.includes(role) ? prev.filter((r) => r !== role) : [...prev, role]));
  }

  const isNetwork = connectionType === PrinterConnectionType.Network;

  const handleSubmit = async () => {
    setErrors({});

    // Mirror the server rule locally so the operator gets the message without
    // a round-trip; the API enforces it regardless (BR-P02).
    if (roles.length === 0) {
      setErrors({ roles: t("printer.no_role_selected") });
      return;
    }

    setSaving(true);
    try {
      await onSubmit({
        name: name.trim(),
        roles,
        connection_type: connectionType,
        address: address.trim(),
        paper_width: paperWidth,
        cut_type: cutType,
        notes: notes.trim() || null,
      });
      onOpenChange(false);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        const fieldErrors: Record<string, string> = {};
        const body = e.body as { errors?: Record<string, string[]> };
        if (body.errors) {
          for (const [field, msgs] of Object.entries(body.errors)) {
            // Laravel reports per-element errors as `roles.0`; surface them on
            // the `roles` field so the message is visible next to the picker.
            fieldErrors[field.startsWith("roles.") ? "roles" : field] = msgs[0];
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
          if (next) {
            onOpenChange(true);
          } else {
            requestClose();
          }
        }}
      >
        <DialogContent className="sm:max-w-md" data-slot="printer-form-dialog">
          <DialogHeader>
            <DialogTitle>{isEdit ? t("common.edit") : t("common.create")}</DialogTitle>
            <DialogDescription>
              {isEdit ? printer.name : t("printer.dialog_description")}
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
                placeholder="Kitchen Printer"
              />
            </Field>

            <Field
              label={t("printer.roles")}
              required
              error={errors.roles}
              hint={t("printer.roles_help")}
            >
              <div className="flex flex-wrap gap-2">
                {Object.values(PrinterRole).map((role) => {
                  const active = roles.includes(role);
                  return (
                    <button
                      key={role}
                      type="button"
                      onClick={() => toggleRole(role)}
                      aria-pressed={active}
                      className={
                        "rounded-lg border px-3 py-1.5 text-sm transition-colors " +
                        (active
                          ? "border-primary bg-primary text-primary-foreground"
                          : "border-border bg-muted text-muted-foreground hover:border-primary/50")
                      }
                    >
                      {getPrinterRoleLabel(role, locale)}
                    </button>
                  );
                })}
              </div>
            </Field>

            <Field label={t("printer.connection")} required error={errors.connection_type}>
              <Select
                value={connectionType}
                onValueChange={(v) => {
                  setDirty(true);
                  setConnectionType(v as PrinterConnectionType);
                }}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {Object.values(PrinterConnectionType).map((ct) => (
                    <SelectItem key={ct} value={ct}>
                      {getPrinterConnectionTypeLabel(ct, locale)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>

            <Field
              label={isNetwork ? t("printer.address_network") : t("printer.address_usb")}
              required
              error={errors.address}
              hint={isNetwork ? t("printer.address_network_help") : t("printer.address_usb_help")}
            >
              <Input
                value={address}
                onChange={(e) => {
                  setDirty(true);
                  setAddress(e.target.value);
                }}
                placeholder={isNetwork ? "192.168.1.100:9100" : "/dev/usb/lp0"}
              />
            </Field>

            <Field label={t("printer.paper_width")} error={errors.paper_width}>
              <Select
                value={String(paperWidth)}
                onValueChange={(v) => {
                  setDirty(true);
                  setPaperWidth(Number(v));
                }}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {PAPER_WIDTHS.map((w) => (
                    <SelectItem key={w} value={String(w)}>
                      {w} mm
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>

            <Field label={t("printer.cut_type")} error={errors.cut_type}>
              <Select
                value={cutType}
                onValueChange={(v) => {
                  setDirty(true);
                  setCutType(v as PrinterCutType);
                }}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {Object.values(PrinterCutType).map((ct) => (
                    <SelectItem key={ct} value={ct}>
                      {getPrinterCutTypeLabel(ct, locale)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>

            <Field label={t("printer.notes")} error={errors.notes}>
              <Input
                value={notes}
                onChange={(e) => {
                  setDirty(true);
                  setNotes(e.target.value);
                }}
              />
            </Field>
          </div>

          <DialogFooter>
            <Button variant="ghost" onClick={requestClose} disabled={saving}>
              {t("common.cancel")}
            </Button>
            <Button
              color="primary"
              onClick={handleSubmit}
              disabled={saving || !name.trim() || !address.trim() || roles.length === 0}
            >
              {saving && <Spinner className="mr-2 size-4" />}
              {isEdit ? t("common.save") : t("common.create")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <AlertDialog open={confirmExitOpen} onOpenChange={setConfirmExitOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("common.discard_changes")}</AlertDialogTitle>
            <AlertDialogDescription>{t("common.discard_changes_desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("common.cancel")}</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                setConfirmExitOpen(false);
                onOpenChange(false);
              }}
            >
              {t("common.discard")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
