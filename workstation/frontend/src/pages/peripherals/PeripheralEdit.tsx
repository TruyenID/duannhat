import { Flex } from "@godxjp/ui";
import { toast, FormField, Alert, AlertDialog } from "@godxjp/ui/admin";
import { Card, CardContent } from "@godxjp/ui/data-display";
import { Input, Select, SelectContent, SelectItem, SelectTrigger, SelectValue, Switch , Form} from "@godxjp/ui/data-entry";
import { Button, Text } from "@godxjp/ui/general";
import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router";
import { ArrowLeft, Trash2 } from "lucide-react";
import {
  listPeripherals,
  updatePeripheral,
  deletePeripheral,
  ApiError,
  GLORY_MODELS,
  type PeripheralDevice,
} from "../../lib/api";
import { useTranslation } from "../../providers/app-provider";
import { PageHeader } from "../../components/layout/page-header";
import { PageContent } from "../../components/layout/page-content";
import { NETWORK_TYPES } from "./form-parts";

export function PeripheralEdit() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();

  const [device, setDevice] = useState<PeripheralDevice | null>(null);
  const [name, setName] = useState("");
  const [host, setHost] = useState("");
  const [port, setPort] = useState("");
  const [model, setModel] = useState("RT-R08");
  const [depositTimeout, setDepositTimeout] = useState("");
  const [active, setActive] = useState(true);
  const [saving, setSaving] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    listPeripherals().then((all) => {
      const d = all.find((x) => x.id === id) ?? null;
      setDevice(d);
      if (d) {
        setName(d.name);
        setHost(d.metadata?.host ?? "");
        setPort(d.metadata?.port != null ? String(d.metadata.port) : "");
        setModel(d.metadata?.model ?? "RT-R08");
        setDepositTimeout(
          d.metadata?.deposit_timeout_seconds != null
            ? String(d.metadata.deposit_timeout_seconds)
            : "",
        );
        setActive(d.is_active);
      }
    });
  }, [id]);

  const isNetwork = device ? (NETWORK_TYPES as string[]).includes(device.type) : false;

  async function handleSave() {
    if (!device) return;
    setErrors({});
    setSaving(true);
    try {
      await updatePeripheral(device.id, {
        name,
        is_active: active,
        ...(isNetwork
          ? {
              metadata: {
                host,
                ...(port ? { port: Number(port) } : {}),
                ...(device.type === "coin_changer" ? { model } : {}),
                // #2422 — blank = the workstation's 300s default. Never 0: the
                // Glory API reads 0 as "wait forever", leaving the machine
                // holding the customer's cash with no terminal state.
                ...(device.type === "coin_changer" && depositTimeout.trim()
                  ? { deposit_timeout_seconds: Number(depositTimeout) }
                  : {}),
              },
            }
          : {}),
      });
      toast.success(t("toast.updated"));
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
      toast.error(t("toast.update_failed"));
    } finally {
      setSaving(false);
    }
  }

  // window.confirm blocks the entire webview in the Wails shell — the delete is
  // gated by an in-app AlertDialog instead.
  async function handleDelete() {
    if (!device) return;
    setConfirmOpen(false);
    try {
      await deletePeripheral(device.id);
      toast.success(t("toast.deleted"));
    } catch {
      toast.error(t("toast.delete_failed"));
    }
    navigate("/peripherals");
  }

  if (!device) {
    return (
      <>
        <PageHeader title={t("common.edit")} />
        <PageContent>
          <Text size="sm" tone="muted">{t("common.loading")}</Text>
        </PageContent>
      </>
    );
  }

  return (
    <>
      <PageHeader title={device.name} description={t(`peripherals.type.${device.type}`)}>
        <Button variant="ghost" size="sm" onClick={() => navigate("/peripherals")}>
          <ArrowLeft />
          {t("common.cancel")}
        </Button>
      </PageHeader>

      <PageContent>
        <Card>
          <CardContent>
            <Form layout="horizontal" labelWidth={220}>
            <FormField label={t("peripherals.field.name")} error={errors.name}>
              <Input value={name} onChange={(e) => setName(e.target.value)} />
            </FormField>

            {isNetwork && (
              <>
                {device.type === "coin_changer" && (
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
                    placeholder={device.type === "coin_changer" ? "80" : "8888"}
                  />
                </FormField>
              </>
            )}

            {device.type === "coin_changer" && (
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

            {errors._ && <Alert tone="destructive">{errors._}</Alert>}

            <Flex align="center" justify="between">
              <Button variant="ghost" size="sm" onClick={() => setConfirmOpen(true)}>
                <Trash2 />
                {t("common.delete")}
              </Button>
              <Button size="sm" onClick={handleSave} disabled={saving}>
                {t("common.save")}
              </Button>
            </Flex>
            </Form>

            <AlertDialog
              open={confirmOpen}
              onOpenChange={setConfirmOpen}
              title={t("common.delete")}
              description={t("peripherals.delete_confirm", { name: device.name })}
              confirmLabel={t("common.delete")}
              cancelLabel={t("common.cancel")}
              variant="destructive"
              onConfirm={handleDelete}
            />

          </CardContent>
        </Card>
      </PageContent>
    </>
  );
}
