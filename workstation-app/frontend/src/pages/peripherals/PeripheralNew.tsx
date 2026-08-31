import { useState } from "react";
import { useNavigate } from "react-router";
import {
  Button,
  Card,
  CardContent,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Switch,
} from "@godxjp/ui";
import { ArrowLeft } from "lucide-react";
import {
  createPeripheral,
  addPrinter,
  ApiError,
  GLORY_MODELS,
  type PeripheralType,
  type PrinterRole,
} from "../../lib/api";
import { useTranslation } from "../../providers/app-provider";
import { PageHeader } from "../../components/layout/page-header";
import { PageContent } from "../../components/layout/page-content";
import { Field, PrinterFields, NETWORK_TYPES } from "./form-parts";

// Registry types + a "printer" pseudo-type that routes to the local manager.
const CREATE_TYPES = ["payment_terminal", "coin_changer", "local_printer"] as const;

export function PeripheralNew() {
  const { t } = useTranslation();
  const navigate = useNavigate();

  const [kind, setKind] = useState<(typeof CREATE_TYPES)[number]>("payment_terminal");
  const [name, setName] = useState("");
  const [host, setHost] = useState("");
  const [port, setPort] = useState("");
  const [model, setModel] = useState("RT-R08");
  const [active, setActive] = useState(true);
  // Printer-only fields.
  const [connType, setConnType] = useState("network");
  const [address, setAddress] = useState("");
  const [paperWidth, setPaperWidth] = useState(80);
  const [roles, setRoles] = useState<PrinterRole[]>(["kitchen_printer"]);

  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const isPrinter = kind === "local_printer";
  const isNetwork = (NETWORK_TYPES as string[]).includes(kind);

  async function handleSave() {
    setErrors({});
    setSaving(true);
    try {
      if (isPrinter) {
        if (!name || !address || roles.length === 0) {
          setErrors({ _: t("peripherals.printer_required") });
          setSaving(false);
          return;
        }
        await addPrinter({
          name,
          roles,
          connection_type: connType,
          address,
          paper_width: paperWidth,
        });
      } else {
        await createPeripheral({
          name,
          type: kind as PeripheralType,
          is_active: active,
          metadata: isNetwork
            ? {
                host,
                ...(port ? { port: Number(port) } : {}),
                ...(kind === "coin_changer" ? { model } : {}),
              }
            : undefined,
        });
      }
      navigate("/peripherals");
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) {
        const body = err.data as { errors?: Record<string, string[]> };
        const fe: Record<string, string> = {};
        for (const [f, m] of Object.entries(body.errors ?? {})) fe[f] = m[0];
        setErrors(fe);
      } else {
        setErrors({ _: (err as Error).message });
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <>
      <PageHeader title={t("peripherals.register")} description={t("peripherals.form_desc")}>
        <Button variant="ghost" size="sm" onClick={() => navigate("/peripherals")}>
          <ArrowLeft className="mr-1 size-4" />
          {t("common.cancel")}
        </Button>
      </PageHeader>

      <PageContent>
        <Card className="max-w-lg">
          <CardContent className="flex flex-col gap-3 py-4">
            <Field label={t("peripherals.field.type")}>
              <Select value={kind} onValueChange={(v) => setKind(v as typeof kind)}>
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {CREATE_TYPES.map((k) => (
                    <SelectItem key={k} value={k}>
                      {t(`peripherals.type.${k}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </Field>

            <Field label={t("peripherals.field.name")} error={errors.name}>
              <Input value={name} onChange={(e) => setName(e.target.value)} />
            </Field>

            {isNetwork && (
              <>
                {kind === "coin_changer" && (
                  <>
                    <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                      {t("peripherals.coin_changer_hint")}
                    </p>
                    <Field label={t("peripherals.field.model")}>
                      <Select value={model} onValueChange={setModel}>
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
                )}
                <Field label={t("peripherals.field.host")} error={errors["metadata.host"]}>
                  <Input value={host} onChange={(e) => setHost(e.target.value)} placeholder="192.168.0.77" />
                </Field>
                <Field label={t("peripherals.field.port")} error={errors["metadata.port"]}>
                  <Input
                    value={port}
                    onChange={(e) => setPort(e.target.value)}
                    placeholder={kind === "coin_changer" ? "80" : "8888"}
                  />
                </Field>
                <div className="flex items-center justify-between rounded-md border border-border px-3 py-2">
                  <span className="text-sm">{t("peripherals.field.active")}</span>
                  <Switch checked={active} onCheckedChange={setActive} />
                </div>
              </>
            )}

            {isPrinter && (
              <PrinterFields
                connType={connType}
                setConnType={setConnType}
                address={address}
                setAddress={setAddress}
                paperWidth={paperWidth}
                setPaperWidth={setPaperWidth}
                roles={roles}
                setRoles={setRoles}
              />
            )}

            {errors._ && <p className="text-xs text-destructive">{errors._}</p>}

            <div className="flex justify-end gap-2 pt-1">
              <Button variant="outline" size="sm" onClick={() => navigate("/peripherals")} disabled={saving}>
                {t("common.cancel")}
              </Button>
              <Button size="sm" onClick={handleSave} disabled={saving}>
                {t("common.save")}
              </Button>
            </div>
          </CardContent>
        </Card>
      </PageContent>
    </>
  );
}
