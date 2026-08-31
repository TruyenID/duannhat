"use client";

import { useState } from "react";
import { Save } from "lucide-react";
import { Button } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Label } from "@godxjp/ui";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";
import { Spinner } from "@godxjp/ui";
import { Switch } from "@godxjp/ui";
import type { TranslatableValue } from "@godxjp/ui";
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from "@godxjp/ui";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { buildI18nPayload, DEFAULT_LOCALE, emptyLocaleMap } from "@/types/models/payload-helpers";
import { useCreateToppingGroup, useUpdateToppingGroup } from "@/hooks/api/use-topping-groups";
import type {
  CreateToppingGroupInput,
  ToppingGroup,
  UpdateToppingGroupInput,
} from "@/services/topping-group-service";

export interface CreateToppingGroupDialogProps {
  brandSlug: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** When set, the dialog edits this group instead of creating a new one:
   *  the form is prefilled from it and submit PATCHes rather than POSTs. */
  group?: ToppingGroup | null;
  /** Fires after a successful create with the freshly-created group. Lets
   *  callers (e.g. inline "+ create" flow on the combo page) auto-select the
   *  new group without waiting for the lookup query to refetch. */
  onCreated?: (group: ToppingGroup) => void;
}

interface FormState {
  name: Record<string, string>;
  selection_type: "single" | "multiple";
  modifier_type: "add" | "remove";
  price_strategy: "flat" | "free_up_to_n";
  free_quantity: string;
  min_select: string;
  max_select: string;
  // OFF (default) ⇒ max_qty_per_item=1 → customer-web renders checkbox/radio
  // for each topping. ON ⇒ admin types a cap (≥2) → customer-web renders +/-
  // counter so customers can stack qty (e.g., 3 eggs).
  allow_multiple_qty: boolean;
  max_qty_per_item: string;
  is_active: boolean;
}

function emptyForm(): FormState {
  return {
    name: emptyLocaleMap(),
    selection_type: "multiple",
    modifier_type: "add",
    price_strategy: "flat",
    free_quantity: "",
    min_select: "0",
    max_select: "",
    allow_multiple_qty: false,
    max_qty_per_item: "10",
    is_active: false,
  };
}

/** Prefill the form from an existing group for edit mode. */
function formFromGroup(group: ToppingGroup): FormState {
  return {
    name: {
      ...emptyLocaleMap(),
      ja: group.translations?.ja?.name ?? group.name ?? "",
      en: group.translations?.en?.name ?? "",
      vi: group.translations?.vi?.name ?? "",
    },
    selection_type: group.selection_type,
    modifier_type: group.modifier_type,
    price_strategy: group.price_strategy,
    free_quantity: group.free_quantity != null ? String(group.free_quantity) : "",
    min_select: String(group.min_select),
    max_select: group.max_select != null ? String(group.max_select) : "",
    allow_multiple_qty: group.max_qty_per_item > 1,
    max_qty_per_item: String(group.max_qty_per_item > 1 ? group.max_qty_per_item : 10),
    is_active: group.is_active,
  };
}

