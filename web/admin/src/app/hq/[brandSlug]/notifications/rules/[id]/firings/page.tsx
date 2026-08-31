"use client";

/**
 * Plan-023 M7 T7.10 — HQ rule firings audit page.
 *
 * `/hq/[brandSlug]/notifications/rules/[id]/firings` — paginated
 * list of NotificationRuleFiring rows for one rule. Filter by
 * outcome; click a row to open the evaluation trace JSON in a
 * Sheet. Provides the admin "why didn't my rule fire?" debug
 * surface promised by the M7 design.
 */

import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  Skeleton,
  Spinner,
} from "@godxjp/ui";
import { AlertCircle, FileSearch, RotateCw } from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useState } from "react";
import { toast } from "sonner";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { useRule, useRuleFirings, useRetryFiring } from "@/hooks/api/use-notification-rules";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { ApiError } from "@/lib/api";
import { formatDateTime } from "@/lib/date";
import type { FiringRow } from "@/services/notification-rule-service";

const OUTCOMES = [
  "all",
  "matched",
  "skipped_cooldown",
  "skipped_condition",
  "shadow",
  "error",
] as const;
type OutcomeFilter = (typeof OUTCOMES)[number];

const OUTCOME_TONES: Record<string, "default" | "secondary" | "destructive" | "outline"> = {
  matched: "default",
  skipped_cooldown: "secondary",
  skipped_condition: "secondary",
  shadow: "outline",
  error: "destructive",
};

