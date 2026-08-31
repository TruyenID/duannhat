import { Flex } from "@godxjp/ui";
import { toast, Badge, FormField } from "@godxjp/ui/admin";
import { Card, CardContent } from "@godxjp/ui/data-display";
import { Form, Input } from "@godxjp/ui/data-entry";
import { Button, Text } from "@godxjp/ui/general";
import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router";
import { ArrowLeft, TestTube, Trash2 } from "lucide-react";
import {
  listDevices,
  updatePrinter,
  updateDeviceRoles,
  testPrinter,
  removeDevice,
  type DeviceInfo,
  type PrinterRole,
} from "../../lib/api";
import { useTranslation } from "../../providers/app-provider";
import { PageHeader } from "../../components/layout/page-header";
import { PageContent } from "../../components/layout/page-content";
import {
  PrinterFields,
  splitAddress,
  joinAddress,
  DEFAULT_PRINTER_PORT,
} from "./form-parts";

export function PrinterEdit() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();

  const [printer, setPrinter] = useState<DeviceInfo | null>(null);
  const [name, setName] = useState("");
  const [connType, setConnType] = useState("network");
  const [host, setHost] = useState("");
  const [port, setPort] = useState(String(DEFAULT_PRINTER_PORT));
  const [paperWidth, setPaperWidth] = useState(80);
  const [roles, setRoles] = useState<PrinterRole[]>([]);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [confirmingDelete, setConfirmingDelete] = useState(false);

  useEffect(() => {
    listDevices().then(({ devices }) => {
      const p = devices.find((x) => x.id === id) ?? null;
      setPrinter(p);
      if (!p) return;
      setName(p.name);
      setConnType(p.connection_type);
      const parts = splitAddress(p.address);
      setHost(parts.host);
      setPort(parts.port || String(DEFAULT_PRINTER_PORT));
      setRoles(p.roles ?? []);
    });
  }, [id]);

  // Identity and roles live behind two different endpoints (PATCH /devices/{id}
  // and PATCH /devices/{id}/roles), but the operator edited one form — so save
  // both, and report the first failure rather than half-succeeding silently.
  async function handleSave() {
    if (!printer) return;
    if (!name.trim()) {
      setError(t("peripherals.name_required"));
      return;
    }
    if (roles.length === 0) {
      setError(t("printer.at_least_one_role"));
      return;
    }
    setError(null);
    setSaving(true);
    try {
      await updatePrinter(printer.id, {
        name: name.trim(),
        connection_type: connType,
        address: joinAddress(host, connType === "network" ? port : ""),
        paper_width: paperWidth,
      });
      await updateDeviceRoles(printer.id, roles);
      toast.success(t("toast.saved"));
      navigate("/peripherals");
    } catch (err) {
      setError((err as Error).message);
      toast.error(t("toast.save_failed"));
    } finally {
      setSaving(false);
    }
  }

  async function handleTest() {
    if (!printer) return;
    try {
      await testPrinter(printer.id);
      toast.success(t("printer.test_sent"));
    } catch (err) {
      toast.error(t("printer.test_failed") + ": " + (err as Error).message);
    }
  }

  async function handleRemove() {
    if (!printer) return;
    setConfirmingDelete(false);
    try {
      await removeDevice(printer.id);
      toast.success(t("toast.deleted"));
    } catch {
      toast.error(t("toast.delete_failed"));
    }
    navigate("/peripherals");
  }

  if (!printer) {
    return (
      <>
        <PageHeader title={t("common.edit")} />
        <PageContent>
          <Text size="sm" tone="muted">
            {t("common.loading")}
          </Text>
        </PageContent>
      </>
    );
  }

  return (
    <>
      <PageHeader title={printer.name} description={t("peripherals.local_printer")}>
        <Badge variant={printer.status === "online" ? "default" : "secondary"}>
          {printer.status}
        </Badge>
        <Button variant="ghost" size="sm" onClick={() => navigate("/peripherals")}>
          <ArrowLeft />
          {t("common.cancel")}
        </Button>
      </PageHeader>

      <PageContent>
        <Card>
          <CardContent>
            <Form layout="horizontal" labelWidth={220}>
              <FormField id="printer-name" label={t("common.name")}>
                <Input
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  disabled={saving}
                  placeholder={t("peripherals.name_placeholder")}
                />
              </FormField>

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

              {error && (
                <FormField label="" error={error}>
                  <span />
                </FormField>
              )}

              <Flex align="center" justify="between">
                <Flex gap="sm">
                  <Button variant="outline" size="sm" onClick={handleTest} disabled={saving}>
                    <TestTube />
                    {t("printer.test")}
                  </Button>
                  {confirmingDelete ? (
                    <Flex align="center" gap="sm">
                      <Text size="sm" tone="muted">
                        {t("peripherals.delete_confirm", { name: printer.name })}
                      </Text>
                      <Button variant="outline" size="sm" onClick={handleRemove}>
                        {t("common.delete")}
                      </Button>
                      <Button variant="ghost" size="sm" onClick={() => setConfirmingDelete(false)}>
                        {t("common.cancel")}
                      </Button>
                    </Flex>
                  ) : (
                    <Button variant="ghost" size="sm" onClick={() => setConfirmingDelete(true)}>
                      <Trash2 />
                      {t("common.delete")}
                    </Button>
                  )}
                </Flex>
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
