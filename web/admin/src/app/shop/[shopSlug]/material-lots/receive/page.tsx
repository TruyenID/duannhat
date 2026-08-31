"use client";

import { useParams, useRouter } from "next/navigation";
import { useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { useReceiveLot } from "@/hooks/api/use-material-lots";
import { useMaterialLookup } from "@/hooks/api/use-catalog-lookup";
import { useWarehouses } from "@/hooks/api/use-warehouses";
import { useTranslation } from "@/providers/app-provider";
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Combobox,
  type ComboboxOption,
  Input,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Textarea,
} from "@godxjp/ui";

interface FormState {
  material_id: string;
  warehouse_id: string;
  supplier_name: string;
  supplier_lot_code: string;
  expiry_date: string;
  received_qty: string;
  unit: string;
  cost_per_unit: string;
  currency: string;
  coa_url: string;
  notes: string;
  received_temperature: string;
  temperature_override_reason: string;
}

const EMPTY: FormState = {
  material_id: "",
  warehouse_id: "",
  supplier_name: "",
  supplier_lot_code: "",
  expiry_date: "",
  received_qty: "",
  unit: "kg",
  cost_per_unit: "",
  currency: "JPY",
  coa_url: "",
  notes: "",
  received_temperature: "",
  temperature_override_reason: "",
};

