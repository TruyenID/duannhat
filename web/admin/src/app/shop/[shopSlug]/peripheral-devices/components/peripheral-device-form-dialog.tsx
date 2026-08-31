"use client";

import { useEffect, useMemo, useState } from "react";
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Badge,
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  MultiCombobox,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Switch,
} from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { ApiError } from "@/lib/api";
import type { CreatePeripheralDeviceInput } from "@/services/peripheral-device-service";
import type { PeripheralDevice, PeripheralDeviceType } from "@/types/models/PeripheralDevice";
import {
  isNetworkPeripheralType,
  DEPOSIT_TIMEOUT_DEFAULT_SECONDS,
  GLORY_MODELS,
  tenderTemplateForModel,
} from "@/types/models/PeripheralDevice";
import { useTillTenderActivation } from "@/hooks/api/use-till-tender-activation";
import type { TillTenderType } from "@/services/till-tender-type-service";

/** Types offered in the UI — physical peripherals an operator registers here. */
const SELECTABLE_TYPES: PeripheralDeviceType[] = [
  "payment_terminal",
  "coin_changer",
  "receipt_printer",
  "kitchen_printer",
  "bar_printer",
];

export interface PeripheralDeviceFormDialogProps {
  shopSlug: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  device?: PeripheralDevice | null;
  onSubmit: (data: CreatePeripheralDeviceInput) => Promise<unknown>;
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
      {children}
      {hint && !error && <span className="text-xs text-muted-foreground">{hint}</span>}
      {error && <span className="text-xs text-destructive">{error}</span>}
    </div>
  );
}

function tenderDisplayName(row: TillTenderType, locale: string): string {
  const name = row.name as unknown;
  if (typeof name === "string") return name;
  if (name && typeof name === "object") {
    const map = name as Record<string, string>;
    return map[locale] ?? map.ja ?? map.en ?? Object.values(map)[0] ?? row.tender_key;
  }
  return row.tender_key;
}

