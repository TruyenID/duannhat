/**
 * Shared table grid used by CreateOrderDialog, AssignTableDialog, and
 * ChangeTableDialog. Each tile shows the table code, seat count, and a
 * full-width status pill so staff can scan room state at a glance.
 *
 * Bàn nào bị khoá là luật của `lib/table-pick-eligibility.ts` — gương của cổng
 * backend. Bàn ĐÃ CÓ ĐƠN mới bị khoá; bàn `occupied` mà chưa có đơn (khách vừa
 * quét QR ngồi xuống) vẫn chọn được, vì backend cho phép đúng ca đó (#2606).
 */

import { useEffect, useMemo, useState } from "react";
import { Armchair, CheckIcon, UsersIcon } from "lucide-react";
import { cn } from "@/lib/utils";
import { tableNameSizeClass } from "@/lib/table-name";
import type { TableResource } from "../types";
import { getTableStatusMeta } from "../lib/table-status";
import { isTablePickBlocked } from "../lib/table-pick-eligibility";
import { useTranslation } from "@/providers/app-provider";

// Sentinel for "Tất cả" tab — null zoneId means no zone filter. Kept as a
// const so the filter logic stays readable (truthy check == filtered).
const ALL_ZONES: null = null;

interface ZoneTab {
  id: string;
  name: string;
  count: number;
}

function collectZones(tables: TableResource[]): ZoneTab[] {
  const map = new Map<string, ZoneTab>();
  for (const t of tables) {
    if (!t.zone) continue;
    const row = map.get(t.zone.id);
    if (row) row.count++;
    else map.set(t.zone.id, { id: t.zone.id, name: t.zone.name, count: 1 });
  }
  return [...map.values()];
}

/** Shared props regardless of mode. */
interface BaseTablePickerProps {
  tables: TableResource[];
  /** Additional table ids to disable (e.g. the order's current table). */
  disabledTableIds?: Set<string>;
  /** Tailwind max-height class (default: max-h-[60vh] for full-dialog usage). */
  maxHeightClass?: string;
  /**
   * Initial zone tab selection. null/undefined = "Tất cả" (no filter).
   * Caller (e.g. CreateOrderDialog) wires this from localStorage so staff
   * working a specific area don't re-pick the zone on every new order.
   * If the id doesn't match any current zone, falls back to "Tất cả".
   */
  defaultZoneId?: string | null;
}

export type TablePickerProps = BaseTablePickerProps &
  (
    | {
        multi?: false | undefined;
        selectedId: string | null;
        onSelect: (table: TableResource) => void;
      }
    | {
        multi: true;
        selectedIds: Set<string>;
        onToggle: (table: TableResource) => void;
      }
  );