export default function ShopReceiveLotPage() {
  const { t } = useTranslation();
  const params = useParams<{ shopSlug: string }>();
  const router = useRouter();

  const [form, setForm] = useState<FormState>(EMPTY);
  const receive = useReceiveLot(params.shopSlug);
  const { data: materialLookup } = useMaterialLookup(params.shopSlug);
  const { data: warehouseList } = useWarehouses(params.shopSlug, {
    is_active: true,
    per_page: 100,
  });

  // cmdk filters by `value` using command-score fuzzy matching. Putting the
  // raw UUID in `value` makes every option look identical to the matcher
  // and pushes newly-created rows under the visibility cutoff for searches
  // like "E2E3" (the query lands mid-UUID instead of at the head of the
  // display name). Encode the human-readable label first and recover the
  // id by lookup. See plans/plan-024/NOTES.md BUG-UI-001.
  const VALUE_SEP = "·"; // U+00B7 MIDDLE DOT — won't appear in display labels
  const materialOptions: ComboboxOption[] = (materialLookup?.data ?? []).map((m) => {
    const display = m.sku ? `${m.name} (${m.sku})` : m.name;
    return { value: `${display}${VALUE_SEP}${m.id}`, label: display };
  });
  const warehouseOptions: ComboboxOption[] = (warehouseList?.data ?? []).map((w) => ({
    value: `${w.name}${VALUE_SEP}${w.id}`,
    label: w.name,
  }));

  function decodeComboValue(v: string): string {
    const idx = v.lastIndexOf(VALUE_SEP);
    return idx >= 0 ? v.slice(idx + 1) : v;
  }

  function encodeComboValue(id: string, options: ComboboxOption[]): string {
    return options.find((o) => o.value.endsWith(`${VALUE_SEP}${id}`))?.value ?? "";
  }

  const set =
    <K extends keyof FormState>(key: K) =>
    (value: string) =>
      setForm((prev) => ({ ...prev, [key]: value }));

  // Units come from the selected material's registered MaterialUnits. The
  // backend rejects a unit that isn't one of them, so we offer a picker (base
  // unit first) instead of free text. Materials with no registered units fall
  // back to a free-text input (the backend then accepts any value).
  const selectedMaterial = (materialLookup?.data ?? []).find((m) => m.id === form.material_id);
  const unitOptions = selectedMaterial?.units ?? [];

  // Picking a material resets the unit to that material's base unit so the
  // value can never be stale relative to the chosen material.
  const handleMaterialChange = (encoded: string) => {
    const id = decodeComboValue(encoded);
    const material = (materialLookup?.data ?? []).find((m) => m.id === id);
    const base = material?.units.find((u) => u.is_base) ?? material?.units[0];
    setForm((prev) => ({ ...prev, material_id: id, unit: base?.unit ?? prev.unit }));
  };

  const canSubmit =
    form.material_id.trim() !== "" &&
    form.warehouse_id.trim() !== "" &&
    Number(form.received_qty) > 0;

  const handleSubmit = () => {
    receive.mutate(
      {
        material_id: form.material_id,
        warehouse_id: form.warehouse_id,
        supplier_name: form.supplier_name || null,
        supplier_lot_code: form.supplier_lot_code || null,
        expiry_date: form.expiry_date || null,
        received_qty: Number(form.received_qty),
        unit: form.unit || null,
        cost_per_unit: form.cost_per_unit ? Number(form.cost_per_unit) : null,
        currency: form.currency || null,
        coa_url: form.coa_url || null,
        received_temperature: form.received_temperature ? Number(form.received_temperature) : null,
        temperature_override_reason: form.temperature_override_reason || null,
      },
      {
        onSuccess: (response) => {
          router.push(`/shop/${params.shopSlug}/material-lots/${response.data.id}`);
        },
      }
    );
  };

  return (
    <>
      <PageHeader
        title={t("material_lot.receive_title")}
        description={t("material_lot.receive_subtitle")}
      >
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => router.back()}>
            {t("common.cancel")}
          </Button>
          <Button onClick={handleSubmit} disabled={!canSubmit || receive.isPending}>
            {receive.isPending ? <Spinner className="mr-2" /> : null}
            {t("material_lot.receive_submit")}
          </Button>
        </div>
      </PageHeader>
      <PageContent>
        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>{t("material_lot.section_what")}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="space-y-1">
                <Label>{t("material_lot.field_material_id")}</Label>
                <Combobox
                  options={materialOptions}
                  value={encodeComboValue(form.material_id, materialOptions)}
                  onChange={handleMaterialChange}
                  placeholder={t("material_lot.field_material_placeholder")}
                />
              </div>
              <div className="space-y-1">
                <Label>{t("material_lot.field_warehouse_id")}</Label>
                <Combobox
                  options={warehouseOptions}
                  value={encodeComboValue(form.warehouse_id, warehouseOptions)}
                  onChange={(v) => set("warehouse_id")(decodeComboValue(v))}
                  placeholder={t("material_lot.field_warehouse_placeholder")}
                />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <Label htmlFor="received_qty">{t("material_lot.field_qty")}</Label>
                  <Input
                    id="received_qty"
                    type="number"
                    step="0.0001"
                    value={form.received_qty}
                    onChange={(e) => set("received_qty")(e.target.value)}
                  />
                </div>
                <div className="space-y-1">
                  <Label htmlFor="unit">{t("material_lot.field_unit")}</Label>
                  {unitOptions.length > 0 ? (
                    <Select value={form.unit || undefined} onValueChange={set("unit")}>
                      <SelectTrigger id="unit" className="w-full">
                        <SelectValue placeholder={t("material_lot.field_unit_placeholder")} />
                      </SelectTrigger>
                      <SelectContent>
                        {unitOptions.map((u) => (
                          <SelectItem key={u.unit} value={u.unit}>
                            {u.is_base ? u.unit : `${u.unit} (×${u.ratio})`}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  ) : (
                    <Input
                      id="unit"
                      value={form.unit}
                      onChange={(e) => set("unit")(e.target.value)}
                      disabled={!form.material_id}
                    />
                  )}
                </div>
              </div>
              <div className="space-y-1">
                <Label htmlFor="expiry_date">{t("material_lot.field_expiry")}</Label>
                <Input
                  id="expiry_date"
                  type="date"
                  value={form.expiry_date}
                  onChange={(e) => set("expiry_date")(e.target.value)}
                />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{t("material_lot.section_supplier")}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="space-y-1">
                <Label htmlFor="supplier_name">{t("material_lot.field_supplier_name")}</Label>
                <Input
                  id="supplier_name"
                  value={form.supplier_name}
                  onChange={(e) => set("supplier_name")(e.target.value)}
                />
              </div>
              <div className="space-y-1">
                <Label htmlFor="supplier_lot_code">
                  {t("material_lot.field_supplier_lot_code")}
                </Label>
                <Input
                  id="supplier_lot_code"
                  value={form.supplier_lot_code}
                  onChange={(e) => set("supplier_lot_code")(e.target.value)}
                />
              </div>
              <div className="grid grid-cols-3 gap-3">
                <div className="col-span-2 space-y-1">
                  <Label htmlFor="cost_per_unit">{t("material_lot.field_cost_per_unit")}</Label>
                  <Input
                    id="cost_per_unit"
                    type="number"
                    step="0.0001"
                    value={form.cost_per_unit}
                    onChange={(e) => set("cost_per_unit")(e.target.value)}
                  />
                </div>
                <div className="space-y-1">
                  <Label htmlFor="currency">{t("material_lot.field_currency")}</Label>
                  <Input
                    id="currency"
                    value={form.currency}
                    onChange={(e) => set("currency")(e.target.value.toUpperCase())}
                    maxLength={3}
                    placeholder="JPY"
                  />
                </div>
              </div>
              <div className="space-y-1">
                <Label htmlFor="coa_url">{t("material_lot.field_coa_url")}</Label>
                <Input
                  id="coa_url"
                  value={form.coa_url}
                  onChange={(e) => set("coa_url")(e.target.value)}
                  placeholder="https://"
                />
              </div>
              <div className="space-y-1">
                <Label htmlFor="notes">{t("material_lot.field_notes")}</Label>
                <Textarea
                  id="notes"
                  value={form.notes}
                  onChange={(e) => set("notes")(e.target.value)}
                  rows={2}
                  maxLength={1000}
                  className="field-sizing-fixed"
                />
              </div>
            </CardContent>
          </Card>

          <Card className="md:col-span-2">
            <CardHeader>
              <CardTitle>{t("material_lot.section_temperature")}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              <p className="text-xs text-muted-foreground">{t("material_lot.temperature_help")}</p>
              <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div className="space-y-1">
                  <Label htmlFor="received_temperature">
                    {t("material_lot.field_received_temperature")}
                  </Label>
                  <Input
                    id="received_temperature"
                    type="number"
                    step="0.1"
                    value={form.received_temperature}
                    onChange={(e) => set("received_temperature")(e.target.value)}
                    placeholder="2.0"
                  />
                </div>
                <div className="space-y-1">
                  <Label htmlFor="temperature_override_reason">
                    {t("material_lot.field_temperature_override_reason")}
                  </Label>
                  <Input
                    id="temperature_override_reason"
                    value={form.temperature_override_reason}
                    onChange={(e) => set("temperature_override_reason")(e.target.value)}
                    placeholder={t("material_lot.field_temperature_override_placeholder")}
                  />
                </div>
              </div>
            </CardContent>
          </Card>
        </div>
      </PageContent>
    </>
  );
}