export default function RuleFiringsPage() {
  const { brandSlug, id } = useParams<{ brandSlug: string; id: string }>();
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  const [outcome, setOutcome] = useState<OutcomeFilter>("all");
  const [viewing, setViewing] = useState<FiringRow | null>(null);

  const rule = useRule(brandSlug, id);
  const firings = useRuleFirings(brandSlug, id, outcome === "all" ? {} : { outcome });
  const retry = useRetryFiring(brandSlug, id);

  // Track which firing is being retried so only its button shows a spinner.
  const [retryingId, setRetryingId] = useState<string | null>(null);

  async function handleRetry(row: FiringRow) {
    setRetryingId(row.id);
    try {
      await retry.mutateAsync(row.id);
      toast.success(t("notifications.rules.firings.retry_success"));
      setViewing(null);
    } catch (err) {
      const code = err instanceof ApiError ? (err.body?.error as string | undefined) : undefined;
      const message =
        code === "rule_inactive"
          ? t("notifications.rules.firings.retry_error_rule_inactive")
          : code === "model_missing" || code === "retry_noop"
            ? t("notifications.rules.firings.retry_error_model_missing")
            : t("notifications.rules.firings.retry_error");
      toast.error(message);
    } finally {
      setRetryingId(null);
    }
  }

  return (
    <>
      <PageHeader
        title={t("notifications.rules.firings.title")}
        description={rule.data?.data.name ?? t("notifications.rules.firings.subtitle")}
      >
        <Button asChild variant="outline" size="sm">
          <Link href={`/hq/${brandSlug}/notifications/rules`}>
            ← {t("notifications.rules.firings.back_to_rules")}
          </Link>
        </Button>
      </PageHeader>

      <PageContent>
        <div className="mb-4 flex flex-wrap items-center gap-1">
          {OUTCOMES.map((o) => (
            <Button
              key={o}
              size="sm"
              variant={outcome === o ? "default" : "outline"}
              onClick={() => setOutcome(o)}
            >
              {t(`notifications.rules.firings.outcome.${o}`)}
            </Button>
          ))}
        </div>

        {firings.isError ? (
          <Alert variant="destructive" className="mb-4">
            <AlertCircle className="size-4" />
            <AlertDescription className="flex items-center justify-between">
              {t("common.error_loading")}
              <Button variant="outline" size="sm" onClick={() => firings.refetch()}>
                {t("common.retry")}
              </Button>
            </AlertDescription>
          </Alert>
        ) : null}

        {firings.isLoading ? (
          <div className="space-y-2">
            {[0, 1, 2, 3].map((i) => (
              <Skeleton key={i} className="h-14 w-full" />
            ))}
          </div>
        ) : (firings.data?.data ?? []).length === 0 ? (
          <Card>
            <CardContent className="p-8 text-center text-sm text-muted-foreground">
              <FileSearch className="mx-auto mb-3 size-8 text-muted-foreground/60" />
              {t("notifications.rules.firings.empty")}
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-1">
            {(firings.data?.data ?? []).map((row) => (
              <Card
                key={row.id}
                className="cursor-pointer transition-colors hover:bg-muted/40"
                onClick={() => setViewing(row)}
                data-slot="firing-row"
              >
                <CardContent className="flex items-center gap-3 p-3">
                  <Badge variant={OUTCOME_TONES[row.outcome] ?? "secondary"}>
                    {t(`notifications.rules.firings.outcome.${row.outcome}`)}
                  </Badge>
                  <div className="min-w-0 flex-1">
                    <p className="text-sm">
                      {row.model_type ?? "—"}
                      {row.model_id ? ` · ${row.model_id}` : ""}
                    </p>
                    <p className="text-[10px] text-muted-foreground">
                      {formatDateTime(row.fired_at, locale, timezone)}
                    </p>
                  </div>
                  {row.outcome === "error" ? (
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={(e) => {
                        e.stopPropagation();
                        void handleRetry(row);
                      }}
                      disabled={retryingId === row.id}
                    >
                      {retryingId === row.id ? (
                        <Spinner className="mr-1.5 size-3.5" />
                      ) : (
                        <RotateCw className="mr-1.5 size-3.5" />
                      )}
                      {t("notifications.rules.firings.retry")}
                    </Button>
                  ) : null}
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </PageContent>

      {/* Trace Sheet */}
      <Sheet open={viewing !== null} onOpenChange={(o) => !o && setViewing(null)}>
        <SheetContent className="w-full sm:max-w-xl" data-slot="firing-detail">
          <SheetHeader>
            <SheetTitle>{t("notifications.rules.firings.detail.title")}</SheetTitle>
            <SheetDescription>
              {viewing
                ? `${t(`notifications.rules.firings.outcome.${viewing.outcome}`)} · ${formatDateTime(viewing.fired_at, locale, timezone)}`
                : ""}
            </SheetDescription>
          </SheetHeader>
          {viewing ? (
            <div className="mt-4 space-y-3 text-sm">
              <div className="grid grid-cols-3 gap-1">
                <span className="text-xs tracking-wide text-muted-foreground uppercase">
                  {t("notifications.rules.firings.detail.model_type")}
                </span>
                <span className="col-span-2 font-mono text-xs">{viewing.model_type ?? "-"}</span>
                <span className="text-xs tracking-wide text-muted-foreground uppercase">
                  {t("notifications.rules.firings.detail.model_id")}
                </span>
                <span className="col-span-2 font-mono text-xs">{viewing.model_id ?? "-"}</span>
              </div>

              {viewing.error_message ? (
                <Alert variant="destructive">
                  <AlertCircle className="size-4" />
                  <AlertDescription className="font-mono text-xs">
                    {viewing.error_message}
                  </AlertDescription>
                </Alert>
              ) : null}

              {viewing.outcome === "error" ? (
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => void handleRetry(viewing)}
                  disabled={retryingId === viewing.id}
                >
                  {retryingId === viewing.id ? (
                    <Spinner className="mr-1.5 size-3.5" />
                  ) : (
                    <RotateCw className="mr-1.5 size-3.5" />
                  )}
                  {t("notifications.rules.firings.retry")}
                </Button>
              ) : null}

              <div>
                <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                  {t("notifications.rules.firings.detail.trace")}
                </p>
                {viewing.evaluation_trace && viewing.evaluation_trace.length > 0 ? (
                  <pre className="max-h-96 overflow-auto rounded-md border bg-muted/30 p-2 font-mono text-[10px]">
                    {JSON.stringify(viewing.evaluation_trace, null, 2)}
                  </pre>
                ) : (
                  <p className="text-xs text-muted-foreground">
                    {t("notifications.rules.firings.detail.no_trace")}
                  </p>
                )}
              </div>

              {viewing.notification_id ? (
                <p className="text-xs">
                  <span className="text-muted-foreground">
                    {t("notifications.rules.firings.detail.notification_link")}:
                  </span>{" "}
                  <Link
                    href={`/hq/${brandSlug}/notifications?detail=${viewing.notification_id}`}
                    className="font-mono underline"
                  >
                    {viewing.notification_id}
                  </Link>
                </p>
              ) : null}
            </div>
          ) : null}
        </SheetContent>
      </Sheet>
    </>
  );
}
