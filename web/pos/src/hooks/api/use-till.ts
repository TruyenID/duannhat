/**
 * use-till — Plan 030 Cashier Shift hooks.
 *
 * All queries scope by shopSlug so a tablet switching shops gets fresh data.
 * Mutations invalidate `currentKeys` + the affected `sessionKeys` so the
 * /pos gate re-resolves the open-session pointer.
 */

import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationOptions,
} from "@tanstack/react-query";
import { ApiError } from "@/lib/api";
import { useLocale } from "@/providers/app-provider";
import type { PaymentTerminal } from "@/app/shift/tender-terminals";
import {
  tillService,
  type CashEventPayload,
  type PosPaymentTerminalRow,
  type ChainSummary,
  type CloseOrderSummary,
  type CloseShiftPayload,
  type CurrentTill,
  type Denomination,
  type DraftClosePayload,
  type GapPreview,
  type OpenShiftPayload,
  type ReconciliationData,
  type TillSession,
  type TillTenderType,
  type UnresolvedOrdersPreview,
} from "@/services/till-service";

export const tillKeys = {
  all: (shopSlug: string) => ["till", shopSlug] as const,
  current: (shopSlug: string) => ["till", shopSlug, "current"] as const,
  denominations: (shopSlug: string, currency?: string) =>
    ["till", shopSlug, "denominations", currency ?? "default"] as const,
  // Locale-keyed: a tender's `name` is resolved server-side from
  // Accept-Language (the workstation picks the per-locale column out of its
  // mirror; Cloud resolves the translatable attribute through SetLocale), so
  // the same request returns different text per language. Without locale in
  // the key the brand chips stayed in the previous language after a switch —
  // the key never moved, so React Query had no reason to refetch, and
  // staleTime is 5 minutes.
  tenderTypes: (shopSlug: string, locale: string) =>
    ["till", shopSlug, "tender-types", locale] as const,
  // NOT locale-keyed, and that is correct: `till_tender_categories.name` is
  // deliberately non-translatable (one shop-owned name per row — see
  // schemas/Backend/Till/TillTenderCategory.yaml), so this response is
  // identical in every language and a locale key would only fragment the
  // cache.
  tenderCategories: (shopSlug: string) =>
    ["till", shopSlug, "tender-categories"] as const,
  paymentTerminals: (shopSlug: string) =>
    ["till", shopSlug, "payment-terminals"] as const,
  session: (shopSlug: string, id: string) =>
    ["till", shopSlug, "session", id] as const,
  reconciliation: (shopSlug: string, id: string) =>
    ["till", shopSlug, "session", id, "reconciliation"] as const,
  gapPreview: (shopSlug: string) =>
    ["till", shopSlug, "gap-preview"] as const,
  unresolvedOrders: (shopSlug: string) =>
    ["till", shopSlug, "unresolved-orders"] as const,
  orderSummary: (shopSlug: string, id: string) =>
    ["till", shopSlug, "session", id, "order-summary"] as const,
  chain: (shopSlug: string, chainId: string) =>
    ["till", shopSlug, "chain", chainId] as const,
  sessionIndex: (shopSlug: string, from?: string, to?: string) =>
    ["till", shopSlug, "sessions", from ?? "", to ?? ""] as const,
};

export function useTillCurrent(shopSlug: string) {
  return useQuery({
    queryKey: tillKeys.current(shopSlug),
    queryFn: () => tillService.current(),
    select: (r) => r.data,
    enabled: !!shopSlug,
    staleTime: 15 * 1000,
    // Re-validate the shift state whenever a page using this hook mounts.
    // Without this, a shift closed on another terminal / force-abandoned /
    // expired (plan-032) stays "open" in this terminal's 15s cache, letting
    // the operator into the close page and count cash for a dead shift
    // before the submit finally 409s (issue #545). Navigation-triggered
    // refetch closes that window — the endpoint is tiny.
    refetchOnMount: "always",
  });
}

/**
 * #3062 — lịch sử ca của quầy, cho trang in lại phiếu 精算.
 *
 * KHÔNG cache lâu: người mở trang này thường vừa chốt ca xong và đang đi tìm
 * đúng ca đó. Một danh sách cũ 5 phút sẽ thiếu chính cái họ cần.
 */
export function useTillSessionHistory(
  shopSlug: string,
  range?: { from?: string; to?: string },
) {
  return useQuery({
    queryKey: tillKeys.sessionIndex(shopSlug, range?.from, range?.to),
    queryFn: () =>
      tillService.sessionIndex({
        businessDateFrom: range?.from,
        businessDateTo: range?.to,
      }),
    select: (r) => r.data,
    enabled: !!shopSlug,
    staleTime: 5 * 1000,
    refetchOnMount: "always",
  });
}

