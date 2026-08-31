"use client";
import { useMemo, useState } from "react";
import { Users } from "lucide-react";
import { Tabs, TabsList, TabsTrigger } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import type { TableResource, TableStatusValue } from "@/types/shop";
import { getTableStatusLabel, type TableStatus } from "@/types/models/enum/TableStatus";

export interface TablePickerProps {
  tables: TableResource[];
  value: string;
  onChange: (tableId: string) => void;
  error?: string;
}

const STATUS_STYLE: Record<TableStatusValue, { dot: string; tint: string; disabled: boolean }> = {
  free: { dot: "bg-emerald-500", tint: "bg-emerald-50/60", disabled: false },
  reserved: { dot: "bg-sky-500", tint: "bg-sky-50/60", disabled: false },
  occupied: { dot: "bg-amber-500", tint: "bg-amber-50/60", disabled: true },
  cleaning: { dot: "bg-slate-400", tint: "bg-slate-50", disabled: true },
  out_of_service: { dot: "bg-muted-foreground/40", tint: "bg-muted/40", disabled: true },
};

export function TablePicker({ tables, value, onChange, error }: TablePickerProps) {
  const { t } = useTranslation();
  // Build zone list from the tables we actually have — avoids a second fetch
  // and keeps the tab row in sync with what's pickable. Zones sort by code so
  // the picker stays stable across refreshes.
  const zones = useMemo(() => {
    const map = new Map<string, { id: string; code: string; name: string }>();
    for (const t of tables) {
      if (!map.has(t.zone.id)) map.set(t.zone.id, t.zone);
    }
    return Array.from(map.values()).sort((a, b) => a.code.localeCompare(b.code));
  }, [tables]);

  const [selectedZoneId, setSelectedZoneId] = useState<string>("");
  const activeZoneId = selectedZoneId || zones[0]?.id || "";

  const visibleTables = useMemo(
    () =>
      tables.filter((t) => t.zone.id === activeZoneId).sort((a, b) => a.code.localeCompare(b.code)),
    [tables, activeZoneId]
  );

  return (
    <div data-slot="table-picker" className="space-y-2">
      {zones.length > 1 && (
        <Tabs value={activeZoneId} onValueChange={setSelectedZoneId}>
          <TabsList className="h-7 flex-wrap">
            {zones.map((z) => (
              <TabsTrigger key={z.id} value={z.id} className="px-3 text-xs">
                {z.code}
                {z.name ? ` · ${z.name}` : ""}
              </TabsTrigger>
            ))}
          </TabsList>
        </Tabs>
      )}
      {visibleTables.length === 0 ? (
        <p className="py-4 text-center text-xs text-muted-foreground">
          {t("shop.orders.new.no_tables_zone")}
        </p>
      ) : (
        <div className="grid grid-cols-3 gap-1.5 sm:grid-cols-4">
          {visibleTables.map((tbl) => {
            const style = STATUS_STYLE[tbl.status];
            const disabled = style.disabled || !tbl.is_active;
            const selected = tbl.id === value;
            return (
              <button
                key={tbl.id}
                type="button"
                disabled={disabled}
                onClick={() => onChange(tbl.id)}
                className={[
                  "relative rounded border p-2 text-left transition-colors",
                  style.tint,
                  disabled ? "cursor-not-allowed opacity-50" : "hover:border-primary/60",
                  selected ? "border-primary ring-2 ring-primary" : "",
                ].join(" ")}
                title={getTableStatusLabel(tbl.status as TableStatus)}
              >
                <span className={`absolute top-1.5 right-1.5 size-1.5 rounded-full ${style.dot}`} />
                <p className="text-sm leading-tight font-semibold tabular-nums">{tbl.code}</p>
                {tbl.name && (
                  <p className="truncate text-[10px] leading-tight text-muted-foreground">
                    {tbl.name}
                  </p>
                )}
                <p className="mt-1 flex items-center gap-0.5 text-[10px] text-muted-foreground tabular-nums">
                  <Users className="size-3" />
                  {tbl.seat_count}
                </p>
              </button>
            );
          })}
        </div>
      )}
      {error && <p className="text-[11px] text-red-500">{error}</p>}
    </div>
  );
}
