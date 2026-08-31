import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router";
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
import { Field, NETWORK_TYPES } from "./form-parts";

export function PeripheralEdit() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();

  const [device, setDevice] = useState<PeripheralDevice | null>(null);
  const [name, setName] = useState("");
  const [host, setHost] = useState("");
  const [port, setPort] = useState("");
  const [model, setModel] = useState("RT-R08");
  const [active, setActive] = useState(true);
  const [saving, setSaving] = useState(false);
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
              },
            }
          : {}),
      });
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

  async function handleDelete() {
    if (!device) return;
    if (!confirm(t("peripherals.delete_confirm", { name: device.name }))) return;
    await deletePeripheral(device.id).catch(() => undefined);
    navigate("/peripherals");
  }

  if (!device) {
    return (
      <>
        <PageHeader title={t("common.edit")} />
        <PageContent>
          <p className="text-sm text-muted-foreground">{t("common.loading")}</p>
        </PageContent>
      </>
    );
  }

  return (
    <>
      <PageHeader title={device.name} description={t(`peripherals.type.${device.type}`)}>
        <Button variant="ghost" size="sm" onClick={() => navigate("/peripherals")}>
          <ArrowLeft className="mr-1 size-4" />
          {t("common.cancel")}
        </Button>
      </PageHeader>

      <PageContent>
        <Card className="max-w-lg">
          <CardContent className="flex flex-col gap-3 py-4">
            <Field label={t("peripherals.field.name")} error={errors.name}>
              <Input value={name} onChange={(e) => setName(e.target.value)} />
            </Field>

            {isNetwork && (
              <>
                {device.type === "coin_changer" && (
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
                    placeholder={device.type === "coin_changer" ? "80" : "8888"}
                  />
                </Field>
              </>
            )}

            <div className="flex items-center justify-between rounded-md border border-border px-3 py-2">
              <span className="text-sm">{t("peripherals.field.active")}</span>
              <Switch checked={active} onCheckedChange={setActive} />
            </div>

            {errors._ && <p className="text-xs text-destructive">{errors._}</p>}

            <div className="flex items-center justify-between pt-1">
              <Button variant="ghost" size="sm" className="text-destructive" onClick={handleDelete}>
                <Trash2 className="mr-1 size-4" />
                {t("common.delete")}
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