export function useDenominations(shopSlug: string, currency?: string) {
  return useQuery({
    queryKey: tillKeys.denominations(shopSlug, currency),
    queryFn: () => tillService.denominations(currency),
    select: (r) => r.data,
    enabled: !!shopSlug,
    // Denominations are admin-edited from shop Settings; without invalidation
    // wiring (no WS event yet), keep this fresh-on-mount so a cashier landing
    // on /shift/open right after shop UI added a value sees it immediately.
    // The list is tiny (<20 rows) — the extra fetch is negligible.
    staleTime: 0,
  });
}

export function useTenderTypes(shopSlug: string) {
  const { locale } = useLocale();
  return useQuery({
    queryKey: tillKeys.tenderTypes(shopSlug, locale),
    queryFn: () => tillService.tenderTypes(),
    select: (r) => r.data,
    enabled: !!shopSlug,
    staleTime: 5 * 60 * 1000,
    // Keep the previous language's chips on screen while the locale-triggered
    // refetch is in flight, so names swap in place instead of the brand picker
    // collapsing to empty mid-checkout.
    placeholderData: keepPreviousData,
  });
}

/**
 * Categories drive which reconciliation cards render on the close screen
 * + their label/order. Cached at the same staleness as tender-types so
 * the two stay in sync when staff bounces from settings → close.
 */
export function useTenderCategories(shopSlug: string) {
  return useQuery({
    queryKey: tillKeys.tenderCategories(shopSlug),
    queryFn: () => tillService.tenderCategories(),
    select: (r) => r.data,
    enabled: !!shopSlug,
    staleTime: 5 * 60 * 1000,
  });
}

/**
 * #1156 — registered payment terminals + their `accepts`, feeding the payment
 * dialog's brand sub-choice and the close page's per-terminal sections.
 *
 * The POS endpoint (`GET /pos/till/payment-terminals`) is a backend follow-up
 * — accepts data currently lives behind Platform SSO only (see the note on
 * PosPaymentTerminalRow). Any ApiError therefore resolves to an EMPTY list
 * (never throws, never retries): the payment dialog then offers the full
 * effective tender list and the close page renders the single generic
 * section, i.e. exactly the pre-#1156 behaviour.
 */
export function usePaymentTerminals(shopSlug: string) {
  return useQuery({
    queryKey: tillKeys.paymentTerminals(shopSlug),
    queryFn: async (): Promise<{ data: PosPaymentTerminalRow[] }> => {
      try {
        return await tillService.paymentTerminals();
      } catch (e) {
        if (e instanceof ApiError) return { data: [] };
        throw e;
      }
    },
    select: (r): PaymentTerminal[] =>
      r.data
        .filter((d) => d.is_active !== false)
        .map((d) => ({
          id: d.id,
          name: d.name,
          accepts: Array.isArray(d.metadata?.accepts) ? d.metadata.accepts : [],
        }))
        .filter((d) => d.accepts.length > 0),
    enabled: !!shopSlug,
    staleTime: 5 * 60 * 1000,
    retry: false,
  });
}

export function useTillSession(shopSlug: string, id: string | null | undefined) {
  return useQuery({
    queryKey: tillKeys.session(shopSlug, id ?? ""),
    queryFn: () => tillService.sessionShow(id as string),
    select: (r) => r.data,
    enabled: !!shopSlug && !!id,
  });
}

export function useReconciliation(
  shopSlug: string,
  id: string | null | undefined,
) {
  return useQuery({
    queryKey: tillKeys.reconciliation(shopSlug, id ?? ""),
    queryFn: () => tillService.reconciliation(id as string),
    select: (r) => r.data,
    enabled: !!shopSlug && !!id,
    staleTime: 15 * 1000,
  });
}

/**
 * plan-044 R2 — NULL-attributed payments taken during the previous shift's
 * close-gap, shown on the open screen so the cashier confirms which belong to
 * the new shift. Fresh-on-mount (staleTime 0): the gap is computed against
 * "now", and non-blocking — a fetch error must not stop the cashier opening a
 * shift (the panel renders empty, the claim is simply skipped).
 */
export function useGapPreview(shopSlug: string, enabled = true) {
  return useQuery<{ data: GapPreview }, Error, GapPreview>({
    queryKey: tillKeys.gapPreview(shopSlug),
    queryFn: () => tillService.gapPreview(),
    select: (r) => r.data,
    enabled: !!shopSlug && enabled,
    staleTime: 0,
    retry: false,
  });
}

/**
 * #2696 — orders still paying/checkout that lived past the previous shift's
 * close, shown on the open screen as a SEPARATE block from gap-preview.
 * Gap = money already taken, unattributed. This = an order that may still
 * be unpaid. Fresh-on-mount, non-blocking: a fetch error must not stop the
 * cashier opening a shift (the panel renders nothing).
 */
