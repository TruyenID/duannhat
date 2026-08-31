import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router";
import { Badge, Button, Card, CardContent } from "@godxjp/ui";
import { ArrowLeft, TestTube, Trash2 } from "lucide-react";
import {
  listDevices,
  updateDeviceRoles,
  testPrinter,
  removeDevice,
  type DeviceInfo,
  type PrinterRole,
} from "../../lib/api";
import { useTranslation } from "../../providers/app-provider";
import { PageHeader } from "../../components/layout/page-header";
import { PageContent } from "../../components/layout/page-content";
import { Field, RolePicker } from "./form-parts";

export function PrinterEdit() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();

  const [printer, setPrinter] = useState<DeviceInfo | null>(null);
  const [roles, setRoles] = useState<PrinterRole[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    listDevices().then(({ devices }) => {
      const p = devices.find((x) => x.id === id) ?? null;
      setPrinter(p);
      setRoles(p?.roles ?? []);
    });
  }, [id]);

  async function handleSaveRoles() {
    if (!printer) return;
    if (roles.length === 0) {
      setError(t("printer.at_least_one_role"));
      return;
    }
    setError(null);
    setSaving(true);
    try {
      await updateDeviceRoles(printer.id, roles);
      navigate("/peripherals");
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setSaving(false);
    }
  }

  async function handleTest() {
    if (!printer) return;
    try {
      await testPrinter(printer.id);
      alert(t("printer.test_sent"));
    } catch (err) {
      alert(t("printer.test_failed") + ": " + (err as Error).message);
    }
  }

  async function handleRemove() {
    if (!printer) return;
    if (!confirm(t("peripherals.delete_confirm", { name: printer.name }))) return;
    await removeDevice(printer.id).catch(() => undefined);
    navigate("/peripherals");
  }

  if (!printer) {
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
      <PageHeader title={printer.name} description={t("peripherals.local_printer")}>
        <Button variant="ghost" size="sm" onClick={() => navigate("/peripherals")}>
          <ArrowLeft className="mr-1 size-4" />
          {t("common.cancel")}
        </Button>
      </PageHeader>

      <PageContent>
        <Card className="max-w-lg">
          <CardContent className="flex flex-col gap-3 py-4">
            <div className="flex items-center gap-2 text-sm">
              <span className="text-muted-foreground">{printer.connection_type}</span>
              <span className="font-mono">{printer.address}</span>
              <Badge variant={printer.status === "online" ? "default" : "secondary"}>
                {printer.status}
              </Badge>
            </div>

            <Field label={t("printer.roles")}>
              <RolePicker
                roles={roles}
                disabled={saving}
                onToggle={(r) =>
                  setRoles((prev) => (prev.includes(r) ? prev.filter((x) => x !== r) : [...prev, r]))
                }
              />
            </Field>

            {error && <p className="text-xs text-destructive">{error}</p>}

            <div className="flex items-center justify-between pt-1">
              <div className="flex gap-2">
                <Button variant="outline" size="sm" onClick={handleTest}>
                  <TestTube className="mr-1 size-4" />
                  {t("printer.test")}
                </Button>
                <Button variant="ghost" size="sm" className="text-destructive" onClick={handleRemove}>
                  <Trash2 className="mr-1 size-4" />
                  {t("common.delete")}
                </Button>
              </div>
              <Button size="sm" onClick={handleSaveRoles} disabled={saving}>
                {t("common.save")}
              </Button>
            </div>
          </CardContent>
        </Card>
      </PageContent>
    </>
  );
}
