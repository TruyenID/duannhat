/**
 * DebtSearchDialog — "Tra cứu nợ".
 *
 * ## Why this is not in the order sidebar any more
 *
 * "Who owes us money" is a SHOP-WIDE question. It used to be a button inside
 * OrderCart, which early-returns when there is no order — so answering it began
 * with creating an order for a customer who might not even be the debtor. The
 * trigger now lives in the POS header and this dialog opens with nothing else
 * on screen.
 *
 * ## Two steps, because one is not enough to collect
 *
 * `GET /pos/debts` groups by customer. That answers "who owes and how much",
 * which is all a manager needs and precisely not enough to collect: settling
 * posts `metadata.settles_payment_id`, and that id exists nowhere in an
 * aggregate. Step 2 reads `GET /pos/debts/{customer}` for the individual rows.
 *
 * ## Collecting needs an OPEN order, and that is a backend rule
 *
 * A settlement is an ordinary payment carrying `settles_payment_id`, and
 * `OrderPaymentService` refuses any payment on an order that is not `checkout`
 * or `paying` — a guard that lives inside the per-order lock and the till-session
 * attribution block, so it is not something the POS may route around. A debt's
 * OWN order is always closed (the debt was recorded as it closed), so the
 * settlement has to land on a live order for the SAME customer — which is what
 * the backend's `settles_wrong_customer` check permits and requires.
 *
 * So: when the POS has an active order for that debtor, this dialog collects.
 * When it does not, it says so and says what to do, rather than offering a
 * button that 409s.
 */

import { useMemo, useState } from "react";
import {
  Button,
  Dialog,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  Input,
} from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import {
  AlertCircleIcon,
  ArrowLeftIcon,
  ChevronRightIcon,
  SearchIcon,
} from "lucide-react";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";
import { formatCurrency } from "../lib/totals";
import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";
import {
  useDebtCustomers,
  useCustomerDebts,
  usePartPaidOrders,
} from "@/hooks/api/use-debts";
import type {
  DebtCustomerRow,
  DebtDetailRow,
  PartPaidCustomerRow,
} from "@/services/debt-service";
import type { EffectivePaymentOption } from "../types";

/** Re-exported so existing importers of the old shape keep compiling. */
export type DebtRow = DebtCustomerRow;

export interface DebtSettlementRequest {
  debt: DebtDetailRow;
  /** The live order the settlement will be posted against. */
  orderId: string;
  option: EffectivePaymentOption;
  /** Only sent when the method requires it; equals `amount` for an exact tender. */
  tenderedAmount?: number;
}

export interface DebtSearchDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /**
   * Passed in rather than read off the URL. This dialog is triggered from the
   * header, which is mounted on screens whose path shape differs, and a regex
   * over `window.location` would be a second, silently-drifting source for
   * something the page already holds.
   */
  shopSlug: string;
  /**
   * The order currently open in the POS, if any. Settlement is only offered
   * when this belongs to the debtor being viewed — see the header comment.
   */
  activeOrder?: { id: string; customerId: string | null } | null;
  /** Checkout-capable methods, already filtered by the page. */
  paymentOptions?: EffectivePaymentOption[];
  /** Posts the settlement. Resolves on success; the dialog refetches after. */
  onSettle?: (req: DebtSettlementRequest) => Promise<void>;
}

export function DebtSearchDialog({
  open,
  onOpenChange,
  ...rest
}: DebtSearchDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[90vh] w-[min(96vw,720px)] flex-col gap-0 p-0">
        {/* Body mounted only while open, so step/search/in-flight state resets
            by UNMOUNTING rather than by an effect that writes state on render.
            The effect version is the shape that wiped a cashier's picks every
            time a background poll re-rendered the page. */}
        {open && <DebtSearchBody {...rest} />}
      </DialogContent>
    </Dialog>
  );
}

