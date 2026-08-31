"use client";

import { useState } from "react";
import { HelpCircle } from "lucide-react";
import {
  Badge,
  Button,
  Card,
  Input,
  Popover,
  PopoverContent,
  PopoverTrigger,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Switch,
  Textarea,
} from "@godxjp/ui";
import {
  useCreateWarehouse,
  useUpdateWarehouse,
  useUpdateWarehouseSettings,
} from "@/hooks/api/use-warehouses";
import { WarehouseType, type Warehouse } from "@/services/warehouse-service";
import type { AllergenPolicy } from "@/services/warehouse-service";
import { useTranslation } from "@/providers/app-provider";

// ---------------------------------------------------------------------------
// AllergenTagInput — inline tag input for allergen names
// ---------------------------------------------------------------------------

interface AllergenTagInputProps {
  values: string[];
  onChange: (v: string[]) => void;
  placeholder: string;
}

function AllergenTagInput({ values, onChange, placeholder }: AllergenTagInputProps) {
  const [input, setInput] = useState("");

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Enter" && !e.nativeEvent.isComposing && input.trim()) {
      e.preventDefault();
      const val = input.trim();
      if (!values.includes(val)) onChange([...values, val]);
      setInput("");
    }
    if (e.key === "Backspace" && !input && values.length > 0) {
      onChange(values.slice(0, -1));
    }
  }

  return (
    <div data-slot="allergen-tag-input">
      <div className="mb-1 flex flex-wrap gap-1">
        {values.map((v) => (
          <Badge key={v} variant="secondary" className="text-xs">
            {v}
            <button
              type="button"
              onClick={() => onChange(values.filter((x) => x !== v))}
              className="ml-1 hover:text-destructive"
            >
              ×
            </button>
          </Badge>
        ))}
      </div>
      <Input
        value={input}
        onChange={(e) => setInput(e.target.value)}
        onKeyDown={handleKeyDown}
        placeholder={placeholder}
        className="h-8 text-xs"
      />
    </div>
  );
}

// ---------------------------------------------------------------------------
// PolicyToggle — labelled switch row with help popover, used by both the
// inventory-policy and auto-approve groups.
// ---------------------------------------------------------------------------

interface PolicyToggleProps {
  label: string;
  hint: string;
  helpAria: string;
  checked: boolean;
  onChange: (v: boolean) => void;
}

function PolicyToggle({ label, hint, helpAria, checked, onChange }: PolicyToggleProps) {
  return (
    <div
      data-slot="policy-toggle"
      className="flex items-center justify-between gap-3 rounded-md border p-3"
    >
      <div className="flex items-center gap-1">
        <label className="text-sm font-medium">{label}</label>
        <Popover>
          <PopoverTrigger asChild>
            <button
              type="button"
              className="inline-flex size-5 cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
              aria-label={helpAria}
            >
              <HelpCircle className="size-3.5" />
            </button>
          </PopoverTrigger>
          <PopoverContent
            align="start"
            className="max-w-sm text-xs leading-relaxed text-muted-foreground"
          >
            {hint}
          </PopoverContent>
        </Popover>
      </div>
      <Switch checked={checked} onCheckedChange={onChange} />
    </div>
  );
}

// ---------------------------------------------------------------------------
// WarehouseForm
// ---------------------------------------------------------------------------

export interface WarehouseFormProps {
  shopSlug: string;
  /** Pass a warehouse for edit mode; omit for create. */
  warehouse?: Warehouse;
  /** Fired after a successful create/update — parent navigates away. */
  onSuccess: () => void;
  /** Fired when the user cancels — parent navigates back. */
  onCancel: () => void;
}

