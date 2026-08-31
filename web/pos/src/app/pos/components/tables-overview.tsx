/**
 * TablesOverview — full-screen view shown when the "Tổng quan" overview tab
 * is active. Displays every table in the shop grouped by zone, with a chip
 * showing the order_code for any table currently serving an order. Clicking
 * a serving table opens (or switches to) that order's tab. Read-only otherwise.
 */

import { useMemo, useState } from "react";
import { Armchair, ArrowRightIcon, CheckIcon, ChevronRightIcon, HistoryIcon, LayoutGridIcon, MoreVerticalIcon, ReceiptIcon, ShoppingBagIcon, UsersIcon } from "lucide-react";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import { cn } from "@/lib/utils";
import { tableNameSizeClass } from "@/lib/table-name";
import { HelpButton } from "@/help/help-button";
import { useTranslation } from "@/providers/app-provider";
import type { CustomerOrder, TableResource, TableStatusValue } from "../types";
import { getTableStatusMeta } from "../lib/table-status";
import { tableTileAction } from "../lib/table-tile-action";
import { formatCurrency } from "../lib/totals";

// The full set of table statuses, in the shop's canonical order (admin-web's
// TableStatusValues). The change-status menu offers all of them — the current
// one is shown with a check and is a no-op — exactly like the shop.
const ALL_STATUSES: TableStatusValue[] = [
  "free",
  "occupied",
  "reserved",
  "cleaning",
  "out_of_service",
];

// Card background tints — colour families matched to the shop's canonical
// status palette (free=emerald, occupied=amber, reserved=blue, cleaning=gray,
// out_of_service=red).
const STATUS_TINT: Record<TableStatusValue, string> = {
  free: "bg-card",
  occupied: "bg-amber-50/60 dark:bg-amber-500/5",
  reserved: "bg-blue-50/60 dark:bg-blue-500/5",
  cleaning: "bg-gray-50/70 dark:bg-gray-500/5",
  out_of_service: "bg-red-50/60 dark:bg-red-500/5",
};

// Small colour dot beside each status in the change-status menu — same palette
// as the shop's TableStatusMenu.
const STATUS_DOT: Record<TableStatusValue, string> = {
  free: "bg-emerald-500",
  occupied: "bg-amber-500",
  reserved: "bg-blue-500",
  cleaning: "bg-gray-400",
  out_of_service: "bg-red-500",
};

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

export interface TablesOverviewProps {
  tables: TableResource[];
  /** Open orders — used to map current_order_id → order_code. */
  orders: CustomerOrder[];
  /** Open or switch to the tab for this order. */
  onOpenOrder: (orderId: string) => void;
  /**
   * Open the create-order dialog. When the user tapped a specific free
   * table, its id is forwarded so the dialog can pre-tick that table —
   * staff doesn't have to pick again. `undefined` = generic "+" trigger.
   */
  onCreateOrder: (presetTableId?: string) => void;
  /**
   * Change a table's status (free / reserved / cleaning / out_of_service).
   * Only offered for tables with NO active order. Omit to hide the control.
   */
  onChangeStatus?: (tableId: string, status: TableStatusValue) => void;
  /**
   * Number of active takeaway orders — takeaway orders have no table so they
   * never appear on this grid. Drives the "Đơn takeaway" button's badge.
   */
  takeawayCount?: number;
  /** Open the takeaway-orders drawer. Omit to hide the takeaway button. */
  onOpenTakeaway?: () => void;
  /** Open the per-table order/payment history view. Offered on every table. */
  onViewHistory?: (tableId: string) => void;
}

