"use client";

/**
 * The two badges that carry the ledger's honesty — plan-052 M2 / T2.2 (#1166).
 *
 * They are one file on purpose: the whole point of the confidence badge is
 * that it sits next to the status badge and contradicts a naive reading of it.
 * "printed" alone is a half-truth on a machine that cannot answer back
 * (printing.md §5, EDGE-CASES P-33), so a printed row always renders BOTH — a
 * green 「印字確認済み」 or an amber 「送信のみ（未確認）」 — and they never
 * share a colour.
 */

import { Badge } from "@godxjp/ui";
import { CheckCircle2, CircleHelp } from "lucide-react";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/providers/app-provider";
import { confidenceTone, isPrintedLabel, printJobStatusTone } from "@/lib/print-jobs";
import type { PrintJobConfidenceLabel, PrintJobStatus } from "@/types/models/PrintJob";

export function PrintJobStatusBadge({
  status,
  className,
}: {
  status: PrintJobStatus | null;
  className?: string;
}) {
  const { t } = useTranslation();
  if (!status) return <span className="text-xs text-muted-foreground">—</span>;

  return (
    <Badge
      variant="soft"
      color={printJobStatusTone(status)}
      className={cn("text-[10px] whitespace-nowrap", className)}
      data-testid={`job-status-${status}`}
    >
      {t(`print_jobs.status.${status}`)}
    </Badge>
  );
}

/**
 * Renders ONLY for a printed job — a queued slip has no confidence question to
 * answer. The icon differs as well as the colour so the distinction survives a
 * greyscale screenshot and a red/green-blind reader.
 */
export function PrintJobConfidenceBadge({
  label,
  className,
}: {
  label: PrintJobConfidenceLabel | null;
  className?: string;
}) {
  const { t } = useTranslation();

  if (!isPrintedLabel(label)) return null;

  const tone = confidenceTone(label);
  const confirmed = label === "printed_confirmed";
  const Icon = confirmed ? CheckCircle2 : CircleHelp;

  return (
    <Badge
      variant="soft"
      color={tone ?? "primary"}
      className={cn("gap-1 text-[10px] whitespace-nowrap", className)}
      title={t(`print_jobs.confidence.${label}_help`)}
      data-testid={`job-confidence-${label}`}
    >
      <Icon className="size-3" />
      {t(`print_jobs.confidence.${label}`)}
    </Badge>
  );
}

/** Small neutral pill for kind / transport / queue owner. */
export function MetaPill({
  children,
  emphasis = false,
  className,
}: {
  children: React.ReactNode;
  emphasis?: boolean;
  className?: string;
}) {
  return (
    <span
      className={cn(
        "rounded border px-1.5 py-0.5 text-[11px] whitespace-nowrap",
        emphasis
          ? "border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200"
          : "border-border bg-muted text-muted-foreground",
        className
      )}
    >
      {children}
    </span>
  );
}
