import { useCallback, useState } from "react";
import {
  useChangeTable,
  useMergeTable,
  useOrderFieldSave,
  useUnmergeTable,
} from "@/hooks/api/use-orders";
import { useTranslation } from "@/providers/app-provider";
import type { PendingUnmerge } from "../components/order-cart";
import type { CustomerOrder, TableResource } from "../types";

export interface UseTableAssignmentArgs {
  shopSlug: string;
  activeOrder: CustomerOrder | undefined;
  /** Every table in the shop — used to name the two ends of a failed move. */
  tables: TableResource[];
  setErrorMessage: (message: string | null) => void;
  surfaceError: (e: unknown) => void;
}

export interface UseTableAssignmentResult {
  /** Set when a change-table move failed at the unmerge step; drives the cart banner. */
  pendingUnmerge: PendingUnmerge | null;
  clearPendingUnmerge: () => void;
  retryUnmerge: () => Promise<void>;
  assignTables: (picked: TableResource[]) => Promise<void>;
  setGuestCount: (value: number) => Promise<void>;
  mergeTables: (pickedIds: string[]) => Promise<void>;
  unmergeTables: (tableIds: string[]) => Promise<void>;
  changeTable: (fromId: string, toId: string) => ReturnType<ReturnType<typeof useChangeTable>>;
}

/**
 * Which tables (and how many guests) an order is bound to.
 *
 * Owns the four mutations behind it because nothing else on the page uses
 * them, plus the one piece of state they produce: `pendingUnmerge`, the
 * banner shown when a change-table move died halfway through and staff has
 * to decide whether to retry the unmerge.
 */
export function useTableAssignment({
  shopSlug,
  activeOrder,
  tables,
  setErrorMessage,
  surfaceError,
}: UseTableAssignmentArgs): UseTableAssignmentResult {
  const { t } = useTranslation();
  const activeOrderId = activeOrder?.id ?? "";

  const unmergeTable = useUnmergeTable(shopSlug, activeOrderId);
  const mergeTable = useMergeTable(shopSlug, activeOrderId);
  const changeTableMutation = useChangeTable(shopSlug, activeOrderId);
  // fieldSave is null until an order is loaded — handlers guard below.
  const fieldSave = useOrderFieldSave(shopSlug, activeOrder ?? null);

  const [pendingUnmerge, setPendingUnmerge] = useState<PendingUnmerge | null>(null);

  async function assignTables(picked: TableResource[]) {
    if (!fieldSave || picked.length === 0) return;
    setErrorMessage(null);
    try {
      await fieldSave({ table_ids: picked.map((table) => table.id) });
    } catch (e) {
      surfaceError(e);
    }
  }

  async function setGuestCount(value: number) {
    if (!fieldSave) return;
    setErrorMessage(null);
    try {
      await fieldSave({ guest_count: value });
    } catch (e) {
      surfaceError(e);
    }
  }

  async function mergeTables(pickedIds: string[]) {
    if (!activeOrder) return;
    setErrorMessage(null);
    // One merge-table call per picked row. Stop at first failure so
    // partial state is clear (order gets whatever was already merged,
    // staff can retry or unmerge the rest).
    for (const tableId of pickedIds) {
      try {
        await mergeTable.mutateAsync(tableId);
      } catch (e) {
        surfaceError(e);
        return;
      }
    }
  }

  async function unmergeTables(tableIds: string[]) {
    if (!activeOrder) return;
    setErrorMessage(null);
    try {
      // Sequential per-table — backend endpoint takes one id at a time.
      // Failure midway leaves whatever already succeeded, matching the
      // merge flow's semantics so staff can retry the rest.
      for (const id of tableIds) {
        await unmergeTable.mutateAsync(id);
      }
    } catch (e) {
      surfaceError(e);
    }
  }

  async function changeTable(fromId: string, toId: string) {
    setErrorMessage(null);
    const result = await changeTableMutation(fromId, toId);
    if (!result.ok && result.stage === "unmerge") {
      const fromT = tables.find((table) => table.id === fromId);
      const toT = tables.find((table) => table.id === toId);
      setPendingUnmerge({
        fromTableCode: fromT?.name ?? fromT?.code ?? fromId.slice(0, 6),
        toTableCode: toT?.name ?? toT?.code ?? toId.slice(0, 6),
        message:
          (result.error.body?.message as string | undefined) ??
          t("pos.dialog.change_table.error_merge"),
      });
    }
    // Tab label stays as order_code on success — does not flip to the new
    // table code; staff identifies the order by its immutable code.
    return result;
  }

  async function retryUnmerge() {
    if (!pendingUnmerge || !activeOrder) return;
    const fromTable = tables.find(
      (table) =>
        table.name === pendingUnmerge.fromTableCode ||
        table.code === pendingUnmerge.fromTableCode,
    );
    if (!fromTable) return;
    try {
      await unmergeTable.mutateAsync(fromTable.id);
      setPendingUnmerge(null);
    } catch (e) {
      surfaceError(e);
    }
  }

  // Stable identity: the page resets this from its tab-change effect, and an
  // unstable callback there would either lie in the dep array or re-run it.
  const clearPendingUnmerge = useCallback(() => {
    setPendingUnmerge(null);
  }, []);

  return {
    pendingUnmerge,
    clearPendingUnmerge,
    retryUnmerge,
    assignTables,
    setGuestCount,
    mergeTables,
    unmergeTables,
    changeTable,
  };
}