function DebtSearchBody({
  shopSlug,
  activeOrder,
  paymentOptions = [],
  onSettle,
}: Omit<DebtSearchDialogProps, "open" | "onOpenChange">) {
  const { t } = useTranslation();
  const [query, setQuery] = useState("");
  const [selected, setSelected] = useState<DebtCustomerRow | null>(null);
  const [settlingId, setSettlingId] = useState<string | null>(null);

  const customersQuery = useDebtCustomers(shopSlug, true);
  const detailQuery = useCustomerDebts(shopSlug, selected?.customer_id ?? null, true);
  // Orders nobody finished paying. A SEPARATE list, never folded into the
  // figures above: an on-account debt was granted on purpose and is collectible
  // on its own terms, while a part-paid order is one nobody closed. One merged
  // number could not tell the two apart, and the shop needs to.
  const partPaidQuery = usePartPaidOrders(shopSlug, true);

  // Memoised so `filtered` below does not recompute on every render — the list
  // is re-derived on each keystroke otherwise.
  const rows = useMemo(() => customersQuery.data ?? [], [customersQuery.data]);
  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return rows;
    const digits = (s: string) => s.replace(/[\s-]/g, "");
    return rows.filter(
      (r) =>
        (r.customer_name ?? "").toLowerCase().includes(q) ||
        // Phone matters most: POS creates customers by phone, so it is often
        // the only populated field. Strip separators so "0987 654 312" and
        // "0987-654-312" both match a stored "0987654312".
        digits(r.customer_phone ?? "").includes(digits(q)) ||
        (r.customer_tax_code ?? "").includes(q),
    );
  }, [query, rows]);

  const debts = detailQuery.data ?? [];
  const partPaidAll = useMemo(
    () => partPaidQuery.data ?? [],
    [partPaidQuery.data],
  );
  const partPaid = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return partPaidAll;
    const digits = (v: string) => v.replace(/[\s-]/g, "");
    return partPaidAll.filter(
      (r) =>
        (r.customer_name ?? "").toLowerCase().includes(q) ||
        digits(r.customer_phone ?? "").includes(digits(q)) ||
        (r.customer_tax_code ?? "").includes(q),
    );
  }, [query, partPaidAll]);
  const selectedPartPaid = selected
    ? (partPaidAll.find((r) => r.customer_id === selected.customer_id) ?? null)
    : null;

  // The settlement target. Same customer is the backend's rule, not a nicety:
  // `settles_wrong_customer` rejects anything else.
  const settlementOrderId =
    selected &&
    activeOrder &&
    activeOrder.customerId &&
    activeOrder.customerId === selected.customer_id
      ? activeOrder.id
      : null;

  const method = paymentOptions[0];

  async function settle(debt: DebtDetailRow) {
    if (!settlementOrderId || !method || !onSettle) return;
    setSettlingId(debt.payment_id);
    try {
      await onSettle({
        debt,
        orderId: settlementOrderId,
        option: method,
        // Exact tender: the amount is fixed by the debt and the backend rejects
        // anything else, so there is no change to make and nothing to ask for.
        tenderedAmount: method.client?.requires_tendered
          ? Number(debt.amount)
          : undefined,
      });
      await Promise.all([customersQuery.refetch(), detailQuery.refetch()]);
      toast.success(t("pos.debt.settled"));
    } catch (err) {
      toast.error(
        err instanceof ApiError && err.message
          ? err.message
          : t("pos.debt.settle_failed"),
      );
    } finally {
      setSettlingId(null);
    }
  }

  const loading = selected ? detailQuery.isLoading : customersQuery.isLoading;
  const failed = selected ? detailQuery.isError : customersQuery.isError;

  return (
    <>
        <DialogHeader className="shrink-0 px-6 pt-6 pb-3">
          <DialogTitle className="flex items-center gap-2">
            {selected && (
              <button
                type="button"
                onClick={() => setSelected(null)}
                aria-label={t("common.back")}
                className="hover:bg-muted -ml-1 flex size-7 cursor-pointer items-center justify-center rounded-md"
              >
                <ArrowLeftIcon className="size-4" />
              </button>
            )}
            {selected
              ? (selected.customer_name ?? selected.customer_phone ?? "—")
              : t("pos.debt.lookup")}
            <HelpButton topic="debt-search" className="size-7" />
          </DialogTitle>
          <DialogDescription className="text-xs">
            {selected
              ? t("pos.debt.detail_hint")
              : t("pos.debt.lookup_hint")}
          </DialogDescription>
        </DialogHeader>

        {!selected && (
          <div className="border-b px-6 pb-3">
            <div className="relative">
              <SearchIcon className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
              <Input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder={t("pos.debt.search_placeholder")}
                className="pl-9"
              />
            </div>
          </div>
        )}

        <div className="flex-1 overflow-y-auto px-6 py-4">
          {loading ? (
            <div className="text-muted-foreground flex items-center justify-center py-8">
              <Spinner className="mr-2 size-4" />
              {t("common.loading")}
            </div>
          ) : failed ? (
            <div className="text-muted-foreground py-8 text-center text-sm">
              {t("pos.debt.load_failed")}
            </div>
          ) : selected ? (
            <>
              <CustomerDebts
                debts={debts}
                settlementOrderId={settlementOrderId}
                hasMethod={!!method && !!onSettle}
                settlingId={settlingId}
                onSettle={settle}
              />
              <CustomerPartPaid row={selectedPartPaid} />
            </>
          ) : filtered.length === 0 && partPaid.length === 0 ? (
            <div className="text-muted-foreground py-8 text-center text-sm">
              {rows.length === 0 && partPaidAll.length === 0
                ? t("pos.debt.empty")
                : t("pos.debt.no_match")}
            </div>
          ) : (
            <div className="space-y-5">
              {/* Two sections, never one merged figure. Money charged to an
                  account on purpose and money on an order nobody closed are
                  different obligations, and a single total would hide which is
                  which. */}
              {filtered.length > 0 && (
                <section>
                  <h3 className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">
                    {t("pos.debt.section_on_account")}
                  </h3>
            <ul className="space-y-2">
              {filtered.map((row) => (
                <li key={row.customer_id}>
                  <button
                    type="button"
                    onClick={() => setSelected(row)}
                    className="bg-card hover:border-primary/40 flex w-full cursor-pointer items-center gap-3 rounded-lg border px-4 py-3 text-left transition-colors"
                  >
                    <div className="min-w-0 flex-1">
                      {/* Phone leads when there's no name — a POS-created debtor
                          has only a phone, so falling back to a bare "—" left
                          the cashier with nothing to identify the row by. */}
                      <div className="truncate font-semibold">
                        {row.customer_name ?? row.customer_phone ?? "—"}
                      </div>
                      <div className="text-muted-foreground text-xs">
                        {[
                          row.customer_name && row.customer_phone
                            ? row.customer_phone
                            : null,
                          row.customer_tax_code
                            ? `MST: ${row.customer_tax_code}`
                            : null,
                          t("pos.debt.open_count", {
                            count: row.open_debt_count,
                          }),
                        ]
                          .filter(Boolean)
                          .join(" · ")}
                      </div>
                    </div>
                    <div className="shrink-0 text-right font-bold tabular-nums text-red-600">
                      {formatCurrency(Number(row.open_debt_total))}
                    </div>
                    <ChevronRightIcon className="text-muted-foreground/50 size-4 shrink-0" />
                  </button>
                </li>
              ))}
            </ul>
                </section>
              )}

              {partPaid.length > 0 && (
                <section>
                  <h3 className="text-muted-foreground mb-1 text-xs font-semibold tracking-wide uppercase">
                    {t("pos.debt.section_part_paid")}
                  </h3>
                  {/* Said once, at the top: these are not debts, and an order
                      being served RIGHT NOW looks identical to the query. The
                      timestamps on each row are what let a cashier tell them
                      apart — a cutoff on the server could not. */}
                  <p className="text-muted-foreground mb-2 text-[11px]">
                    {t("pos.debt.section_part_paid_hint")}
                  </p>
                  <ul className="space-y-2">
                    {partPaid.map((row) => (
                      <li key={row.customer_id}>
                        <button
                          type="button"
                          onClick={() =>
                            setSelected({
                              customer_id: row.customer_id,
                              customer_name: row.customer_name,
                              customer_phone: row.customer_phone,
                              customer_tax_code: row.customer_tax_code,
                              open_debt_count: 0,
                              open_debt_total: "0",
                              oldest_debt_at: row.oldest_at,
                              latest_debt_at: row.latest_at,
                            })
                          }
                          className="bg-card hover:border-primary/40 flex w-full cursor-pointer items-center gap-3 rounded-lg border px-4 py-3 text-left transition-colors"
                        >
                          <div className="min-w-0 flex-1">
                            <div className="truncate font-semibold">
                              {row.customer_name ?? row.customer_phone ?? "—"}
                            </div>
                            <div className="text-muted-foreground text-xs">
                              {[
                                row.customer_name && row.customer_phone
                                  ? row.customer_phone
                                  : null,
                                t("pos.debt.part_paid_count", {
                                  count: row.order_count,
                                }),
                              ]
                                .filter(Boolean)
                                .join(" · ")}
                            </div>
                          </div>
                          <div className="shrink-0 text-right font-bold tabular-nums text-amber-700 dark:text-amber-400">
                            {formatCurrency(Number(row.total_unpaid))}
                          </div>
                          <ChevronRightIcon className="text-muted-foreground/50 size-4 shrink-0" />
                        </button>
                      </li>
                    ))}
                  </ul>
                </section>
              )}
            </div>
          )}
        </div>
    </>
  );
}