export function CreateToppingGroupDialog({
  brandSlug,
  open,
  onOpenChange,
  group,
  onCreated,
}: CreateToppingGroupDialogProps) {
  const { t } = useTranslation();
  const isEdit = !!group;
  // Lazy-init from the group so edit mode is prefilled on first render. The
  // parent remounts this dialog (via a `key` tied to create vs the edit target),
  // so the state is fresh each time it opens — no reset-in-effect needed.
  const [form, setForm] = useState<FormState>(() => (group ? formFromGroup(group) : emptyForm()));
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const createMutation = useCreateToppingGroup(brandSlug);
  const updateMutation = useUpdateToppingGroup(brandSlug);
  const isPending = createMutation.isPending || updateMutation.isPending;

  function update<K extends keyof FormState>(key: K, value: FormState[K]) {
    setForm((prev) => ({ ...prev, [key]: value }));
    if (fieldErrors[key as string]) {
      setFieldErrors((prev) => {
        const next = { ...prev };
        delete next[key as string];
        return next;
      });
    }
  }

  const effectiveName =
    form.name[DEFAULT_LOCALE]?.trim() || form.name["en"]?.trim() || form.name["vi"]?.trim() || "";
  const canSubmit = effectiveName.length > 0 && !isPending;

  async function handleSubmit() {
    setFieldErrors({});
    const i18n = buildI18nPayload({ name: form.name });
    for (const locale of Object.keys(i18n) as Array<keyof typeof i18n>) {
      if (!i18n[locale]?.name?.trim()) delete i18n[locale];
    }

    const shared = {
      name: effectiveName,
      selection_type: form.selection_type,
      modifier_type: form.modifier_type,
      price_strategy: form.price_strategy,
      free_quantity:
        form.price_strategy === "free_up_to_n" && form.free_quantity.trim()
          ? Number(form.free_quantity)
          : null,
      min_select: Number(form.min_select) || 0,
      max_select: form.max_select.trim() ? Number(form.max_select) : null,
      max_qty_per_item: form.allow_multiple_qty
        ? Math.max(1, Number(form.max_qty_per_item) || 1)
        : 1,
      is_active: form.is_active,
      ...i18n,
    };

    try {
      if (group) {
        await updateMutation.mutateAsync({
          id: group.id,
          data: shared as UpdateToppingGroupInput,
        });
        onOpenChange(false);
        return;
      }

      const result = await createMutation.mutateAsync(shared as CreateToppingGroupInput);
      onOpenChange(false);
      onCreated?.(result.data);
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.body as { errors?: Record<string, string[]> };
        if (body.errors) setFieldErrors(body.errors);
      }
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        data-slot="create-topping-group-dialog"
        className="flex max-h-[90vh] flex-col gap-0 sm:max-w-lg"
      >
        <DialogHeader>
          <DialogTitle>
            {isEdit ? t("hq.topping_groups.edit_title") : t("hq.topping_groups.new_title")}
          </DialogTitle>
        </DialogHeader>

        <div className="flex flex-1 flex-col gap-4 overflow-y-auto px-1 py-4">
          {/* Name (translatable) */}
          <div className="flex flex-col gap-1.5">
            <Label className="text-xs font-medium text-muted-foreground">
              {t("hq.topping_groups.form.name_label")}
              <span className="ml-0.5 text-destructive">*</span>
            </Label>
            <Input
              translatable
              value={form.name as TranslatableValue}
              onChange={(value) => update("name", value as Record<string, string>)}
              maxLength={255}
              autoFocus
              className="h-9"
            />
            {(fieldErrors["ja.name"]?.[0] ?? fieldErrors.name?.[0]) && (
              <p className="text-[11px] text-destructive">
                {fieldErrors["ja.name"]?.[0] ?? fieldErrors.name?.[0]}
              </p>
            )}
          </div>

          <div className="grid grid-cols-2 gap-4 border-t border-dashed pt-4">
            {/* selection_type */}
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs font-medium text-muted-foreground">
                {t("hq.topping_groups.form.selection_type")}
              </Label>
              <Select
                value={form.selection_type}
                onValueChange={(v) => update("selection_type", v as "single" | "multiple")}
              >
                <SelectTrigger className="h-9">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="multiple">
                    {t("hq.topping_groups.selection_type.multiple")}
                  </SelectItem>
                  <SelectItem value="single">
                    {t("hq.topping_groups.selection_type.single")}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* modifier_type */}
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs font-medium text-muted-foreground">
                {t("hq.topping_groups.form.modifier_type")}
              </Label>
              <Select
                value={form.modifier_type}
                onValueChange={(v) => update("modifier_type", v as "add" | "remove")}
              >
                <SelectTrigger className="h-9">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="add">{t("hq.topping_groups.modifier_type.add")}</SelectItem>
                  <SelectItem value="remove">
                    {t("hq.topping_groups.modifier_type.remove")}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* min_select */}
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs font-medium text-muted-foreground">
                {t("hq.topping_groups.form.min_select")}
              </Label>
              <Input
                type="number"
                min={0}
                value={form.min_select}
                onChange={(e) => update("min_select", e.target.value)}
                className="h-9"
              />
              {fieldErrors.min_select?.[0] && (
                <p className="text-[11px] text-destructive">{fieldErrors.min_select[0]}</p>
              )}
            </div>

            {/* max_select */}
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs font-medium text-muted-foreground">
                {t("hq.topping_groups.form.max_select")}
              </Label>
              <Input
                type="number"
                min={0}
                value={form.max_select}
                onChange={(e) => update("max_select", e.target.value)}
                placeholder="—"
                className="h-9"
              />
              {fieldErrors.max_select?.[0] && (
                <p className="text-[11px] text-destructive">{fieldErrors.max_select[0]}</p>
              )}
            </div>

            {/* allow_multiple_qty — Input number luôn render (disabled khi OFF)
                để layout cell ổn định 100%, không shift khi toggle. Switch nằm
                bên phải input. */}
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs font-medium text-muted-foreground">
                {t("hq.topping_groups.form.allow_multiple_qty")}
              </Label>
              <div className="flex h-9 items-center gap-2">
                <Input
                  type="number"
                  min={2}
                  value={form.max_qty_per_item}
                  onChange={(e) => update("max_qty_per_item", e.target.value)}
                  disabled={!form.allow_multiple_qty}
                  placeholder={t("hq.topping_groups.form.max_qty_per_item")}
                  className="h-9 flex-1"
                />
                <Switch
                  checked={form.allow_multiple_qty}
                  onCheckedChange={(v) => update("allow_multiple_qty", v)}
                />
              </div>
              {fieldErrors.max_qty_per_item?.[0] && (
                <p className="text-[11px] text-destructive">{fieldErrors.max_qty_per_item[0]}</p>
              )}
            </div>

            {/* price_strategy */}
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs font-medium text-muted-foreground">
                {t("hq.topping_groups.form.price_strategy")}
              </Label>
              <Select
                value={form.price_strategy}
                onValueChange={(v) => update("price_strategy", v as "flat" | "free_up_to_n")}
              >
                <SelectTrigger className="h-9">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="flat">{t("hq.topping_groups.price_strategy.flat")}</SelectItem>
                  <SelectItem value="free_up_to_n">
                    {t("hq.topping_groups.price_strategy.free_up_to_n")}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* free_quantity — only when free_up_to_n */}
            {form.price_strategy === "free_up_to_n" && (
              <div className="flex flex-col gap-1.5">
                <Label className="text-xs font-medium text-muted-foreground">
                  {t("hq.topping_groups.form.free_quantity")}
                  <span className="ml-0.5 text-destructive">*</span>
                </Label>
                <Input
                  type="number"
                  min={1}
                  value={form.free_quantity}
                  onChange={(e) => update("free_quantity", e.target.value)}
                  className="h-9"
                />
                {fieldErrors.free_quantity?.[0] && (
                  <p className="text-[11px] text-destructive">{fieldErrors.free_quantity[0]}</p>
                )}
              </div>
            )}

            {/* is_active */}
            <div className="flex flex-col gap-1.5">
              <Label className="text-xs font-medium text-muted-foreground">
                {t("hq.topping_groups.form.is_active")}
              </Label>
              <Select
                value={form.is_active ? "active" : "inactive"}
                onValueChange={(v) => update("is_active", v === "active")}
              >
                <SelectTrigger className="h-9">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="active">{t("status.active")}</SelectItem>
                  <SelectItem value="inactive">{t("status.inactive")}</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
        </div>

        <DialogFooter className="border-t pt-3">
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => onOpenChange(false)}
            disabled={isPending}
          >
            {t("common.cancel")}
          </Button>
          <Button
            type="button"
            size="sm"
            className="gap-1.5"
            onClick={handleSubmit}
            disabled={!canSubmit}
          >
            {isPending ? <Spinner className="size-3.5" /> : <Save className="size-3.5" />}
            {isEdit ? t("common.save") : t("common.create")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
