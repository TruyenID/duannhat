"use client";

import { notFound, useParams, useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import {
  useDisposeLot,
  useHqMaterialLot,
  useQuarantineLot,
  useReleaseLot,
} from "@/hooks/api/use-material-lots";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { ApiError } from "@/lib/api";
import { formatDate, formatDateTime, daysUntilExpiry } from "@/lib/date";
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Input,
  Spinner,
  StatusBadge,
} from "@godxjp/ui";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";

import { LotTimeline } from "./components/lot-timeline";

export default function HqMaterialLotDetailPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const params = useParams<{ brandSlug: string; id: string }>();
  const router = useRouter();

  const { data, isLoading, error } = useHqMaterialLot(params.brandSlug, params.id);
  const quarantine = useQuarantineLot(params.brandSlug);
  const release = useReleaseLot(params.brandSlug);
  const dispose = useDisposeLot(params.brandSlug);

  const [reason, setReason] = useState("");
  const [confirmDispose, setConfirmDispose] = useState(false);

  // 404 / 403 → Next.js not-found page (id doesn't exist or no access). Runs
  // before the loading guard so a missing id shows a proper 404 instead of
  // getting stuck on the spinner / a bare "not found" line (TC-LOT-DET2).
  if (error instanceof ApiError && (error.status === 404 || error.status === 403)) {
    notFound();
  }

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

  const canQuarantine = lot.status === "active";
  const canRelease = lot.status === "quarantined";
  const canDispose = lot.status !== "disposed";

  return (
    <>
      <PageHeader title={lot.lot_code} description={t("material_lot.detail_subtitle")}>
        <Button variant="outline" onClick={() => router.back()}>
          {t("common.back")}
        </Button>
      </PageHeader>
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
                  {lot.received_temperature
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

          <Card className="md:col-span-2">
            <CardHeader>
              <CardTitle>{t("material_lot.section_actions")}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-3">
              {lot.quarantine_reason ? (
                <p className="rounded-md bg-amber-50 p-3 text-sm text-amber-900">
                  <strong>{t("material_lot.quarantine_reason")}:</strong> {lot.quarantine_reason}
                </p>
              ) : null}

              {canQuarantine ? (
                <div className="flex items-center gap-2">
                  <Input
                    placeholder={t("material_lot.quarantine_reason_placeholder")}
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                  />
                  <Button
                    variant="secondary"
                    onClick={() =>
                      quarantine.mutate(
                        { id: lot.id, input: { reason } },
                        { onSuccess: () => setReason("") }
                      )
                    }
                    disabled={quarantine.isPending || reason.trim().length < 3}
                  >
                    {quarantine.isPending ? <Spinner className="mr-2" /> : null}
                    {t("material_lot.action_quarantine")}
                  </Button>
                </div>
              ) : null}

              {canRelease ? (
                <Button onClick={() => release.mutate({ id: lot.id })} disabled={release.isPending}>
                  {release.isPending ? <Spinner className="mr-2" /> : null}
                  {t("material_lot.action_release")}
                </Button>
              ) : null}

              {canDispose ? (
                <Button
                  variant="destructive"
                  onClick={() => {
                    if (Number(lot.qty_on_hand) > 0) {
                      setConfirmDispose(true);
                    } else {
                      dispose.mutate({ id: lot.id, input: { force: false } });
                    }
                  }}
                  disabled={dispose.isPending}
                >
                  {dispose.isPending ? <Spinner className="mr-2" /> : null}
                  {t("material_lot.action_dispose")}
                </Button>
              ) : null}
            </CardContent>
          </Card>

          <Card className="md:col-span-2">
            <CardHeader>
              <CardTitle>{t("material_lot.section_timeline")}</CardTitle>
            </CardHeader>
            <CardContent>
              <LotTimeline brandSlug={params.brandSlug} lotId={params.id} />
            </CardContent>
          </Card>
        </div>
      </PageContent>

      <DeleteConfirmDialog
        open={confirmDispose}
        onOpenChange={setConfirmDispose}
        title={t("material_lot.action_dispose")}
        description={t("material_lot.dispose_confirm_description", {
          qty: Number(lot.qty_on_hand).toLocaleString(locale),
          unit: lot.unit ?? "",
        })}
        onConfirm={async () => {
          await dispose.mutateAsync({ id: lot.id, input: { force: true } });
          setConfirmDispose(false);
        }}
        isPending={dispose.isPending}
      />
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