export function WarehouseForm({ shopSlug, warehouse, onSuccess, onCancel }: WarehouseFormProps) {
  const { t } = useTranslation();
  const isEdit = !!warehouse;
  const createMutation = useCreateWarehouse(shopSlug);
  const updateMutation = useUpdateWarehouse(shopSlug);
  const settingsMutation = useUpdateWarehouseSettings(shopSlug);
  const pending =
    createMutation.isPending || updateMutation.isPending || settingsMutation.isPending;

  // code is never edited from the UI: blank on create (backend auto-generates)
  // and read-only on edit (backend treats it as immutable). Kept as state only
  // to mirror the existing warehouse's code for display + payload.
  const [code] = useState(warehouse?.code ?? "");
  const [name, setName] = useState(warehouse?.name ?? "");
  const [type, setType] = useState<WarehouseType>(warehouse?.type ?? WarehouseType.Main);
  const [address, setAddress] = useState(warehouse?.address ?? "");

  const initialPolicy = warehouse?.allergen_policy as AllergenPolicy | null | undefined;
  const [forbidden, setForbidden] = useState<string[]>(initialPolicy?.forbidden ?? []);
  const [requiredSeparate, setRequiredSeparate] = useState<string[]>(
    initialPolicy?.required_separate ?? []
  );

  // Plan-024 — sales-flow allow-negative policy. Stored separately from the
  // main update payload because the backend exposes it on the settings
  // sub-endpoint (PATCH /warehouses/{id}/settings). On edit the form fires
  // both calls in sequence on submit.
  const [allowNegativeSales, setAllowNegativeSales] = useState(
    warehouse?.allow_negative_sales ?? false
  );
  const [autoApproveStockIn, setAutoApproveStockIn] = useState(
    warehouse?.auto_approve_stock_in ?? true
  );
  const [autoApproveStockOut, setAutoApproveStockOut] = useState(
    warehouse?.auto_approve_stock_out ?? true
  );
  const [autoApproveBatch, setAutoApproveBatch] = useState(warehouse?.auto_approve_batch ?? false);
  const [autoApproveDisposal, setAutoApproveDisposal] = useState(
    warehouse?.auto_approve_disposal ?? false
  );
  const [disposalApprovalThreshold, setDisposalApprovalThreshold] = useState<string>(
    warehouse?.disposal_approval_threshold != null
      ? String(warehouse.disposal_approval_threshold)
      : ""
  );

  // code is optional — the backend auto-generates a unique code when blank,
  // and ignores it entirely on update (immutable after creation). So only
  // name gates submission.
  const canSubmit = name.trim().length > 0 && !pending;

  async function handleSubmit() {
    if (!canSubmit) return;

    const hasAllergenPolicy = forbidden.length > 0 || requiredSeparate.length > 0;
    const allergenPolicy: AllergenPolicy | null = hasAllergenPolicy
      ? { forbidden, required_separate: requiredSeparate }
      : null;

    const payload = {
      code: code.trim(),
      name: name.trim(),
      type,
      address: address.trim() || null,
      allergen_policy: allergenPolicy,
    };
    const trimmedThreshold = disposalApprovalThreshold.trim();
    const parsedThreshold = trimmedThreshold === "" ? null : Number(trimmedThreshold);
    const thresholdValid =
      trimmedThreshold === "" || (Number.isFinite(parsedThreshold) && (parsedThreshold ?? 0) >= 0);

    if (!thresholdValid) return;

    const settingsPayload = {
      allow_negative_sales: allowNegativeSales,
      auto_approve_stock_in: autoApproveStockIn,
      auto_approve_stock_out: autoApproveStockOut,
      auto_approve_batch: autoApproveBatch,
      auto_approve_disposal: autoApproveDisposal,
      disposal_approval_threshold: parsedThreshold,
    };

    try {
      if (isEdit && warehouse) {
        await updateMutation.mutateAsync({ id: warehouse.id, data: payload });
        const changed =
          allowNegativeSales !== (warehouse.allow_negative_sales ?? false) ||
          autoApproveStockIn !== (warehouse.auto_approve_stock_in ?? true) ||
          autoApproveStockOut !== (warehouse.auto_approve_stock_out ?? true) ||
          autoApproveBatch !== (warehouse.auto_approve_batch ?? false) ||
          autoApproveDisposal !== (warehouse.auto_approve_disposal ?? false) ||
          parsedThreshold !== (warehouse.disposal_approval_threshold ?? null);
        if (changed) {
          await settingsMutation.mutateAsync({ id: warehouse.id, data: settingsPayload });
        }
      } else {
        await createMutation.mutateAsync({
          ...payload,
          ...settingsPayload,
        });
      }
      onSuccess();
    } catch {
      // toast already shown by the mutation hook
    }
  }

  return (
    <div className="space-y-4" data-slot="warehouse-form">
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
        {/* LEFT — identity + allergen policy */}
        <div className="flex flex-col gap-4 lg:col-span-7">
          <Card className="space-y-4 p-5">
            <div className="text-sm font-semibold">{t("shop.warehouses.form.basic_info")}</div>
            <div className={isEdit ? "grid grid-cols-1 gap-3 sm:grid-cols-2" : undefined}>
              {/* code is hidden on create (auto-generated); shown read-only on
                  edit since the backend treats it as immutable. */}
              {isEdit && (
                <div className="space-y-1.5">
                  <label className="text-xs font-medium text-muted-foreground">
                    {t("shop.warehouses.form.code")}
                  </label>
                  <Input
                    value={code}
                    readOnly
                    tabIndex={-1}
                    className="h-9 cursor-not-allowed bg-muted/40 text-sm text-muted-foreground"
                  />
                </div>
              )}
              <div className="space-y-1.5">
                <label className="text-xs font-medium text-muted-foreground">
                  {t("shop.warehouses.form.type")}
                </label>
                <Select value={type} onValueChange={(v) => setType(v as WarehouseType)}>
                  <SelectTrigger className="h-9 w-full text-sm">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={WarehouseType.Main}>
                      {t("shop.warehouses.form.type.main")}
                    </SelectItem>
                    <SelectItem value={WarehouseType.Branch}>
                      {t("shop.warehouses.form.type.branch")}
                    </SelectItem>
                    <SelectItem value={WarehouseType.Production}>
                      {t("shop.warehouses.form.type.production")}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="space-y-1.5">
              <label className="text-xs font-medium text-muted-foreground">
                {t("shop.warehouses.form.name")}
              </label>
              <Input
                value={name}
                onChange={(e) => setName(e.target.value)}
                className="h-9 text-sm"
              />
            </div>

            <div className="space-y-1.5">
              <label className="text-xs font-medium text-muted-foreground">
                {t("shop.warehouses.form.address")}
              </label>
              <Textarea
                value={address}
                onChange={(e) => setAddress(e.target.value)}
                rows={3}
                maxLength={1000}
                className="field-sizing-fixed"
              />
            </div>
          </Card>

          <Card className="space-y-3 p-5">
            <div className="text-sm font-semibold">{t("shop.warehouses.form.allergen_policy")}</div>
            <div className="space-y-1.5">
              <label className="text-xs font-medium text-muted-foreground">
                {t("shop.warehouses.form.allergen_forbidden")}
              </label>
              <p className="text-[11px] text-muted-foreground/70">
                {t("shop.warehouses.form.allergen_forbidden_help")}
              </p>
              <AllergenTagInput
                values={forbidden}
                onChange={setForbidden}
                placeholder={t("shop.warehouses.form.allergen_add_placeholder")}
              />
            </div>
            <div className="space-y-1.5">
              <label className="text-xs font-medium text-muted-foreground">
                {t("shop.warehouses.form.allergen_required_separate")}
              </label>
              <AllergenTagInput
                values={requiredSeparate}
                onChange={setRequiredSeparate}
                placeholder={t("shop.warehouses.form.allergen_add_placeholder")}
              />
            </div>
          </Card>
        </div>

        {/* RIGHT — operational policies */}
        <div className="flex flex-col gap-4 lg:col-span-5">
          <Card className="space-y-3 p-5">
            <div className="text-sm font-semibold">
              {t("shop.warehouses.form.inventory_policy")}
            </div>
            <PolicyToggle
              label={t("shop.warehouses.form.allow_negative_sales")}
              hint={t("shop.warehouses.form.allow_negative_sales_hint")}
              helpAria={t("common.help")}
              checked={allowNegativeSales}
              onChange={setAllowNegativeSales}
            />
          </Card>

          <Card className="space-y-3 p-5">
            <div className="text-sm font-semibold">{t("shop.warehouses.form.auto_approve")}</div>
            <PolicyToggle
              label={t("shop.warehouses.form.auto_approve_stock_in")}
              hint={t("shop.warehouses.form.auto_approve_stock_in_hint")}
              helpAria={t("common.help")}
              checked={autoApproveStockIn}
              onChange={setAutoApproveStockIn}
            />
            <PolicyToggle
              label={t("shop.warehouses.form.auto_approve_stock_out")}
              hint={t("shop.warehouses.form.auto_approve_stock_out_hint")}
              helpAria={t("common.help")}
              checked={autoApproveStockOut}
              onChange={setAutoApproveStockOut}
            />
            <PolicyToggle
              label={t("shop.warehouses.form.auto_approve_batch")}
              hint={t("shop.warehouses.form.auto_approve_batch_hint")}
              helpAria={t("common.help")}
              checked={autoApproveBatch}
              onChange={setAutoApproveBatch}
            />
            <PolicyToggle
              label={t("shop.warehouses.form.auto_approve_disposal")}
              hint={t("shop.warehouses.form.auto_approve_disposal_hint")}
              helpAria={t("common.help")}
              checked={autoApproveDisposal}
              onChange={setAutoApproveDisposal}
            />
            <div className="space-y-1.5 rounded-md border p-3">
              <div className="flex items-center gap-1">
                <label className="text-sm font-medium">
                  {t("shop.warehouses.form.disposal_approval_threshold")}
                </label>
                <Popover>
                  <PopoverTrigger asChild>
                    <button
                      type="button"
                      className="inline-flex size-5 cursor-pointer items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                      aria-label={t("common.help")}
                    >
                      <HelpCircle className="size-3.5" />
                    </button>
                  </PopoverTrigger>
                  <PopoverContent
                    align="start"
                    className="max-w-sm text-xs leading-relaxed text-muted-foreground"
                  >
                    {t("shop.warehouses.form.disposal_approval_threshold_hint")}
                  </PopoverContent>
                </Popover>
              </div>
              <Input
                type="number"
                inputMode="decimal"
                min={0}
                value={disposalApprovalThreshold}
                onChange={(e) => setDisposalApprovalThreshold(e.target.value)}
                className="h-9 text-sm"
              />
            </div>
          </Card>
        </div>
      </div>

      {/* Action bar — spans full width below the grid */}
      <div className="flex items-center justify-end gap-2 border-t pt-4">
        <Button variant="outline" onClick={onCancel} disabled={pending}>
          {t("common.cancel")}
        </Button>
        <Button onClick={handleSubmit} disabled={!canSubmit}>
          {pending && <Spinner className="mr-1.5 size-3.5" />}
          {isEdit ? t("common.save") : t("common.create")}
        </Button>
      </div>
    </div>
  );
}
