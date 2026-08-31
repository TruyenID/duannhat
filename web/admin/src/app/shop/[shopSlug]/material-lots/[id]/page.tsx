"use client";

import { useParams, useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { Scissors } from "lucide-react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { useShopMaterialLot } from "@/hooks/api/use-material-lots";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate, formatDateTime, daysUntilExpiry } from "@/lib/date";
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Spinner,
  StatusBadge,
} from "@godxjp/ui";

import { SplitLotDialog } from "./components/split-lot-dialog";

/**
 * Shop-scope material lot detail page. Read-only: the lifecycle actions
 * (quarantine / release / dispose) live on the HQ surface only — shop
 * staff just inspect lots their warehouse holds. Plan-017 design.
 */
export default function ShopMaterialLotDetailPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const params = useParams<{ shopSlug: string; id: string }>();
  const router = useRouter();
  const [splitOpen, setSplitOpen] = useState(false);

  const { data, isLoading } = useShopMaterialLot(params.shopSlug, params.id);

  if (isLoading) {
    return (
      <PageContent>
        <Spinner className="mx-auto" />
      </PageContent>
    );
  }

  const lot = data?.data;
  if (!lot) {
    return (
      <PageContent>
        <p className="text-muted-foreground">{t("common.not_found")}</p>
      </PageContent>
    );
  }

  return (
    <>
      <PageHeader title={lot.lot_code} description={t("material_lot.detail_subtitle")}>
        <Button variant="outline" onClick={() => router.back()}>
          {t("common.back")}
        </Button>
        {lot.status === "active" && Number(lot.qty_on_hand) > 0 && (
          <Button variant="outline" onClick={() => setSplitOpen(true)}>
            <Scissors className="mr-1.5 size-3.5" />
            {t("material_lot.action_split")}
          </Button>
        )}
      </PageHeader>
      <SplitLotDialog
        shopSlug={params.shopSlug}
        lot={lot}
        open={splitOpen}
        onOpenChange={setSplitOpen}
      />
      <PageContent>
        <div className="grid gap-4 md:grid-cols-2">
          <Card>
            <CardHeader>
              <CardTitle>{t("material_lot.section_overview")}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("material_lot.col_status")}</span>
                <StatusBadge status={lot.status} />
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("material_lot.col_source")}</span>
                <Badge variant="outline">{lot.source}</Badge>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("material_lot.col_qty_on_hand")}</span>
                <span className="font-medium tabular-nums">
                  {Number(lot.qty_on_hand).toLocaleString(locale)} {lot.unit}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("material_lot.col_received_qty")}</span>
                <span className="tabular-nums">
                  {Number(lot.received_qty).toLocaleString(locale)} {lot.unit}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("material_lot.col_expiry")}</span>
                {lot.expiry_date ? (
                  <span>{formatDate(lot.expiry_date, locale, timezone)}</span>
                ) : (
                  <Badge variant="outline" className="h-5 px-1.5 text-xs font-medium">
                    {t("material_lot.no_expiry")}
                  </Badge>
                )}
              </div>
              {lot.expiry_date ? (
                <div className="flex justify-between">
                  <span className="text-muted-foreground">
                    {t("material_lot.col_days_until_expiry")}
                  </span>
                  <DaysUntilBadge expiryDate={lot.expiry_date} timezone={timezone} />
                </div>
              ) : null}
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("material_lot.col_received_at")}</span>
                <span>{formatDateTime(lot.received_at, locale, timezone)}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("material_lot.col_warehouse")}</span>
                <span>{lot.warehouse?.name ?? "—"}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("material_lot.col_material")}</span>
                <span className="font-mono text-xs">{lot.material?.sku ?? lot.material_id}</span>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{t("material_lot.section_supplier")}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("material_lot.col_supplier")}</span>
                <span>{lot.supplier_name ?? "—"}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">
                  {t("material_lot.col_supplier_lot_code")}
                </span>
                <span className="font-mono text-xs">{lot.supplier_lot_code ?? "—"}</span>
              </div>
              {lot.coa_urls && lot.coa_urls.length > 0 ? (
                <div className="space-y-1">
                  <span className="text-muted-foreground">{t("material_lot.col_coa_urls")}</span>
                  <div className="space-y-1">
                    {lot.coa_urls.map((coa, i) => (
                      <div key={i} className="flex items-center justify-between">
                        <Badge variant="outline" className="text-xs">
                          {coa.language}
                        </Badge>
                        <a
                          href={coa.url}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="text-sm text-emerald-700 hover:underline"
                        >
                          {t("material_lot.coa_open")}
                        </a>
                      </div>
                    ))}
                  </div>
                </div>
              ) : null}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{t("material_lot.section_cost")}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("material_lot.col_unit_cost")}</span>
                <span className="tabular-nums">
                  {lot.unit_cost
                    ? `${Number(lot.unit_cost).toLocaleString(locale)} ${lot.currency ?? ""}`
                    : "—"}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("material_lot.col_total_cost")}</span>
                <span className="font-medium tabular-nums">
                  {lot.total_cost
                    ? `${Number(lot.total_cost).toLocaleString(locale)} ${lot.currency ?? ""}`
                    : "—"}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">{t("material_lot.col_cost_basis")}</span>
                <Badge variant="outline">{lot.cost_basis ?? "—"}</Badge>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{t("material_lot.section_temperature")}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
              <div className="flex justify-between">
                <span className="text-muted-foreground">
                  {t("material_lot.col_received_temperature")}
                </span>
                <span className="tabular-nums">
                  {lot.received_temperature !== null && lot.received_temperature !== undefined
                    ? `${Number(lot.received_temperature).toLocaleString(locale)} °C`
                    : "—"}
                </span>
              </div>
              <div className="flex justify-between">
                <span className="text-muted-foreground">
                  {t("material_lot.col_temperature_compliance")}
                </span>
                {lot.is_temperature_compliant === true ? (
                  <Badge className="bg-emerald-100 text-emerald-800">
                    {t("material_lot.compliance_ok")}
                  </Badge>
                ) : lot.is_temperature_compliant === false ? (
                  <Badge variant="destructive">{t("material_lot.compliance_override")}</Badge>
                ) : (
                  <Badge variant="outline">{t("material_lot.compliance_unset")}</Badge>
                )}
              </div>
              {lot.temperature_override_reason ? (
                <div>
                  <span className="text-muted-foreground">
                    {t("material_lot.col_override_reason")}:
                  </span>
                  <p className="mt-1 rounded bg-amber-50 p-2 text-xs text-amber-900">
                    {lot.temperature_override_reason}
                  </p>
                </div>
              ) : null}
            </CardContent>
          </Card>

          {lot.quarantine_reason ? (
            <Card className="border-amber-200 md:col-span-2">
              <CardHeader>
                <CardTitle>{t("material_lot.quarantine_reason")}</CardTitle>
              </CardHeader>
              <CardContent>
                <p className="rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                  {lot.quarantine_reason}
                </p>
                <p className="mt-2 text-xs text-muted-foreground">
                  {t("material_lot.shop_action_hint")}
                </p>
              </CardContent>
            </Card>
          ) : null}
        </div>
      </PageContent>
    </>
  );
}

function DaysUntilBadge({ expiryDate, timezone }: { expiryDate: string; timezone: string }) {
  const { t } = useTranslation();
  const [now, setNow] = useState<number | null>(null);
  useEffect(() => {
    setNow(Date.now());
  }, []);

  const days = useMemo(
    () => (now === null ? null : daysUntilExpiry(expiryDate, now, timezone)),
    [expiryDate, now, timezone]
  );

  if (days === null) return <span className="text-muted-foreground">…</span>;
  if (days < 0) return <Badge variant="destructive">{t("material_lot.expired")}</Badge>;
  if (days <= 1) return <Badge variant="destructive">1d</Badge>;
  if (days <= 7) return <Badge className="bg-amber-100 text-amber-800">{days}d</Badge>;
  return <span className="tabular-nums">{days}d</span>;
}
