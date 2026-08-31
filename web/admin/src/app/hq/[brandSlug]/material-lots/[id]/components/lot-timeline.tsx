"use client";

import { useState } from "react";

import { useLotTimeline } from "@/hooks/api/use-material-lots";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
import type { TimelineEntryType } from "@/services/material-lot-service";
import { Badge, Button, Spinner } from "@godxjp/ui";

// =========================================================================
//  Props
// =========================================================================

export interface LotTimelineProps {
  brandSlug: string;
  lotId: string;
}

// =========================================================================
//  Constants
// =========================================================================

const ALL_TYPES: TimelineEntryType[] = ["audit", "movement", "genealogy", "expiry_alert", "recall"];

const TYPE_COLORS: Record<TimelineEntryType, string> = {
  audit: "bg-blue-100 text-blue-800",
  movement: "bg-violet-100 text-violet-800",
  genealogy: "bg-emerald-100 text-emerald-800",
  expiry_alert: "bg-amber-100 text-amber-800",
  recall: "bg-red-100 text-red-800",
};

// =========================================================================
//  Component
// =========================================================================

export function LotTimeline({ brandSlug, lotId }: LotTimelineProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const [page, setPage] = useState(1);
  const [selectedTypes, setSelectedTypes] = useState<string[]>([]);

  const filters = {
    page,
    per_page: 50,
    ...(selectedTypes.length > 0 ? { types: selectedTypes } : {}),
  };

  const { data, isLoading, isFetching } = useLotTimeline(brandSlug, lotId, filters);

  const toggleType = (type: string) => {
    setSelectedTypes((prev) =>
      prev.includes(type) ? prev.filter((t) => t !== type) : [...prev, type]
    );
    setPage(1);
  };

  return (
    <div data-slot="lot-timeline" className="space-y-4">
      {/* Type filter */}
      <div className="space-y-1.5">
        <span className="text-sm text-muted-foreground">
          {t("material_lot.timeline_filter_label")}
        </span>
        <div className="flex flex-wrap gap-1.5">
          {ALL_TYPES.map((type) => (
            <button
              key={type}
              type="button"
              onClick={() => toggleType(type)}
              className={`inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-medium transition-colors ${
                selectedTypes.length === 0 || selectedTypes.includes(type)
                  ? TYPE_COLORS[type]
                  : "bg-muted text-muted-foreground opacity-50"
              }`}
            >
              {t(`material_lot.timeline_type_${type}`)}
            </button>
          ))}
        </div>
      </div>

      {/* Loading */}
      {isLoading ? (
        <div className="flex justify-center py-8">
          <Spinner />
        </div>
      ) : !data?.data || data.data.length === 0 ? (
        <p className="py-8 text-center text-sm text-muted-foreground">
          {t("material_lot.timeline_empty")}
        </p>
      ) : (
        <>
          {/* Timeline entries */}
          <div className="relative space-y-3">
            {data.data.map((entry, i) => (
              <div
                key={`${entry.type}-${entry.occurred_at}-${i}`}
                className="flex items-start gap-3 rounded-md border p-3 text-sm"
              >
                <Badge className={`shrink-0 ${TYPE_COLORS[entry.type]}`}>
                  {t(`material_lot.timeline_type_${entry.type}`)}
                </Badge>
                <span className="flex-1 text-muted-foreground">{summarizeEntry(entry)}</span>
                <time className="shrink-0 text-xs text-muted-foreground tabular-nums">
                  {formatDateTime(entry.occurred_at, locale, timezone)}
                </time>
              </div>
            ))}
          </div>

          {/* Pagination */}
          {data.meta && (
            <div className="flex items-center justify-between pt-2">
              <span className="text-xs text-muted-foreground">
                {data.meta.from}–{data.meta.to} / {data.meta.total}
              </span>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page <= 1 || isFetching}
                >
                  Prev
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setPage((p) => p + 1)}
                  disabled={page >= (data.meta?.last_page ?? 1) || isFetching}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}

// =========================================================================
//  Helpers
// =========================================================================

function summarizeEntry(entry: Record<string, unknown>): string {
  // Build a one-line summary from whichever fields the backend sends.
  const parts: string[] = [];
  if (typeof entry.description === "string") parts.push(entry.description);
  if (typeof entry.action === "string") parts.push(entry.action);
  if (typeof entry.from_status === "string" && typeof entry.to_status === "string") {
    parts.push(`${entry.from_status} → ${entry.to_status}`);
  }
  if (typeof entry.qty === "number" || typeof entry.qty === "string") {
    parts.push(`qty: ${entry.qty}`);
  }
  return parts.length > 0 ? parts.join(" — ") : String(entry.type ?? "");
}