function CustomerDebts({
  debts,
  settlementOrderId,
  hasMethod,
  settlingId,
  onSettle,
}: {
  debts: DebtDetailRow[];
  settlementOrderId: string | null;
  hasMethod: boolean;
  settlingId: string | null;
  onSettle: (debt: DebtDetailRow) => void;
}) {
  const { t } = useTranslation();

  if (debts.length === 0) {
    return (
      <div className="text-muted-foreground py-8 text-center text-sm">
        {t("pos.debt.customer_clear")}
      </div>
    );
  }

  const canCollect = !!settlementOrderId && hasMethod;

  return (
    <div className="space-y-3">
      {/* Why the buttons are inert, stated ONCE at the top rather than repeated
          as a disabled tooltip per row. A settlement is a payment, and a payment
          needs a live order — the debt's own order closed the moment the debt
          was recorded. */}
      {!canCollect && (
        <div className="text-muted-foreground flex items-start gap-2 rounded-lg border border-dashed px-3 py-2 text-xs">
          <AlertCircleIcon className="mt-0.5 size-3.5 shrink-0" />
          <span>
            {settlementOrderId
              ? t("pos.debt.no_method")
              : t("pos.debt.needs_open_order")}
          </span>
        </div>
      )}

      <ul className="space-y-2">
        {debts.map((debt) => {
          const settling = settlingId === debt.payment_id;
          const blocked = !debt.is_settleable;
          return (
            <li
              key={debt.payment_id}
              className="bg-card flex items-center gap-3 rounded-lg border px-4 py-3"
            >
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-semibold">
                  {debt.order_code ?? "—"}
                </div>
                <div className="text-muted-foreground text-xs tabular-nums">
                  {debt.created_at}
                </div>
                {blocked && (
                  // A partially refunded debt cannot go through the payment
                  // path: paying the net trips `settles_amount_mismatch`, and
                  // paying the original over-collects. Say it here instead of
                  // letting the cashier discover it from a 422.
                  <div className="mt-0.5 text-xs text-amber-700 dark:text-amber-400">
                    {t("pos.debt.partially_refunded")}
                  </div>
                )}
              </div>
              <div
                className={cn(
                  "shrink-0 text-right font-bold tabular-nums",
                  blocked ? "text-muted-foreground" : "text-red-600",
                )}
              >
                {formatCurrency(Number(debt.net_amount))}
              </div>
              <Button
                size="sm"
                disabled={!canCollect || blocked || settling}
                onClick={() => onSettle(debt)}
              >
                {settling && <Spinner className="mr-1.5 size-3.5" />}
                {t("pos.debt.settle")}
              </Button>
            </li>
          );
        })}
      </ul>
    </div>
  );
}