export function TablesOverview({
  tables,
  orders,
  onOpenOrder,
  onCreateOrder,
  onChangeStatus,
  takeawayCount = 0,
  onOpenTakeaway,
  onViewHistory,
}: TablesOverviewProps) {
  const { t } = useTranslation();
  const TABLE_STATUS_META = useMemo(() => getTableStatusMeta(t), [t]);

  const [activeZoneId, setActiveZoneId] = useState<string | null>(ALL_ZONES);

  const zones = useMemo(() => collectZones(tables), [tables]);
  const showZoneTabs = zones.length >= 2;

  const visibleTables = useMemo(() => {
    if (!activeZoneId) return tables;
    return tables.filter((tb) => tb.zone?.id === activeZoneId);
  }, [tables, activeZoneId]);

  // orderId → CustomerOrder map for fast lookup
  const orderById = useMemo(() => {
    const m = new Map<string, CustomerOrder>();
    for (const o of orders) m.set(o.id, o);
    return m;
  }, [orders]);

  // Stats — over the visible (filtered) tables
  const stats = useMemo(() => {
    let occupied = 0;
    let seatedNoOrder = 0;
    let free = 0;
    let other = 0;
    let totalSeats = 0;
    let occupiedSeats = 0;
    for (const tb of visibleTables) {
      totalSeats += tb.seat_count;
      if (tb.current_order_id || tb.status === "occupied") {
        occupied++;
        occupiedSeats += tb.seat_count;
        // #3009 — bàn CÓ NGƯỜI nhưng CHƯA CÓ ĐƠN đếm riêng. Gộp vào "đang
        // phục vụ" làm con số đó trả lời sai đúng câu quán hỏi nó ("còn mấy
        // bàn nhận khách được"), và 本郷店 đã đọc nó thành "đơn hiện nhầm bàn".
        if (!tb.current_order_id) seatedNoOrder++;
      } else if (tb.status === "free") {
        free++;
      } else {
        other++;
      }
    }
    return { occupied, seatedNoOrder, free, other, totalSeats, occupiedSeats,
             total: visibleTables.length };
  }, [visibleTables]);

  const totalGuests = useMemo(
    () =>
      orders.reduce(
        (sum, o) => sum + (typeof o.guest_count === "number" ? o.guest_count : 0),
        0,
      ),
    [orders],
  );

  return (
    <div className="flex min-h-0 flex-1 flex-col overflow-hidden bg-background">
      {/* Sticky header — title + stats */}
      <div className="shrink-0 border-b bg-card px-3 py-3 sm:px-6 sm:py-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-2 sm:gap-3">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary sm:size-10">
              <LayoutGridIcon className="size-4 sm:size-5" />
            </span>
            <div className="min-w-0">
              <h2 className="truncate text-base font-bold tracking-tight sm:text-lg">{t("pos.overview.title")}</h2>
              <p className="hidden text-xs text-muted-foreground sm:block">
                {t("pos.overview.desc")}
              </p>
            </div>
            <HelpButton topic="tables-overview" />
          </div>

          <div className="grid w-full grid-cols-2 gap-1.5 sm:flex sm:w-auto sm:flex-wrap sm:items-center sm:gap-2">
            <StatPill
              label={t("pos.overview.stat.serving")}
              value={`${stats.occupied - stats.seatedNoOrder} / ${stats.total}`}
              dotClass="bg-primary"
            />
            {/* Chỉ hiện khi CÓ — một ô luôn bằng 0 là nhiễu, và nhiễu lặp lại
                thì người ta thôi đọc cả thanh này. */}
            {stats.seatedNoOrder > 0 && (
              <StatPill
                label={t("pos.overview.stat.seated_no_order")}
                value={String(stats.seatedNoOrder)}
                dotClass="bg-amber-500"
              />
            )}
            <StatPill
              label={t("pos.overview.stat.open_orders")}
              value={String(orders.length)}
              dotClass="bg-emerald-500"
            />
            <StatPill
              label={t("pos.overview.stat.guests")}
              value={String(totalGuests)}
              dotClass="bg-sky-500"
              icon={<UsersIcon className="size-3" />}
            />
            <StatPill
              label={t("pos.overview.stat.seats")}
              value={`${stats.occupiedSeats} / ${stats.totalSeats}`}
              dotClass="bg-amber-500"
              icon={<Armchair className="size-3" />}
            />
          </div>
        </div>

        {showZoneTabs && (
          <div className="mt-4 flex items-center gap-1.5 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
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
      </div>

      {/* Scrollable grid */}
      <div className="flex-1 overflow-y-auto px-3 py-3 pb-24 sm:px-6 sm:py-5 sm:pb-5">
        <ul className="grid grid-cols-[repeat(auto-fill,minmax(140px,1fr))] gap-2 sm:grid-cols-[repeat(auto-fill,minmax(180px,1fr))] sm:gap-4">
            {/* Takeaway tile — takeaway orders have no table, so this is their
                entry point (like the reference "Mang về" card). Always the
                first cell so it's reachable regardless of zone / table count. */}
            {onOpenTakeaway && (
              <li>
                <button
                  type="button"
                  onClick={onOpenTakeaway}
                  aria-label={t("pos.overview.takeaway")}
                  className={cn(
                    "group relative flex aspect-[4/3] w-full flex-col items-stretch overflow-hidden rounded-xl border-2 border-primary/40 bg-primary/5 p-3 text-left transition-all duration-150 sm:rounded-2xl sm:p-4",
                    "cursor-pointer hover:-translate-y-1 hover:border-primary hover:shadow-lg",
                    "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                  )}
                >
                  <ShoppingBagIcon
                    aria-hidden
                    className="pointer-events-none absolute -bottom-3 -right-3 size-24 text-primary/10 transition-opacity"
                    strokeWidth={1.25}
                  />
                  <div className="relative z-10 mb-2 flex items-center justify-between gap-2">
                    <span className="rounded-full bg-primary/15 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-primary">
                      {t("pos.overview.takeaway")}
                    </span>
                    {takeawayCount > 0 && (
                      <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-bold tabular-nums text-primary-foreground">
                        {takeawayCount}
                      </span>
                    )}
                  </div>
                  <div className="relative z-10 flex flex-1 flex-col justify-center">
                    <ShoppingBagIcon className="mb-1 size-7 text-primary sm:size-8" />
                    <div className="truncate text-lg font-extrabold leading-tight tracking-tight text-foreground sm:text-xl">
                      {t("pos.overview.takeaway")}
                    </div>
                    <div className="mt-0.5 text-[10px] font-medium text-muted-foreground sm:text-[11px]">
                      {t("pos.takeaway.count", { count: takeawayCount })}
                    </div>
                  </div>
                  <div className="relative z-10 mt-2 flex items-center justify-center gap-1 rounded-md border border-dashed border-primary/40 bg-card/50 px-2 py-1.5 text-[11px] font-medium text-primary transition-colors group-hover:border-primary group-hover:bg-primary/10">
                    <span>{t("pos.takeaway.view_cta")}</span>
                    <ChevronRightIcon className="size-3" />
                  </div>
                </button>
              </li>
            )}
            {visibleTables.map((tb) => {
              const meta = TABLE_STATUS_META[tb.status];
              const tint = STATUS_TINT[tb.status];
              const order = tb.current_order_id ? orderById.get(tb.current_order_id) : undefined;
              const isOccupied = !!tb.current_order_id || tb.status === "occupied";
              // #2524 — `disabled` và `onClick` đọc CÙNG một hàm. Hai biểu thức
              // viết rời là cách chúng lệch nhau: ô bàn ma (occupied mà không
              // có đơn) từng bị disable trong khi vẫn còn việc để làm.
              const action = tableTileAction(tb, order?.id);
              // #3009 — tên cũ là `isGhostOccupied` ("bàn ma"). Đổi vì cái tên
              // đó SAI và nó gây hại thật: trạng thái này KHÔNG phải rác, nó là
              // khách đã quét QR và ngồi xuống mà chưa gọi món
              // (`CustomerTableSessionService` lật `free → occupied` TRƯỚC khi
              // có đơn). Bàn đó đang có người thật — không xếp khách khác vào
              // được. Gọi nó là "ma" làm người đọc tưởng phải diệt trạng thái,
              // nên không ai đi sửa ba thứ thật sự sai: nhãn, phép đếm, và cửa
              // sổ 4 giờ của reaper.
              const isSeatedNoOrder = action?.kind === "create-order" && tb.status === "occupied";
              const isClickable = action !== null;
              // Occupied tables show the canonical status label (使用中 / Occupied
              // / Đang phục vụ) so the overview matches the shop exactly.
              // Ba nhãn cho ba thứ khác nhau. Trước #3009 bàn-có-đơn và
              // bàn-chưa-gọi-món dùng CHUNG nhãn "Đang phục vụ", nên trên màn
              // hình chúng không phân biệt được — 本郷店 đọc ra thành "đơn của
              // A-8 hiện sang C-1". Màn chọn bàn (`table-picker.tsx`) đã phân
              // biệt đúng từ trước; đây là chỗ còn gộp.
              const statusText = isSeatedNoOrder
                ? t("pos.overview.status.seated_no_order")
                : isOccupied
                  ? TABLE_STATUS_META.occupied.label
                  : meta.label;

              // The status menu is for tables with NO active order — a served
              // table's status is order-driven (close it first). History is
              // offered on EVERY table, so the `…` menu shows whenever either
              // action is available.
              const canChangeStatus =
                !!onChangeStatus &&
                !tb.current_order_id &&
                tb.is_active !== false;
              const showMenu = !!onViewHistory || canChangeStatus;

              return (
                <li key={tb.id} className="relative">
                  {showMenu && (
                    <div className="absolute right-1.5 top-1.5 z-20">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <button
                            type="button"
                            aria-label={t("pos.overview.table_actions")}
                            onClick={(e) => e.stopPropagation()}
                            className="flex size-7 items-center justify-center rounded-full bg-background/80 text-muted-foreground shadow-sm ring-1 ring-border/60 backdrop-blur transition-colors hover:bg-background hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                          >
                            <MoreVerticalIcon className="size-4" />
                          </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                          align="end"
                          className="w-52 rounded-xl p-1 shadow-lg"
                        >
                          {onViewHistory && (
                            <DropdownMenuItem
                              className="gap-2.5 rounded-lg px-2 py-2"
                              onSelect={() => onViewHistory(tb.id)}
                            >
                              <HistoryIcon className="size-4 shrink-0 text-muted-foreground" />
                              <span className="flex-1 font-medium">
                                {t("pos.overview.view_history")}
                              </span>
                            </DropdownMenuItem>
                          )}
                          {canChangeStatus && (
                            <>
                              {onViewHistory && (
                                <DropdownMenuSeparator className="mx-1 my-1" />
                              )}
                              <DropdownMenuLabel className="px-2 pb-1 pt-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/80">
                                {t("pos.overview.change_status")}
                              </DropdownMenuLabel>
                              {ALL_STATUSES.map((s) => {
                                const isCurrent = s === tb.status;
                                return (
                                  <DropdownMenuItem
                                    key={s}
                                    className={cn(
                                      "gap-2.5 rounded-lg px-2 py-2",
                                      isCurrent && "bg-muted font-semibold",
                                    )}
                                    onSelect={() => {
                                      if (!isCurrent) onChangeStatus?.(tb.id, s);
                                    }}
                                  >
                                    {/* Fixed 16px rail so every dot lines up
                                        with the history icon above. */}
                                    <span className="flex size-4 shrink-0 items-center justify-center">
                                      <span
                                        className={cn(
                                          "size-2.5 rounded-full",
                                          STATUS_DOT[s],
                                        )}
                                        aria-hidden
                                      />
                                    </span>
                                    <span className="flex-1">
                                      {TABLE_STATUS_META[s].label}
                                    </span>
                                    {isCurrent && (
                                      <CheckIcon className="size-4 shrink-0 text-primary" />
                                    )}
                                  </DropdownMenuItem>
                                );
                              })}
                            </>
                          )}
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  )}
                  <button
                    type="button"
                    disabled={!isClickable}
                    onClick={() => {
                      if (action?.kind === "open-order") onOpenOrder(action.orderId);
                      else if (action?.kind === "create-order") onCreateOrder(tb.id);
                    }}
                    className={cn(
                      "group relative flex aspect-[4/3] w-full flex-col items-stretch overflow-hidden rounded-xl border-2 p-3 text-left transition-all duration-150 sm:rounded-2xl sm:p-4",
                      "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                      "disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:translate-y-0 disabled:hover:shadow-none",
                      tint,
                      isOccupied
                        ? "cursor-pointer border-primary/40 shadow-sm hover:-translate-y-1 hover:border-primary hover:shadow-lg"
                        : tb.status === "free"
                          ? "cursor-pointer border-border/60 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                          : "border-border/60 cursor-not-allowed opacity-60",
                    )}
                  >
                    {/* Watermark armchair — very faint kanso accent */}
                    <Armchair
                      aria-hidden
                      className={cn(
                        "pointer-events-none absolute -bottom-3 -right-3 size-24 transition-opacity",
                        isOccupied
                          ? "text-primary/8"
                          : "text-muted-foreground/5 group-hover:text-primary/8",
                      )}
                      strokeWidth={1.25}
                    />

                    {/* Top row: status pill + chevron-on-hover */}
                    <div className="relative z-10 mb-2 flex items-center justify-between gap-2">
                      <span
                        className={cn(
                          "rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider",
                          meta.badge,
                        )}
                      >
                        {statusText}
                      </span>
                      {isOccupied && order && (
                        <ChevronRightIcon className="size-4 text-primary opacity-0 transition-opacity group-hover:opacity-100" />
                      )}
                    </div>

                    {/* Center: Table name big */}
                    <div className="relative z-10 flex flex-1 flex-col justify-center">
                      <div
                        title={tb.name ?? tb.code}
                        className={cn(
                          "min-w-0 truncate font-extrabold leading-none tracking-tight text-foreground",
                          tableNameSizeClass(tb.name ?? tb.code),
                        )}
                      >
                        {tb.name ?? tb.code}
                      </div>
                      {/* #3210 — bàn CÓ khách hiện TIỀN, bàn trống hiện số ghế.
                          Yêu cầu gốc là "bỏ số ghế, thêm tổng đơn khách đã gọi".
                          Bỏ hẳn số ghế thì bàn TRỐNG mất đúng thông tin nó cần —
                          xếp mấy người ngồi được. Số ghế vô dụng khi khách đã
                          ngồi, chứ không vô dụng lúc chọn chỗ; nên hai trạng
                          thái trả lời hai câu khác nhau.

                          Dùng `total_amount` (đã gồm thuế), khớp con số màn
                          thanh toán của chính đơn đó hiện — hai chỗ lệch nhau
                          thì thu ngân không biết tin cái nào. */}
                      {isOccupied && order ? (
                        <div className="mt-1.5 flex items-center gap-1 text-[11px] font-semibold tabular-nums text-foreground sm:text-xs">
                          {formatCurrency(order.total_amount)}
                        </div>
                      ) : (
                        <div className="mt-1.5 flex items-center gap-1 text-[10px] font-medium text-muted-foreground sm:text-[11px]">
                          <UsersIcon className="size-3" />
                          <span className="tabular-nums">{tb.seat_count}</span>
                          <span>{t("pos.table_picker.seat_unit")}</span>
                        </div>
                      )}
                    </div>

                    {/* Bottom row: order_code chip OR free hint */}
                    <div className="relative z-10 mt-2">
                      {isOccupied && order ? (
                        <div className="flex items-center gap-1.5 rounded-md border border-primary/30 bg-primary/10 px-2 py-1.5 shadow-sm">
                          <ReceiptIcon className="size-3.5 shrink-0 text-primary" />
                          <span className="truncate font-mono text-[11px] font-bold text-primary">
                            {order.order_code}
                          </span>
                          {typeof order.guest_count === "number" && (
                            <span className="ml-auto flex shrink-0 items-center gap-0.5 text-[10px] text-primary/70">
                              <UsersIcon className="size-2.5" />
                              <span className="tabular-nums">{order.guest_count}</span>
                            </span>
                          )}
                        </div>
                      ) : isSeatedNoOrder ? (
                        // #2524 — cùng lời mời như bàn trống: ô này bấm được và
                        // bấm vào là mở đơn mới.
                        //
                        // #3009 sửa vế nhãn. Bản trước ghi ở đây rằng nhãn phía
                        // trên "vẫn là đang phục vụ vì đó là trạng thái THẬT
                        // trong DB". Trạng thái DB đúng là `occupied`, nhưng
                        // "Đang phục vụ" KHÔNG phải bản dịch trung thực của nó
                        // — nó là bản dịch của `occupied + có đơn`. Nhãn mới
                        // ("khách đã ngồi, chưa gọi món") nói ĐÚNG HƠN chứ
                        // không giấu bớt: nhân viên vẫn thấy bàn có người, và
                        // thấy thêm điều mà bản cũ nuốt mất — rằng chưa có đơn.
                        <div className="flex items-center justify-center gap-1 rounded-md border border-dashed border-primary/40 bg-card/50 px-2 py-1.5 text-[11px] font-medium text-primary transition-colors group-hover:border-primary">
                          <span>{t("pos.overview.create_order_cta")}</span>
                          <ArrowRightIcon className="size-3" />
                        </div>
                      ) : isOccupied ? (
                        <div className="rounded-md border border-dashed border-muted-foreground/30 bg-muted/40 px-2 py-1.5 text-center text-[11px] text-muted-foreground">
                          {t("pos.overview.has_order")}
                        </div>
                      ) : tb.status === "free" ? (
                        <div className="flex items-center justify-center gap-1 rounded-md border border-dashed border-border bg-card/50 px-2 py-1.5 text-[11px] font-medium text-muted-foreground transition-colors group-hover:border-primary/40 group-hover:text-primary">
                          <span>{t("pos.overview.create_order_cta")}</span>
                          <ArrowRightIcon className="size-3" />
                        </div>
                      ) : (
                        <div className="rounded-md bg-muted px-2 py-1.5 text-center text-[10px] uppercase tracking-wide text-muted-foreground">
                          {meta.label}
                        </div>
                      )}
                    </div>
                  </button>
                </li>
              );
            })}
          </ul>
          {visibleTables.length === 0 && (
            <div className="mt-4 flex flex-col items-center justify-center gap-2 px-6 py-10 text-center">
              <Armchair className="size-10 text-muted-foreground/30" />
              <div className="text-sm text-muted-foreground">
                {t("pos.table_picker.no_tables")}
              </div>
            </div>
          )}
      </div>
    </div>
  );
}

interface StatPillProps {
  label: string;
  value: string;
  dotClass: string;
  icon?: React.ReactNode;
}

function StatPill({ label, value, dotClass, icon }: StatPillProps) {
  return (
    <div className="flex min-w-0 items-center gap-1.5 rounded-full border border-border/60 bg-background px-2.5 py-1 shadow-sm sm:gap-2 sm:px-3 sm:py-1.5">
      {icon ?? <span className={cn("size-2 shrink-0 rounded-full", dotClass)} aria-hidden />}
      <span className="min-w-0 truncate text-[11px] font-medium text-muted-foreground">{label}</span>
      <span className="ml-auto shrink-0 text-xs font-bold tabular-nums text-foreground sm:ml-0 sm:text-sm">{value}</span>
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
        "flex shrink-0 cursor-pointer items-center gap-1.5 rounded-full border-2 px-4 py-1.5 text-xs transition-all",
        "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
        active
          ? "border-primary bg-primary text-primary-foreground shadow-sm"
          : "border-border/60 bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground",
      )}
    >
      <span className="font-semibold">{label}</span>
      <span
        className={cn(
          "inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-bold tabular-nums",
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
