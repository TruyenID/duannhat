"use client";

/**
 * Plan-032 T7.3 — Stale sessions table (restored for the "Ca treo" tab).
 *
 * Renders GET /pos/till/sessions/stale rows. The action menu per row exposes
 * "View detail" (always → navigates to the canonical /till/sessions/{id}
 * detail route), "Force abandon" (status ∈ open|closing), and "Manual settle"
 * (status=expired). `opened_at` is locale-formatted via formatDateTime to match
 * the History tab — the original table rendered the raw ISO string.
 */

import {
  Badge,
  Button,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { MoreHorizontalIcon } from "lucide-react";

import { useTranslation } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
import { useShopTimezone } from "@/providers/shop-timezone-provider";
import type { StaleSession } from "@/services/till-session-admin-service";

export interface StaleSessionsTableProps {
  rows: StaleSession[];
  onViewDetail: (s: StaleSession) => void;
  onForceAbandon: (s: StaleSession) => void;
  onManualSettle: (s: StaleSession) => void;
}

export function StaleSessionsTable({
  rows,
  onViewDetail,
  onForceAbandon,
  onManualSettle,
}: StaleSessionsTableProps) {
  const { t, locale } = useTranslation();
  // Shop-scoped screen: timestamps belong to the shop's clock, not the
  // viewer's browser (#1248). Null outside a shop route, which makes
  // formatDateTime fall back to the old behaviour.
  const shopTimezone = useShopTimezone();

  return (
    <div data-slot="stale-sessions-table" className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead className="border-b text-left text-xs text-muted-foreground uppercase">
          <tr>
            <th className="px-3 py-2">{t("till_sessions.column.session")}</th>
            <th className="px-3 py-2">{t("till_sessions.column.status")}</th>
            <th className="px-3 py-2">{t("till_sessions.column.opened_at")}</th>
            <th className="px-3 py-2">{t("till_sessions.column.currency")}</th>
            <th className="w-12 px-3 py-2 text-right">{t("till_sessions.column.actions")}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((s) => {
            const canForceAbandon = s.status === "open" || s.status === "closing";
            const canManualSettle = s.status === "expired";
            return (
              <tr key={s.id} className="border-b last:border-0 hover:bg-muted/30">
                <td className="px-3 py-2">
                  <button
                    type="button"
                    onClick={() => onViewDetail(s)}
                    className="font-mono text-xs text-primary hover:underline"
                  >
                    {s.session_code}
                  </button>
                </td>
                <td className="px-3 py-2">
                  <Badge variant={s.force_abandoned ? "destructive" : "outline"}>
                    {t(`till_sessions.status.${s.status}`)}
                  </Badge>
                </td>
                <td className="px-3 py-2 text-xs text-muted-foreground">
                  {s.opened_at ? formatDateTime(s.opened_at, locale, shopTimezone) : "—"}
                </td>
                <td className="px-3 py-2 text-xs">{s.default_currency_code}</td>
                <td className="px-3 py-2 text-right">
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="ghost" size="icon" className="size-8" aria-label="actions">
                        <MoreHorizontalIcon className="size-4" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      <DropdownMenuItem onSelect={() => onViewDetail(s)}>
                        {t("till_sessions.actions.view_detail")}
                      </DropdownMenuItem>
                      {canForceAbandon && (
                        <DropdownMenuItem onSelect={() => onForceAbandon(s)}>
                          {t("till_sessions.actions.force_abandon")}
                        </DropdownMenuItem>
                      )}
                      {canManualSettle && (
                        <DropdownMenuItem onSelect={() => onManualSettle(s)}>
                          {t("till_sessions.actions.manual_settle")}
                        </DropdownMenuItem>
                      )}
                    </DropdownMenuContent>
                  </DropdownMenu>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
