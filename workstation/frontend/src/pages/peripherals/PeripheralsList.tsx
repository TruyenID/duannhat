import { Badge, EmptyState } from "@godxjp/ui/admin";
import { Card, CardContent, ListRow } from "@godxjp/ui/data-display";
import { Button, Text } from "@godxjp/ui/general";
import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router";
import { CreditCard, Plus, Printer, RefreshCw, Wifi, WifiOff } from "lucide-react";
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
    // Printer reachability is probed server-side in the background (see
    // handleListDevices / deviceStatusMaxAge) — polling is what actually
    // surfaces a status flip, a one-shot fetch would show a stale badge
    // until the user manually refreshes twice.
    const id = setInterval(load, 5000);
    return () => clearInterval(id);
  }, [load]);

  const empty = !loading && registry.length === 0 && printers.length === 0;

  return (
    <>
      <PageHeader title={t("peripherals.title")} description={t("peripherals.subtitle")}>
        <Button variant="ghost" size="icon" onClick={load} aria-label={t("common.refresh")}>
          <RefreshCw />
        </Button>
        <Button asChild size="sm">
          <Link to="/peripherals/new">
            <Plus />
            {t("peripherals.register")}
          </Link>
        </Button>
      </PageHeader>

      <PageContent>
        {loading ? (
          <Text size="sm" tone="muted">
            {t("common.loading")}
          </Text>
        ) : empty ? (
          <EmptyState
            icon={CreditCard}
            title={t("peripherals.empty")}
            description={t("peripherals.subtitle")}
          />
        ) : (
          <Card>
            <CardContent>
              {/* Cloud-registry peripherals (P400 / 釣銭機 / registry printers) */}
              {registry.map((d) => (
                <Link key={d.id} to={`/peripherals/${d.id}`}>
                  <ListRow
                    leading={isNetwork(d.type) ? <CreditCard aria-hidden /> : <Printer aria-hidden />}
                    title={d.name}
                    description={
                      <>
                        {t(`peripherals.type.${d.type}`)}
                        {d.type === "coin_changer" && d.metadata?.model ? (
                          <Text as="span" size="xs" weight="medium">
                            {" · "}
                            {d.metadata.model}
                          </Text>
                        ) : null}
                        {isNetwork(d.type) && d.metadata?.host ? (
                          <Text as="span" size="xs" mono>
                            {"  "}
                            {d.metadata.host}
                            {d.metadata.port ? `:${d.metadata.port}` : ""}
                          </Text>
                        ) : null}
                      </>
                    }
                    trailing={
                      <>
                        {d.pending_sync && (
                          <Badge variant="outline">{t("peripherals.pending_sync")}</Badge>
                        )}
                        <Badge tone={d.is_active ? "success" : "neutral"}>
                          {d.is_active ? t("peripherals.active") : t("peripherals.inactive")}
                        </Badge>
                      </>
                    }
                  />
                </Link>
              ))}

              {/* Local physical printers (workstation printer manager) */}
              {printers.map((p) => (
                <Link key={p.id} to={`/peripherals/printer/${p.id}`}>
                  <ListRow
                    leading={
                      p.status === "online" ? (
                        <Wifi aria-hidden className="text-success" />
                      ) : (
                        <WifiOff aria-hidden />
                      )
                    }
                    title={p.name}
                    description={
                      <>
                        {p.connection_type}
                        <Text as="span" size="xs" mono>
                          {"  "}
                          {p.address}
                        </Text>
                        {(p.roles ?? []).length > 0
                          ? `  ${(p.roles ?? []).map((r) => t(`printer.role.${r}`)).join(", ")}`
                          : ""}
                      </>
                    }
                    trailing={
                      <Badge tone={p.origin === "cloud" ? "info" : "neutral"}>
                        {p.origin === "cloud"
                          ? t("peripherals.origin.cloud")
                          : t("peripherals.origin.local")}
                      </Badge>
                    }
                  />
                </Link>
              ))}
            </CardContent>
          </Card>
        )}
      </PageContent>
    </>
  );
}
