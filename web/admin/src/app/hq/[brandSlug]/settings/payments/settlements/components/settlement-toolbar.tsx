"use client";

import type { ReactNode } from "react";
import { Download } from "lucide-react";
import { Button, Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";

import { useTranslation } from "@/providers/app-provider";
import { humanizeCode, type SettlementConnectionOption } from "../lib/settlement-view";

export interface SettlementToolbarProps {
  connections: SettlementConnectionOption[];
  connectionId: string;
  onConnectionChange: (value: string) => void;
  /** Codes discovered in the loaded rows — never a hardcoded enum. */
  statusOptions?: string[];
  status?: string;
  onStatusChange?: (value: string) => void;
  onExport: () => void;
  exportDisabled: boolean;
  /**
   * Note under the export button, already translated. Defaults to the
   * paginated wording; the aging tab overrides it because that endpoint returns
   * the whole report in one response, so its file really is everything.
   */
  scopeNote?: string;
  /** Extra filter controls rendered before the export button. */
  children?: ReactNode;
}

/**
 * Filter bar + CSV export for one settlement tab (#1157 T5.2).
 *
 * The export note is not decoration. The CSV is built in the browser from the
 * rows currently on screen, because the backend has no settlement export
 * endpoint (four GET routes, all paginated — see
 * `backend/routes/api/hq/settlements.php`). An accountant who exports page 1 of
 * 40 and files it as "the month" has a wrong book and no way to notice, so the
 * scope of the file is stated next to the button that produces it.
 */
export function SettlementToolbar({
  connections,
  connectionId,
  onConnectionChange,
  statusOptions,
  status,
  onStatusChange,
  onExport,
  exportDisabled,
  scopeNote,
  children,
}: SettlementToolbarProps) {
  const { t } = useTranslation();

  return (
    <div data-slot="settlement-toolbar" className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-2">
        <Select value={connectionId} onValueChange={onConnectionChange}>
          <SelectTrigger className="h-8 w-56 text-xs">
            <SelectValue placeholder={t("hq.settlements.filter.connection")} />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{t("hq.settlements.filter.all_connections")}</SelectItem>
            {connections.map((connection) => (
              <SelectItem key={connection.id} value={connection.id}>
                {connection.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        {statusOptions && onStatusChange ? (
          <Select value={status ?? "all"} onValueChange={onStatusChange}>
            <SelectTrigger className="h-8 w-44 text-xs">
              <SelectValue placeholder={t("hq.settlements.filter.status")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("hq.settlements.filter.all_statuses")}</SelectItem>
              {statusOptions.map((code) => (
                <SelectItem key={code} value={code}>
                  {humanizeCode(code)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        ) : null}

        {children}

        <Button
          variant="outline"
          size="sm"
          className="ml-auto h-8 gap-1.5 text-xs"
          onClick={onExport}
          disabled={exportDisabled}
        >
          <Download className="size-3.5" />
          {t("hq.settlements.export.button")}
        </Button>
      </div>

      <p className="text-xs text-muted-foreground">
        {scopeNote ?? t("hq.settlements.export.scope_note")}
      </p>
    </div>
  );
}