export function PeripheralDeviceFormDialog({
  shopSlug,
  open,
  onOpenChange,
  device,
  onSubmit,
}: PeripheralDeviceFormDialogProps) {
  const { t, locale } = useTranslation();
  const isEdit = !!device;

  const [name, setName] = useState("");
  const [type, setType] = useState<PeripheralDeviceType>("payment_terminal");
  const [host, setHost] = useState("");
  const [port, setPort] = useState("");
  const [model, setModel] = useState("");
  const [depositTimeout, setDepositTimeout] = useState("");
  const [accepts, setAccepts] = useState<string[]>([]);
  const [acceptsTouched, setAcceptsTouched] = useState(false);
  const [isActive, setIsActive] = useState(true);
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [confirmExitOpen, setConfirmExitOpen] = useState(false);
  const [dirty, setDirty] = useState(false);

  // #1156 — accepts editor options come from the branch's EFFECTIVE tender
  // list (server-sourced; never hardcoded). Only fetch while the dialog is
  // open for a type that carries accepts.
  const isNetwork = isNetworkPeripheralType(type);
  const tendersQuery = useTillTenderActivation(open && isNetwork ? shopSlug : "");
  const tenderOptions = useMemo(
    () =>
      (tendersQuery.data?.data ?? [])
        .filter((row) => row.is_active)
        .map((row) => ({
          value: row.tender_key,
          label: `${tenderDisplayName(row, locale)} (${row.tender_key})`,
        })),
    [tendersQuery.data, locale]
  );

  useEffect(() => {
    if (open) {
      setName(device?.name ?? "");
      setType(device?.type ?? "payment_terminal");
      setHost(device?.metadata?.host ?? "");
      setPort(device?.metadata?.port != null ? String(device.metadata.port) : "");
      setModel(
        device?.metadata?.model ?? (device?.type === "coin_changer" ? "RT-R08" : "")
      );
      setDepositTimeout(
        device?.metadata?.deposit_timeout_seconds != null
          ? String(device.metadata.deposit_timeout_seconds)
          : ""
      );
      setAccepts(device?.metadata?.accepts ?? []);
      setAcceptsTouched(false);
      setIsActive(device?.is_active ?? true);
      setErrors({});
      setDirty(false);
    }
  }, [open, device]);

  // When the type flips, keep the model default sensible for the coin_changer
  // select (free text for terminals).
  useEffect(() => {
    if (!open) return;
    if (type === "coin_changer" && !GLORY_MODELS.includes(model)) {
      setModel(device?.metadata?.model && device.type === "coin_changer" ? device.metadata.model : "RT-R08");
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [type, open]);

  /**
   * Template prefill hint (#1156): on CREATE of a payment_terminal whose model
   * matches a vendor template while accepts is still empty/untouched, the
   * backend prefills accepts — surface exactly what it will apply
   * (template ∩ branch's active tenders).
   */
  const template = type === "payment_terminal" ? tenderTemplateForModel(model) : null;
  const activeTenderKeys = useMemo(
    () => new Set(tenderOptions.map((o) => o.value)),
    [tenderOptions]
  );
  const templatePrefill = useMemo(
    () => (template ? template.accepts.filter((key) => activeTenderKeys.has(key)) : []),
    [template, activeTenderKeys]
  );
  const showTemplateHint =
    !isEdit && template !== null && !acceptsTouched && accepts.length === 0 && templatePrefill.length > 0;

  function requestClose() {
    if (dirty && !saving) {
      setConfirmExitOpen(true);
    } else {
      onOpenChange(false);
    }
  }

  const handleSubmit = async () => {
    setErrors({});

    // `Number("600s")` is NaN, and JSON.stringify writes NaN as `null`. The
    // Cloud rule is `nullable`, so the row saved, the toast said success, and
    // the deposit window silently stayed at the workstation's 300s default —
    // on the next slow customer the 釣銭機 times out and KEEPS their cash.
    // Refuse here, where the operator can still see the field.
    if (type === "coin_changer" && depositTimeout.trim()) {
      const secs = Number(depositTimeout.trim());
      if (!Number.isInteger(secs) || secs < 30 || secs > 86400) {
        setErrors({
          "metadata.deposit_timeout_seconds": t(
            "shop.peripherals.field.deposit_timeout_invalid",
          ),
        });
        return;
      }
    }

    setSaving(true);
    try {
      let metadata: CreatePeripheralDeviceInput["metadata"];
      if (isNetwork) {
        metadata = {
          host: host.trim(),
          ...(port.trim() ? { port: Number(port) } : {}),
          ...(model.trim() ? { model: model.trim() } : {}),
          // #2422 — blank means "use the workstation default (300s)", so send
          // nothing rather than 0: the Glory API reads 0 as "wait forever".
          ...(type === "coin_changer" && depositTimeout.trim()
            ? { deposit_timeout_seconds: Number(depositTimeout) }
            : {}),
          // Omit `accepts` entirely when the operator never touched it and
          // none existed — on create that lets the backend template-prefill;
          // an explicit (even empty) list always wins.
          ...(acceptsTouched || accepts.length > 0 || device?.metadata?.accepts
            ? { accepts }
            : {}),
        };
      }

      await onSubmit({
        name: name.trim(),
        type,
        is_active: isActive,
        ...(metadata ? { metadata } : {}),
      });
      onOpenChange(false);
    } catch (e) {
      if (e instanceof ApiError && e.status === 422) {
        const fieldErrors: Record<string, string> = {};
        const body = e.body as { errors?: Record<string, string[]> };
        if (body.errors) {
          for (const [field, msgs] of Object.entries(body.errors)) {
            // metadata.accepts.0 → metadata.accepts
            const key = field.startsWith("metadata.accepts") ? "metadata.accepts" : field;
            fieldErrors[key] = msgs[0];
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
        <DialogContent className="sm:max-w-md" data-slot="peripheral-device-form-dialog">
          <DialogHeader>
            <DialogTitle>
              {isEdit ? t("common.edit") : t("shop.peripherals.register")}
            </DialogTitle>
            <DialogDescription>
              {isEdit ? device.name : t("shop.peripherals.register_desc")}
            </DialogDescription>
          </DialogHeader>

          <div className="flex max-h-[65vh] flex-col gap-3 overflow-y-auto py-2 pr-1">
            <Field label={t("shop.peripherals.field.type")} required error={errors.type}>
              <Select
                value={type}
                onValueChange={(v) => {
                  setDirty(true);
                  setType(v as PeripheralDeviceType);
                }}
              >
                <SelectTrigger aria-invalid={!!errors.type}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {SELECTABLE_TYPES.map((pt) => (
                    <SelectItem key={pt} value={pt}>
                      {t(`shop.peripherals.type.${pt}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>

            <Field label={t("common.name")} required error={errors.name}>
              <Input
                value={name}
                onChange={(e) => {
                  setDirty(true);
                  setName(e.target.value);
                }}
                aria-invalid={!!errors.name}
                placeholder={t("shop.peripherals.field.name_placeholder")}
              />
            </Field>

            {isNetwork && (
              <>
                {type === "coin_changer" ? (
                  <>
                    <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                      {t("shop.peripherals.coin_changer_hint")}
                    </p>
                    <Field label={t("shop.peripherals.field.model")}>
                      <Select
                        value={model}
                        onValueChange={(v) => {
                          setDirty(true);
                          setModel(v);
                        }}
                      >
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {GLORY_MODELS.map((m) => (
                            <SelectItem key={m} value={m}>
                              {m}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </Field>
                  </>
                ) : (
                  <Field
                    label={t("shop.peripherals.field.terminal_model")}
                    error={errors["metadata.model"]}
                    hint={t("shop.peripherals.field.terminal_model_hint")}
                  >
                    <Input
                      value={model}
                      onChange={(e) => {
                        setDirty(true);
                        setModel(e.target.value);
                      }}
                      placeholder="stera terminal"
                    />
                  </Field>
                )}

                <Field
                  label={t("shop.peripherals.field.host")}
                  required
                  error={errors["metadata.host"]}
                  hint={t("shop.peripherals.field.host_hint")}
                >
                  <Input
                    value={host}
                    onChange={(e) => {
                      setDirty(true);
                      setHost(e.target.value);
                    }}
                    aria-invalid={!!errors["metadata.host"]}
                    placeholder="192.168.0.77"
                    inputMode="decimal"
                  />
                </Field>

                <Field
                  label={t("shop.peripherals.field.port")}
                  error={errors["metadata.port"]}
                  hint={t("shop.peripherals.field.port_hint")}
                >
                  <Input
                    value={port}
                    onChange={(e) => {
                      setDirty(true);
                      setPort(e.target.value);
                    }}
                    aria-invalid={!!errors["metadata.port"]}
                    placeholder={type === "coin_changer" ? "80" : "8888"}
                    inputMode="numeric"
                  />
                </Field>

                {/* #2422 — 釣銭機 only. On timeout the machine KEEPS the cash,
                    so the shop decides how long it waits. Blank = 300s. */}
                {type === "coin_changer" && (
                  <Field
                    label={t("shop.peripherals.field.deposit_timeout")}
                    error={errors["metadata.deposit_timeout_seconds"]}
                    hint={t("shop.peripherals.field.deposit_timeout_hint")}
                  >
                    <Input
                      value={depositTimeout}
                      onChange={(e) => {
                        setDirty(true);
                        setDepositTimeout(e.target.value);
                      }}
                      aria-invalid={!!errors["metadata.deposit_timeout_seconds"]}
                      placeholder={String(DEPOSIT_TIMEOUT_DEFAULT_SECONDS)}
                      inputMode="numeric"
                    />
                  </Field>
                )}

                {/* #1156 — accepts editor: subset of the branch's tender vocabulary */}
                <Field
                  label={t("shop.peripherals.field.accepts")}
                  error={errors["metadata.accepts"]}
                  hint={
                    showTemplateHint
                      ? undefined
                      : t("shop.peripherals.field.accepts_hint")
                  }
                >
                  <div data-slot="accepts-editor" className="flex flex-col gap-2">
                    <MultiCombobox
                      options={tenderOptions}
                      value={accepts}
                      onChange={(next) => {
                        setDirty(true);
                        setAcceptsTouched(true);
                        setAccepts(next);
                      }}
                      placeholder={t("shop.peripherals.field.accepts_placeholder")}
                      searchPlaceholder={t("common.search") + "..."}
                      emptyText={t("shop.peripherals.field.accepts_empty")}
                      disabled={tendersQuery.isLoading}
                      error={errors["metadata.accepts"]}
                    />
                    {accepts.length > 0 && (
                      <div className="flex flex-wrap gap-1">
                        {accepts.map((key) => (
                          <Badge key={key} variant="secondary" className="h-5 font-mono text-[10px]">
                            {key}
                          </Badge>
                        ))}
                      </div>
                    )}
                  </div>
                </Field>

                {showTemplateHint && (
                  <div
                    data-slot="accepts-template-hint"
                    className="rounded-md border border-dashed bg-muted/40 px-3 py-2 text-xs"
                    role="note"
                  >
                    <p className="text-muted-foreground">
                      {t("shop.peripherals.accepts_template_hint", {
                        model: template?.slug ?? "",
                      })}
                    </p>
                    <div className="mt-1.5 flex flex-wrap gap-1">
                      {templatePrefill.map((key) => (
                        <Badge key={key} variant="outline" className="h-5 font-mono text-[10px]">
                          {key}
                        </Badge>
                      ))}
                    </div>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      className="mt-2 h-6 text-xs"
                      onClick={() => {
                        setDirty(true);
                        setAcceptsTouched(true);
                        setAccepts(templatePrefill);
                      }}
                    >
                      {t("shop.peripherals.accepts_apply_template")}
                    </Button>
                  </div>
                )}
              </>
            )}

            <div className="flex items-center justify-between rounded-md border border-border px-3 py-2">
              <span className="text-xs font-medium">{t("shop.peripherals.field.is_active")}</span>
              <Switch
                checked={isActive}
                onCheckedChange={(v) => {
                  setDirty(true);
                  setIsActive(v);
                }}
              />
            </div>
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
