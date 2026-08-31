"use client";

import { notFound, useParams, useRouter } from "next/navigation";
import { useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import {
  useCancelRecall,
  useCompleteRecall,
  useRecall,
  useRecallReport,
} from "@/hooks/api/use-recalls";
import { formatDateTime } from "@/lib/date";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { ApiError } from "@/lib/api";
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Spinner,
  StatusBadge,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
  Textarea,
} from "@godxjp/ui";

export default function HqRecallDetailPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const params = useParams<{ brandSlug: string; id: string }>();
  const router = useRouter();

  const { data, isLoading, error } = useRecall(params.brandSlug, params.id);
  const { data: reportEnvelope, isLoading: reportLoading } = useRecallReport(
    params.brandSlug,
    params.id
  );
  const complete = useCompleteRecall(params.brandSlug);
  const cancel = useCancelRecall(params.brandSlug);

  const [showCancel, setShowCancel] = useState(false);
  const [cancelReason, setCancelReason] = useState("");

  // 404 / 403 → Next.js not-found page (id doesn't exist or no access). Runs
  // before the loading guard so a missing id shows a proper 404 instead of
  // getting stuck on the spinner / a bare "not found" line (TC-RCL-DET2).
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

  const recall = data?.data;
  if (!recall) {
    return (
      <PageContent>
        <p className="text-muted-foreground">{t("common.not_found")}</p>
      </PageContent>
    );
  }

  const report = reportEnvelope?.data;
  const isActive = recall.status === "active";

  return (
    <>
      <PageHeader title={recall.recall_code} description={t("recall.detail_subtitle")}>
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => router.back()}>
            {t("common.back")}
          </Button>
          {isActive ? (
            <>
              <Button
                variant="outline"
                onClick={() => setShowCancel(true)}
                disabled={cancel.isPending}
              >
                {cancel.isPending ? <Spinner className="mr-2" /> : null}
                {t("recall.action_cancel")}
              </Button>
              <Button onClick={() => complete.mutate(recall.id)} disabled={complete.isPending}>
                {complete.isPending ? <Spinner className="mr-2" /> : null}
                {t("recall.action_complete")}
              </Button>
            </>
          ) : null}
        </div>
      </PageHeader>

      <PageContent>
        <Card>
          <CardHeader>
            <CardTitle>{t("recall.section_overview")}</CardTitle>
          </CardHeader>
          <CardContent className="grid gap-2 text-sm md:grid-cols-2">
            <div>
              <div className="text-muted-foreground">{t("recall.col_status")}</div>
              <StatusBadge status={t(`recall.status.${recall.status}`)} />
            </div>
            <div>
              <div className="text-muted-foreground">{t("recall.col_scope")}</div>
              <Badge variant="outline">{recall.scope_type}</Badge>
            </div>
            <div>
              <div className="text-muted-foreground">{t("recall.col_affected_lots")}</div>
              <div className="text-2xl font-bold tabular-nums">{recall.affected_lots_count}</div>
            </div>
            <div>
              <div className="text-muted-foreground">{t("recall.col_affected_orders")}</div>
              <div className="text-2xl font-bold tabular-nums">{recall.affected_orders_count}</div>
            </div>
            <div className="md:col-span-2">
              <div className="text-muted-foreground">{t("recall.col_reason")}</div>
              <p className="whitespace-pre-wrap">{recall.reason}</p>
            </div>
            {recall.cancellation_reason ? (
              <div className="md:col-span-2">
                <div className="text-muted-foreground">{t("recall.col_cancellation_reason")}</div>
                <p className="whitespace-pre-wrap text-amber-800">{recall.cancellation_reason}</p>
              </div>
            ) : null}
            <div>
              <div className="text-muted-foreground">{t("recall.col_initiated_at")}</div>
              <div>
                {recall.initiated_at ? formatDateTime(recall.initiated_at, locale, timezone) : "—"}
              </div>
            </div>
            {recall.completed_at ? (
              <div>
                <div className="text-muted-foreground">{t("recall.col_completed_at")}</div>
                <div>{formatDateTime(recall.completed_at, locale, timezone)}</div>
              </div>
            ) : null}
          </CardContent>
        </Card>

        <Tabs defaultValue="lots" className="mt-4">
          <TabsList>
            <TabsTrigger value="lots">
              {t("recall.tab_affected_lots")} ({recall.affected_lots_count})
            </TabsTrigger>
            <TabsTrigger value="orders">
              {t("recall.tab_affected_orders")} ({recall.affected_orders_count})
            </TabsTrigger>
          </TabsList>

          <TabsContent value="lots">
            <Card>
              <CardContent className="pt-4">
                {reportLoading ? (
                  <Spinner />
                ) : report?.affected_lots && report.affected_lots.length > 0 ? (
                  <div className="space-y-2 text-sm">
                    {report.affected_lots.map((l: Record<string, unknown>) => (
                      <div
                        key={String(l.id)}
                        className="flex items-center justify-between border-b py-1"
                      >
                        <div>
                          <div className="font-medium">{String(l.lot_code)}</div>
                          <div className="text-xs text-muted-foreground">
                            {String(l.supplier_name ?? "—")} · {String(l.supplier_lot_code ?? "—")}
                          </div>
                        </div>
                        <Badge>{String(l.status)}</Badge>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-sm text-muted-foreground">{t("recall.empty_lots")}</p>
                )}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="orders">
            <Card>
              <CardContent className="pt-4">
                {reportLoading ? (
                  <Spinner />
                ) : report?.affected_orders && report.affected_orders.length > 0 ? (
                  <div className="space-y-2 text-sm">
                    {report.affected_orders.map((o: Record<string, unknown>) => (
                      <div
                        key={String(o.id)}
                        className="flex items-center justify-between border-b py-1"
                      >
                        <span className="font-mono text-xs">{String(o.customer_order_id)}</span>
                        <Badge variant="outline">
                          {o.notified_at ? t("recall.notified") : t("recall.pending")}
                        </Badge>
                      </div>
                    ))}
                  </div>
                ) : (
                  <p className="text-sm text-muted-foreground">{t("recall.empty_orders")}</p>
                )}
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      </PageContent>

      <Dialog open={showCancel} onOpenChange={setShowCancel}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("recall.cancel_dialog_title")}</DialogTitle>
          </DialogHeader>
          <Textarea
            placeholder={t("recall.cancel_reason_placeholder")}
            value={cancelReason}
            onChange={(e) => setCancelReason(e.target.value)}
            rows={4}
            maxLength={2000}
            className="field-sizing-fixed"
          />
          <p className="text-right text-xs text-muted-foreground">{cancelReason.length}/2000</p>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowCancel(false)}>
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              disabled={cancelReason.trim().length < 3 || cancel.isPending}
              onClick={() => {
                cancel.mutate(
                  { id: recall.id, reason: cancelReason },
                  { onSuccess: () => setShowCancel(false) }
                );
              }}
            >
              {cancel.isPending ? <Spinner className="mr-2" /> : null}
              {t("recall.confirm_cancel")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