export function TablePicker(props: TablePickerProps) {
  const {
    tables,
    disabledTableIds,
    maxHeightClass = "max-h-[60vh]",
    defaultZoneId,
  } = props;
  const multi = props.multi === true;
  const { t } = useTranslation();
  const TABLE_STATUS_META = useMemo(() => getTableStatusMeta(t), [t]);

  // Zone tabs — only shown when the shop has ≥2 zones; a single-zone shop
  // doesn't benefit from filtering and the row would just steal vertical
  // space. Selection is preserved across zone switches (parent owns the
  // selectedIds / selectedId state — we only filter the visible rows).
  const zones = useMemo(() => collectZones(tables), [tables]);
  const [activeZoneId, setActiveZoneId] = useState<string | null>(
    defaultZoneId ?? ALL_ZONES,
  );
  // Guard against a stored default pointing at a zone that no longer
  // exists for this shop (renamed, deleted, or cross-shop leak). Once
  // zones have loaded, fall back to "Tất cả" instead of leaving the grid
  // empty with no highlighted tab — staff can still click a real zone.
  useEffect(() => {
    if (!activeZoneId) return;
    if (zones.length === 0) return;
    if (!zones.some((z) => z.id === activeZoneId)) {
      setActiveZoneId(ALL_ZONES);
    }
  }, [activeZoneId, zones]);

  const showZoneTabs = zones.length >= 2;
  const visibleTables = useMemo(() => {
    if (!activeZoneId) return tables;
    return tables.filter((t) => t.zone?.id === activeZoneId);
  }, [tables, activeZoneId]);

  return (
    <div className="flex min-h-0 flex-col">
      {showZoneTabs && (
        <div
          data-slot="table-picker-zones"
          className="flex shrink-0 items-center gap-1 overflow-x-auto border-b bg-muted/30 px-3 py-1.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        >
          <ZonePill
            label={t("pos.table_picker.all_zones")}
            count={tables.length}
            active={activeZoneId === ALL_ZONES}
            onClick={() => setActiveZoneId(ALL_ZONES)}
          />
          {zones.map((z) => (
            <ZonePill
              key={z.id}
              label={z.name}
              count={z.count}
              active={activeZoneId === z.id}
              onClick={() => setActiveZoneId(z.id)}
            />
          ))}
        </div>
      )}
      <div className={cn("overflow-y-auto p-4", maxHeightClass)}>
        {visibleTables.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center">
            <Armchair className="size-8 text-muted-foreground/40" />
            <div className="text-xs text-muted-foreground">
              {t("pos.table_picker.no_tables")}
            </div>
          </div>
        ) : (
          <ul className="grid grid-cols-[repeat(auto-fill,minmax(140px,1fr))] gap-3">
            {visibleTables.map((table) => {
              // Defensive fallback: workstation LAN had a 2026-06 window
              // where the local `tables` replica was seeded with status
              // 'available' (Cloud's enum is 'free' / occupied / reserved
              // / cleaning / out_of_service). An indexed lookup with no
              // fallback crashed the whole picker when staff hit Đổi bàn
              // → Ghép bàn before the next sync DOWN. Use the `free` meta
              // for any unknown status so the tile stays renderable.
              const meta =
                TABLE_STATUS_META[table.status] ?? TABLE_STATUS_META.free;
              // Luật ở `lib/table-pick-eligibility.ts` — gương của cổng backend
              // `validateAndAssignTables`, có test riêng (#2606).
              const held = table.current_order_id != null;
              const disabled =
                disabledTableIds?.has(table.id) || isTablePickBlocked(table);
              const picked = multi
                ? props.selectedIds.has(table.id)
                : props.selectedId === table.id;
              const muted = disabled && !picked;
              // Nhãn "Đang phục vụ" chỉ dành cho bàn ĐÃ CÓ ĐƠN. Bàn `occupied`
              // mà chưa có đơn giữ nhãn trạng thái của chính nó — nếu gán nhãn
              // kia thì ô vừa mở khoá lại trông y như ô bị khoá (#2606).
              const statusText = held ? t("pos.table_picker.occupied") : meta.label;

              return (
                <li key={table.id}>
                  <button
                    type="button"
                    disabled={disabled}
                    onClick={() => {
                      if (disabled) return;
                      if (multi) props.onToggle(table);
                      else props.onSelect(table);
                    }}
                    data-slot="table-picker-tile"
                    data-picked={picked}
                    aria-pressed={picked}
                    className={cn(
                      "group relative flex aspect-5/4 w-full cursor-pointer flex-col rounded-2xl border-2 p-3 text-center transition-all duration-150",
                      "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                      "disabled:cursor-not-allowed disabled:opacity-70 disabled:bg-muted/40 disabled:shadow-none disabled:hover:translate-y-0 disabled:hover:border-border/60 disabled:hover:shadow-none",
                      picked
                        ? "border-primary bg-primary/5 shadow-sm"
                        : "border-border/60 bg-card shadow-sm hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md",
                    )}
                  >
                    {/* Selected checkmark — top-right, circle badge */}
                    {picked && (
                      <span className="absolute right-2 top-2 flex size-5 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-sm">
                        <CheckIcon className="size-3" strokeWidth={3} />
                      </span>
                    )}

                    {/* Body: table code + seat count, vertically centered */}
                    <div className="flex flex-1 flex-col items-center justify-center gap-1.5">
                      <div
                        title={table.name ?? table.code}
                        className={cn(
                          "w-full min-w-0 truncate font-bold leading-none tracking-tight",
                          tableNameSizeClass(table.name ?? table.code, "picker"),
                          muted ? "text-muted-foreground/70" : "text-foreground",
                        )}
                      >
                        {table.name ?? table.code}
                      </div>
                      <div
                        className={cn(
                          "flex items-center gap-1 text-xs font-medium",
                          muted ? "text-muted-foreground/60" : "text-muted-foreground",
                        )}
                      >
                        <UsersIcon className="size-3.5" strokeWidth={1.75} />
                        <span className="tabular-nums">{table.seat_count}</span>
                      </div>
                    </div>

                    {/* Status pill — full-width strip at the bottom */}
                    <div
                      className={cn(
                        "mt-2 w-full truncate rounded-full px-2 py-1 text-[11px] font-bold uppercase tracking-wider",
                        meta.badge,
                      )}
                    >
                      {statusText}
                    </div>
                  </button>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </div>
  );
}

interface ZonePillProps {
  label: string;
  count: number;
  active: boolean;
  onClick: () => void;
}

function ZonePill({ label, count, active, onClick }: ZonePillProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        "flex shrink-0 cursor-pointer items-center gap-1.5 rounded-full border px-3.5 py-1.5 text-xs transition-all duration-150",
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
        active
          ? "border-primary bg-primary text-primary-foreground shadow-sm"
          : "border-border/60 bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground",
      )}
    >
      <span className="font-medium">{label}</span>
      <span
        className={cn(
          "inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-semibold tabular-nums",
          active
            ? "bg-primary-foreground/20 text-primary-foreground"
            : "bg-muted-foreground/15 text-muted-foreground",
        )}
      >
        {count}
      </span>
    </button>
  );
}