/**
 * The customer's unfinished orders, listed under their on-account debts.
 *
 * Read-only on purpose. Collecting the rest of an order is the ordinary payment
 * flow on that order — it is not a settlement, carries no
 * `settles_payment_id`, and routing it through this dialog would invent a
 * second way to take the same money.
 */
function CustomerPartPaid({ row }: { row: PartPaidCustomerRow | null }) {
  const { t } = useTranslation();
  if (!row || row.orders.length === 0) return null;

  return (
    <section className="mt-5">
      <h3 className="text-muted-foreground mb-1 text-xs font-semibold tracking-wide uppercase">
        {t("pos.debt.section_part_paid")}
      </h3>
      <p className="text-muted-foreground mb-2 text-[11px]">
        {t("pos.debt.part_paid_detail_hint")}
      </p>
      <ul className="space-y-2">
        {row.orders.map((o) => (
          <li
            key={o.order_id}
            className="bg-card flex items-center gap-3 rounded-lg border border-dashed px-4 py-3"
          >
            <div className="min-w-0 flex-1">
              <div className="truncate text-sm font-semibold">
                {o.order_code ?? "—"}
              </div>
              <div className="text-muted-foreground text-xs tabular-nums">
                {o.opened_at}
              </div>
            </div>
            <div className="text-muted-foreground shrink-0 text-right text-xs tabular-nums">
              {formatCurrency(Number(o.paid_amount))} /{" "}
              {formatCurrency(Number(o.total_amount))}
            </div>
            <div className="shrink-0 text-right font-bold tabular-nums text-amber-700 dark:text-amber-400">
              {formatCurrency(Number(o.unpaid_amount))}
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}
