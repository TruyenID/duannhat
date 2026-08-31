import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router";
import { Badge, Button, Card, CardContent } from "@godxjp/ui";
import { CreditCard, Plus, Printer, Wifi, WifiOff, RefreshCw } from "lucide-react";
import {
  listPeripherals,
  listDevices,
  type PeripheralDevice,
  type DeviceInfo,
} from "../../lib/api";
import { useTranslation } from "../../providers/app-provider";
import { PageHeader } from "../../components/layout/page-header";
import { PageContent } from "../../components/layout/page-content";

function isNetwork(type: string): boolean {
  return type === "payment_terminal" || type === "coin_changer";
}

export function PeripheralsList() {
  const { t } = useTranslation();
  const [registry, setRegistry] = useState<PeripheralDevice[]>([]);
  const [printers, setPrinters] = useState<DeviceInfo[]>([]);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    const [reg, dev] = await Promise.allSettled([listPeripherals(), listDevices()]);
    if (reg.status === "fulfilled") setRegistry(reg.value);
    if (dev.status === "fulfilled") setPrinters(dev.value.devices);
    setLoading(false);
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const empty = !loading && registry.length === 0 && printers.length === 0;

  return (
    <>
      <PageHeader title={t("peripherals.title")} description={t("peripherals.subtitle")}>
        <Button variant="ghost" size="icon" onClick={load} title={t("common.refresh")}>
          <RefreshCw className="size-4" />
        </Button>
        <Button asChild size="sm">
          <Link to="/peripherals/new">
            <Plus className="mr-1 size-4" />
            {t("peripherals.register")}
          </Link>
        </Button>
      </PageHeader>

      <PageContent>
        {loading ? (
          <p className="text-sm text-muted-foreground">{t("common.loading")}</p>
        ) : empty ? (
          <Card>
            <CardContent className="py-10 text-center text-sm text-muted-foreground">
              {t("peripherals.empty")}
            </CardContent>
          </Card>
        ) : (
          <div className="flex flex-col gap-2">
            {/* Cloud-registry peripherals (P400 / 釣銭機 / registry printers) */}
            {registry.map((d) => (
              <Link key={d.id} to={`/peripherals/${d.id}`} className="block">
                <Card className="transition-colors hover:border-primary/50">
                  <CardContent className="flex items-center gap-3 py-3">
                    {isNetwork(d.type) ? (
                      <CreditCard className="size-5 text-muted-foreground" />
                    ) : (
                      <Printer className="size-5 text-muted-foreground" />
                    )}
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <span className="font-medium">{d.name}</span>
                        <Badge variant={d.is_active ? "default" : "secondary"}>
                          {d.is_active ? t("peripherals.active") : t("peripherals.inactive")}
                        </Badge>
                        {d.pending_sync && (
                          <Badge variant="outline">{t("peripherals.pending_sync")}</Badge>
                        )}
                      </div>
                      <div className="text-xs text-muted-foreground">
                        {t(`peripherals.type.${d.type}`)}
                        {d.type === "coin_changer" && d.metadata?.model ? (
                          <span className="ml-1 font-medium text-foreground">· {d.metadata.model}</span>
                        ) : null}
                        {isNetwork(d.type) && d.metadata?.host ? (
                          <span className="ml-2 font-mono">
                            {d.metadata.host}
                            {d.metadata.port ? `:${d.metadata.port}` : ""}
                          </span>
                        ) : null}
                      </div>
                    </div>
                  </CardContent>
                </Card>
              </Link>
            ))}

            {/* Local physical printers (workstation printer manager) */}
            {printers.map((p) => (
              <Link key={p.id} to={`/peripherals/printer/${p.id}`} className="block">
                <Card className="transition-colors hover:border-primary/50">
                  <CardContent className="flex items-center gap-3 py-3">
                    {p.status === "online" ? (
                      <Wifi className="size-5 text-success" />
                    ) : (
                      <WifiOff className="size-5 text-muted-foreground" />
                    )}
                    <div className="min-w-0 flex-1">
                      <div className="flex items-center gap-2">
                        <span className="font-medium">{p.name}</span>
                        <Badge variant="secondary">{t("peripherals.local_printer")}</Badge>
                      </div>
                      <div className="text-xs text-muted-foreground">
                        {p.connection_type} · <span className="font-mono">{p.address}</span>
                        {(p.roles ?? []).length > 0 && (
                          <span className="ml-2">
                            {(p.roles ?? []).map((r) => t(`printer.role.${r}`)).join(", ")}
                          </span>
                        )}
                      </div>
                    </div>
                  </CardContent>
                </Card>
              </Link>
            ))}
          </div>
        )}
      </PageContent>
    </>
  );
}
