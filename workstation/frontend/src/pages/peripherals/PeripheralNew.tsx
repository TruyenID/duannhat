import { Flex } from "@godxjp/ui";
import { toast, FormField, Alert } from "@godxjp/ui/admin";
import { Card, CardContent } from "@godxjp/ui/data-display";
import { Input, Select, SelectContent, SelectItem, SelectTrigger, SelectValue, Switch , Form} from "@godxjp/ui/data-entry";
import { Button } from "@godxjp/ui/general";
import { useState } from "react";
import { useNavigate } from "react-router";
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
import {
  PrinterFields,
  NETWORK_TYPES,
  joinAddress,
  DEFAULT_PRINTER_PORT,
} from "./form-parts";

// Registry types + a "printer" pseudo-type that routes to the local manager.
const CREATE_TYPES = ["payment_terminal", "coin_changer", "local_printer"] as const;

export function PeripheralNew() {
  const { t } = useTranslation();
  const navigate = useNavigate();

  const [kind, setKindState] = useState<(typeof CREATE_TYPES)[number]>("payment_terminal");

  // Each device family has its own conventional port (ESC/POS printers 9100,
  // P400 8888, Glory 80). Seed it on the switch instead of leaving a blank box
  // the operator has to know the answer for — they can still overwrite it.
  function setKind(next: (typeof CREATE_TYPES)[number]) {
    setKindState(next);
    if (next === "local_printer" && !port.trim()) {
      setPort(String(DEFAULT_PRINTER_PORT));
    }
  }
  const [name, setName] = useState("");
  const [host, setHost] = useState("");
  const [port, setPort] = useState("");
  const [model, setModel] = useState("RT-R08");
  const [depositTimeout, setDepositTimeout] = useState("");
  const [active, setActive] = useState(true);
  // Printer-only fields.
  const [connType, setConnType] = useState("network");
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
        const address = joinAddress(
          host,
          connType === "network" ? port || String(DEFAULT_PRINTER_PORT) : "",
        );
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
                // #2422 — blank = the workstation's 300s default. Never send 0:
                // the Glory API reads 0 as "wait forever" and the machine would
                // sit holding the customer's cash with no terminal state.
                ...(kind === "coin_changer" && depositTimeout.trim()
                  ? { deposit_timeout_seconds: Number(depositTimeout) }
                  : {}),
              }
            : undefined,
        });
      }
      toast.success(t("toast.registered"));
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
      toast.error(t("toast.register_failed"));
    } finally {
      setSaving(false);
    }
  }

  return (
    <>
      <PageHeader title={t("peripherals.register")} description={t("peripherals.form_desc")}>
        <Button variant="ghost" size="sm" onClick={() => navigate("/peripherals")}>
          <ArrowLeft />
          {t("common.cancel")}
        </Button>
      </PageHeader>

      <PageContent>
        <Card>
          <CardContent>
            <Form layout="horizontal" labelWidth={220}>
            <FormField label={t("peripherals.field.type")}>
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
            </FormField>

            <FormField label={t("peripherals.field.name")} error={errors.name}>
              <Input value={name} onChange={(e) => setName(e.target.value)} />
            </FormField>

            {isNetwork && (
              <>
                {kind === "coin_changer" && (
                  <>
                    <Alert tone="info">{t("peripherals.coin_changer_hint")}</Alert>
                    <FormField label={t("peripherals.field.model")}>
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
                    </FormField>
                  </>
                )}
                <FormField label={t("peripherals.field.host")} error={errors["metadata.host"]}>
                  <Input value={host} onChange={(e) => setHost(e.target.value)} placeholder="192.168.0.77" />
                </FormField>
                <FormField label={t("peripherals.field.port")} error={errors["metadata.port"]}>
                  <Input
                    value={port}
                    onChange={(e) => setPort(e.target.value)}
                    placeholder={kind === "coin_changer" ? "80" : "8888"}
                  />
                </FormField>
                {kind === "coin_changer" && (
                  <FormField
                    label={t("peripherals.field.deposit_timeout")}
                    error={errors["metadata.deposit_timeout_seconds"]}
                  >
                    <Input
                      value={depositTimeout}
                      onChange={(e) => setDepositTimeout(e.target.value)}
                      placeholder="300"
                      inputMode="numeric"
                    />
                  </FormField>
                )}
                <FormField label={t("peripherals.field.active")}>
                  <Switch checked={active} onCheckedChange={setActive} />
                </FormField>
              </>
            )}

            {isPrinter && (
              <PrinterFields
                connType={connType}
                setConnType={setConnType}
                host={host}
                setHost={setHost}
                port={port}
                setPort={setPort}
                paperWidth={paperWidth}
                setPaperWidth={setPaperWidth}
                roles={roles}
                setRoles={setRoles}
                disabled={saving}
              />
            )}

            {errors._ && <Alert tone="destructive">{errors._}</Alert>}

            <Flex justify="end" gap="sm">
              <Button variant="outline" size="sm" onClick={() => navigate("/peripherals")} disabled={saving}>
                {t("common.cancel")}
              </Button>
              <Button size="sm" onClick={handleSave} disabled={saving}>
                {t("common.save")}
              </Button>
            </Flex>
            </Form>
          </CardContent>
        </Card>
      </PageContent>
    </>
  );
}