export function useUnresolvedOrders(shopSlug: string, enabled = true) {
  return useQuery<
    { data: UnresolvedOrdersPreview },
    Error,
    UnresolvedOrdersPreview
  >({
    queryKey: tillKeys.unresolvedOrders(shopSlug),
    queryFn: () => tillService.unresolvedOrders(),
    select: (r) => r.data,
    enabled: !!shopSlug && enabled,
    staleTime: 0,
    retry: false,
  });
}

/**
 * plan-044 R2 — paid vs unpaid-carry order summary for the close screen. Paid
 * orders settle into THIS shift; unpaid orders carry naturally to the next.
 */
export function useCloseOrderSummary(
  shopSlug: string,
  id: string | null | undefined,
) {
  return useQuery<{ data: CloseOrderSummary }, Error, CloseOrderSummary>({
    queryKey: tillKeys.orderSummary(shopSlug, id ?? ""),
    queryFn: () => tillService.orderSummary(id as string),
    select: (r) => r.data,
    enabled: !!shopSlug && !!id,
    staleTime: 15 * 1000,
  });
}

export function useOpenShift(
  shopSlug: string,
  options?: UseMutationOptions<{ data: TillSession }, Error, OpenShiftPayload>,
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: OpenShiftPayload) => tillService.open(body),
    ...options,
    onSuccess: (data, vars, onMutateResult, ctx) => {
      qc.invalidateQueries({ queryKey: tillKeys.all(shopSlug) });
      options?.onSuccess?.(data, vars, onMutateResult, ctx);
    },
  });
}

export function useCloseShift(
  shopSlug: string,
  sessionId: string,
  options?: UseMutationOptions<{ data: TillSession }, Error, CloseShiftPayload>,
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: CloseShiftPayload) =>
      tillService.close(sessionId, body),
    ...options,
    onSuccess: (data, vars, onMutateResult, ctx) => {
      qc.invalidateQueries({ queryKey: tillKeys.all(shopSlug) });
      options?.onSuccess?.(data, vars, onMutateResult, ctx);
    },
  });
}

// Plan-046 — handover: settle the shift but keep the chain open. Same payload
// shape as close (a handover settles like close).
export function useHandoverShift(
  shopSlug: string,
  sessionId: string,
  options?: UseMutationOptions<{ data: TillSession }, Error, CloseShiftPayload>,
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: CloseShiftPayload) =>
      tillService.handover(sessionId, body),
    ...options,
    onSuccess: (data, vars, onMutateResult, ctx) => {
      qc.invalidateQueries({ queryKey: tillKeys.all(shopSlug) });
      options?.onSuccess?.(data, vars, onMutateResult, ctx);
    },
  });
}

// Plan-046 — aggregate chain summary (enabled once a chainId is known).
export function useChainSummary(
  shopSlug: string,
  chainId: string | null | undefined,
) {
  return useQuery<{ data: ChainSummary }, Error, ChainSummary>({
    queryKey: tillKeys.chain(shopSlug, chainId ?? ""),
    queryFn: () => tillService.chainSummary(chainId as string),
    enabled: Boolean(chainId),
    select: (r) => r.data,
  });
}

export function useSaveDraft(
  shopSlug: string,
  sessionId: string,
  options?: UseMutationOptions<{ data: TillSession }, Error, DraftClosePayload>,
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: DraftClosePayload) =>
      tillService.saveDraft(sessionId, body),
    ...options,
    onSuccess: (data, vars, onMutateResult, ctx) => {
      qc.invalidateQueries({
        queryKey: tillKeys.reconciliation(shopSlug, sessionId),
      });
      options?.onSuccess?.(data, vars, onMutateResult, ctx);
    },
  });
}

export function useCashEvent(
  shopSlug: string,
  sessionId: string,
  options?: UseMutationOptions<{ data: unknown }, Error, CashEventPayload>,
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: CashEventPayload) =>
      tillService.cashEvent(sessionId, body),
    ...options,
    onSuccess: (data, vars, onMutateResult, ctx) => {
      qc.invalidateQueries({
        queryKey: tillKeys.reconciliation(shopSlug, sessionId),
      });
      qc.invalidateQueries({ queryKey: tillKeys.current(shopSlug) });
      options?.onSuccess?.(data, vars, onMutateResult, ctx);
    },
  });
}

export function useAbandonShift(
  shopSlug: string,
  sessionId: string,
  options?: UseMutationOptions<{ data: TillSession }, Error, string | null>,
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (reason: string | null) =>
      tillService.abandon(sessionId, reason),
    ...options,
    onSuccess: (data, vars, onMutateResult, ctx) => {
      qc.invalidateQueries({ queryKey: tillKeys.all(shopSlug) });
      options?.onSuccess?.(data, vars, onMutateResult, ctx);
    },
  });
}

// Re-export the types for components to import from one place.
export type {
  CurrentTill,
  Denomination,
  ReconciliationData,
  TillSession,
  TillTenderType,
};
