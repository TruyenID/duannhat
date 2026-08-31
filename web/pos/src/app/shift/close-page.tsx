/**
 * Shift Close page — /shop/:shopSlug/shift/close.
 *
 * Plan 030 — reconciliation screen aligned with pos-web design system.
 * Reuses Card / Dialog / Button / Input / Badge from @godxjp/ui, design
 * tokens, useTranslation for all strings.
 *
 * Layout: single-column on tablet (the prototype's 2-column intent is
 * preserved via the sticky summary footer at the bottom). Sub-brand
 * tenders are grouped by category with a per-category subtotal + signed
 * variance vs POS.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Dialog,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Label,
  Textarea,
} from "@godxjp/ui";
import { DialogContent } from "@/components/ui/dialog";
import { HelpButton } from "@/help/help-button";
import { AlertTriangleIcon, CheckCircle2Icon, ChevronLeftIcon, LockIcon, PrinterIcon, SaveIcon } from "lucide-react";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import { setCurrentShopSlug } from "@/lib/shop-context";
import { useTranslation } from "@/providers/app-provider";
import { useNetworkRequired } from "@/hooks/use-network-required";
import {
  useCloseOrderSummary,
  useCloseShift,
  useHandoverShift,
  useDenominations,
  usePaymentTerminals,
  useReconciliation,
  useSaveDraft,
  useTenderCategories,
  useTenderTypes,
  useTillCurrent,
} from "@/hooks/api/use-till";
import { workstationPrintService } from "@/services/workstation-print-service";
import type { TillTenderType } from "@/services/till-service";
import { ApiError } from "@/lib/api";
import { useAuth } from "@/providers/use-auth";
import { PosHeader } from "@/app/pos/components/pos-header";
import { DenominationCounter } from "./denomination-counter";
import { formatAmount, getCurrencyConfig, sumCountedCash } from "./currency";
import {
  computeSectionReconciles,
  computeTerminalReconcile,
} from "./terminal-reconcile";
import {
  buildTerminalSections,
  tenderCategoryLabel,
  tenderDisplayName,
} from "./tender-terminals";
import { Spinner } from "@/components/ui/spinner";

interface TenderInput {
  gross: string;
  cancel: string;
  terminal_total: string;
  reason: string;
}

function blankTender(): TenderInput {
  return { gross: "", cancel: "", terminal_total: "", reason: "" };
}

function num(s: string): number {
  const n = Number(s);
  return Number.isFinite(n) && n >= 0 ? n : 0;
}

/**
 * Toast cảnh báo in phải sống đủ lâu để người ta đọc VÀ bấm. Mặc định của
 * sonner là ~4s — vừa đủ để thấy một dòng chữ nhấp nháy rồi mất, đúng cái
 * #3050 lỗ 3 mô tả: cảnh báo hiện trên MÀN KHÁC vì việc in chạy nền sau khi
 * đã điều hướng.
 */
const PRINT_WARNING_MS = 12_000;

export function ShiftClosePage() {
  const { shopSlug = "" } = useParams<{ shopSlug: string }>();
  const navigate = useNavigate();
  const { t, locale } = useTranslation();

  /**
   * #3050 lỗ 3 — cảnh báo in hỏng phải BẤM ĐƯỢC, không phải trôi qua.
   *
   * Việc in chạy nền SAU khi đã điều hướng (cố ý: một máy in nhiệt ngủ sẽ giữ
   * thu ngân đứng lại vô hạn, và ca thì đã settle rồi). Hệ quả là cảnh báo hiện
   * trên MÀN KHÁC — nhân viên thấy một dòng thoáng qua nói vừa mất một chứng
   * từ, giữa lúc kết ca, và trước #3062 thì không có nút nào để lấy lại.
   *
   * Nay có trang lịch sử ca, nên cảnh báo dẫn thẳng tới đó. Kèm thời gian hiển
   * thị dài hơn mặc định: một toast có nút mà biến mất sau 4 giây thì cái nút
   * đó chỉ để trang trí.
   */
  const warnWithReprint = useCallback(
    (message: string) => {
      toast.warning(message, {
        duration: PRINT_WARNING_MS,
        action: {
          label: t("shift.close.print.open_history"),
          onClick: () => navigate(`/shop/${shopSlug}/shift/history`),
        },
      });
    },
    [navigate, shopSlug, t],
  );
  const network = useNetworkRequired();
  const { device } = useAuth();
  useEffect(() => {
    setCurrentShopSlug(shopSlug);
  }, [shopSlug]);

  const current = useTillCurrent(shopSlug);
  const session = current.data?.open_session;
  const sessionId = session?.id ?? "";
  // Only an open/closing shift may be settled. A shift closed elsewhere
  // (another terminal, force-abandon, expire, settle — plan-032) can still
  // sit in this terminal's cache; `useTillCurrent` refetches on mount so we
  // re-validate on entry, and this flag blocks rendering the count form for
  // a shift that is no longer open (issue #545) instead of only failing at
  // submit after the operator has already counted the drawer.
  const sessionActive =
    session?.status === "open" || session?.status === "closing";

  useEffect(() => {
    if (current.isFetched && !sessionActive) {
      toast.info(t("shift.close.no_session"));
      navigate(`/shop/${shopSlug}/shift/open`, { replace: true });
    }
  }, [current.isFetched, sessionActive, navigate, shopSlug, t]);

  // Currency is SNAPSHOTTED at session open and lives on till_sessions.
  // We deliberately do NOT live-read shop_order_settings.currency_code here
  // — admin flipping currency mid-shift would otherwise reformat the open
  // session's opening_float / denominations with a different currency,
  // producing meaningless variance. Backend already blocks the flip
  // (CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT 409); this snapshot is the
  // defense-in-depth half — close-page is correct even if a flip somehow
  // slips through (e.g. legacy data, race window before the block landed).
  const currency = session?.default_currency_code ?? "JPY";
  // Shift OPEN formats money through the shared currency config; CLOSE used a
  //formatAmount(bare, cur), which takes the browser locale and NO fraction
  // digits. On a two-decimal currency that renders 1234.50 as "1,234.5" — a
  // digit apparently missing, on the screen where a cashier counts a physical
  // drawer against the figure. formatAmount (not formatMoney) keeps the layout
  // exactly as it is: these sites already print the code beside the number, and
  // formatMoney would add the symbol on top of it.
  const cur = getCurrencyConfig(currency);
  const denominations = useDenominations(shopSlug, currency);
  const tenderTypes = useTenderTypes(shopSlug);
  const tenderCategoriesQuery = useTenderCategories(shopSlug);
  // Cash is intentionally NOT iterated as a reconciliation card here —
  // it's owned by the DenominationCounter section above. Filter it out
  // of the dynamic category list so a stale system row doesn't sneak in.
  const visibleCategories = (tenderCategoriesQuery.data ?? []).filter(
    (c) => c.key !== "cash",
  );
  // #1156 — registered payment terminals + accepts (empty until the POS
  // endpoint ships → single generic section, see usePaymentTerminals).
  const paymentTerminals = usePaymentTerminals(shopSlug);
  const reconciliation = useReconciliation(shopSlug, sessionId || null);
  // plan-044 R2 — paid orders settle into THIS shift; unpaid orders carry to the next.
  const orderSummary = useCloseOrderSummary(shopSlug, sessionId || null);

  const [closingCounts, setClosingCounts] = useState<Record<string, number>>(
    {},
  );
  // Denomination-table total (Σ value×qty) and the free-form odd-change /
  // adjustment that captures cash below the smallest configured
  // denomination. Counted cash = denomCash + adjustment (issue #542).
  const [denomCash, setDenomCash] = useState(0);
  const [cashAdjustment, setCashAdjustment] = useState("");
  const [tenders, setTenders] = useState<Record<string, TenderInput>>({});
  const [closingNote, setClosingNote] = useState("");
  // Payment-terminal device reconciliation (#1156 — multi-terminal). Non-cash
  // tenders are grouped into ONE SECTION PER registered payment terminal (via
  // the device's `accepts`; tenders no device covers fall into a generic
  // section, and a shop without terminal data gets exactly one generic
  // section = the old single-device behaviour). Each section has its own
  // declared-vs-system compare, its own batch-slip total input, and its own
  // variance reason — on submit each section's reason is stamped onto the
  // out-of-tolerance tenders living in that section so the per-anchor /
  // per-category backend gate (TillSessionService::close) is satisfied.
  const [sectionReasons, setSectionReasons] = useState<Record<string, string>>(
    {},
  );
  // Per-section 端末日計 batch grand total (the machine's own slip figure) —
  // persisted as `terminal_batch_total` on the section's anchor row.
  const [sectionBatchTotals, setSectionBatchTotals] = useState<
    Record<string, string>
  >({});
  const [confirmOpen, setConfirmOpen] = useState(false);

  // #3048 — trạng thái máy in HOÁ ĐƠN, hỏi TRƯỚC khi chốt ca.
  //
  // 本郷店 16/08: máy `Casher` (role `receipt_printer`) offline từ 20:00 JST,
  // ca chốt lúc 21:50 — offline gần hai tiếng. Phiếu 精算 chỉ đi máy mang role
  // đó và KHÔNG có fallback (#2593, cố ý), nên nó không có đường nào khác.
  //
  // Thu ngân chỉ biết sau khi ca đã settle — mà lúc đó không lùi được, và
  // không có đường in lại. Hỏi trước thì họ còn kịp bật lại máy.
  //
  // CẢNH BÁO, KHÔNG CHẶN: mất khả năng chốt ca tệ hơn mất tờ giấy, và luật
  // "warn, never block" (plan-052 §4) áp đúng ở đây. Máy trạm không với tới
  // được cũng không chặn — im lặng coi như "không biết", không phải "hỏng".
  const [receiptPrinterDown, setReceiptPrinterDown] = useState(false);
  useEffect(() => {
    if (!confirmOpen || !workstationPrintService.enabled) {
      return;
    }
    let alive = true;
    void workstationPrintService
      .getPrintStatus()
      .then((st) => {
        if (!alive) return;
        const r = st.printer_roles?.receipt_printer;
        // `configured === false` và `online === false` là hai chuyện khác nhau,
        // nhưng hệ quả cho tờ giấy thì giống hệt: không in được. `online`
        // vắng mặt nghĩa là bản máy trạm cũ chưa biết trả — coi như KHÔNG BIẾT,
        // đừng dựng ra một cảnh báo từ một trường không tồn tại.
        setReceiptPrinterDown(r ? r.configured === false || r.online === false : false);
      })
      .catch(() => {
        // Không với tới máy trạm ⇒ không kết luận gì.
        if (alive) setReceiptPrinterDown(false);
      });

    return () => {
      alive = false;
    };
  }, [confirmOpen]);
  // Plan-046 — which settle the confirm dialog will run.
  const [settleKind, setSettleKind] = useState<"final" | "handover">("final");

  const saveDraft = useSaveDraft(shopSlug, sessionId);
  const closeMut = useCloseShift(shopSlug, sessionId);
  const handoverMut = useHandoverShift(shopSlug, sessionId);

  // SC-11 (#1986) — restore a saved draft.
  //
  // The draft endpoint has always persisted the count; this screen never read it
  // back, so every state above started empty. A cashier who counted the drawer,
  // was called to the floor, and reloaded came back to a blank sheet — and to a
  // variance measured against a drawer the screen believed held nothing (−24.990
  // in the QA run, with the software demanding a reason for it). Saving work
  // nobody reads back is worse than not saving it: the screen says it was kept.
  //
  // Hydrate ONCE per session, tracked by id rather than a boolean. `useTillCurrent`
  // refetches on window focus, and re-applying the server's draft on every
  // refetch would wipe out whatever the cashier had typed since — a rarer bug
  // than this one and a far more infuriating one, because it destroys work while
  // they watch. Keyed by id so opening a DIFFERENT shift still hydrates.
  const hydratedSessionRef = useRef<string | null>(null);
  useEffect(() => {
    if (!session || !sessionId || hydratedSessionRef.current === sessionId) {
      return;
    }
    // Only a shift already in `closing` carries a draft. An `open` shift has
    // nothing saved, and marking it hydrated would be wrong: the cashier may
    // save a draft during this very mount and reload later.
    if (session.status !== "closing") {
      return;
    }
    hydratedSessionRef.current = sessionId;

    const counts = session.closing_counts ?? [];
    if (counts.length > 0) {
      const restored: Record<string, number> = {};
      let total = 0;
      for (const c of counts) {
        restored[c.denomination_id] = c.quantity;
        total += c.subtotal_amount;
      }
      setClosingCounts(restored);
      // `denomCash` MUST be restored alongside, and from the stored subtotals.
      //
      // It is only ever set by DenominationCounter's `onChange`, which does not
      // fire for a controlled `values` prop arriving on mount. Restoring the
      // counts without it leaves the counted total at 0 — the grid shows the
      // right quantities while the variance below it is computed against an
      // empty drawer, which is more misleading than restoring nothing at all.
      //
      // Summing `subtotal_amount` rather than recomputing value×quantity keeps
      // this identical to the figure the server settled on.
      setDenomCash(total);
    }

    // `!= null`, so a stored 0 restores as "0" rather than being read as absent.
    // Zero is a claim about the drawer — the cashier counted and found no loose
    // change — and turning it back into an empty box discards that claim.
    if (session.closing_cash_adjustment_amount != null) {
      setCashAdjustment(String(session.closing_cash_adjustment_amount));
    }
    if (session.closing_note) {
      setClosingNote(session.closing_note);
    }
  }, [session, sessionId]);

  const grouped = useMemo(() => {
    const groups: Record<string, TillTenderType[]> = {};
    for (const t of tenderTypes.data ?? []) {
      (groups[t.category] ??= []).push(t);
    }
    return groups;
  }, [tenderTypes.data]);

  const expectedByTender = useMemo(() => {
    const map: Record<string, number | null> = {};
    for (const r of reconciliation.data?.tenders ?? []) {
      map[r.tender_key] = r.expected_amount;
    }
    return map;
  }, [reconciliation.data]);

  const tolerance = current.data?.till.variance_tolerance_amount ?? 0;

  function tenderOf(key: string): TenderInput {
    return tenders[key] ?? blankTender();
  }
  function updateTender(key: string, patch: Partial<TenderInput>) {
    setTenders((prev) => ({
      ...prev,
      [key]: { ...blankTender(), ...prev[key], ...patch },
    }));
  }

  const categoryDeclared = useMemo(() => {
    const out: Record<string, number> = {};
    for (const tt of tenderTypes.data ?? []) {
      const ti = tenders[tt.tender_key];
      if (!ti) continue;
      out[tt.category] = (out[tt.category] ?? 0) + num(ti.gross) - num(ti.cancel);
    }
    return out;
  }, [tenderTypes.data, tenders]);

  const hasNonCashTenders = useMemo(
    () => visibleCategories.some((c) => (grouped[c.key] ?? []).length > 0),
    [visibleCategories, grouped],
  );
  // #1156 — one reconcile section per physical terminal. Sections come from
  // the device accepts (generic fallback when no device data); the pure math
  // lives in ./terminal-reconcile + ./tender-terminals so it can be
  // unit-tested away from this component.
  const reconcileInput = useMemo(
    () => ({
      tenderTypes: tenderTypes.data ?? [],
      tenders,
      categoryDeclared,
      categoryExpected: reconciliation.data?.category_expected ?? {},
      expectedByTender,
      visibleCategoryKeys: visibleCategories.map((c) => c.key),
      tolerance,
    }),
    [
      tenderTypes.data,
      tenders,
      categoryDeclared,
      reconciliation.data,
      expectedByTender,
      visibleCategories,
      tolerance,
    ],
  );
  const terminalSections = useMemo(
    () =>
      buildTerminalSections(
        paymentTerminals.data ?? [],
        tenderTypes.data ?? [],
        visibleCategories.map((c) => c.key),
      ),
    [paymentTerminals.data, tenderTypes.data, visibleCategories],
  );
  const sectionReconciles = useMemo(
    () => computeSectionReconciles(reconcileInput, terminalSections),
    [reconcileInput, terminalSections],
  );
  // Grand totals across every terminal (the number shown at the
  // reconciliation card header). Identical to the pre-#1156 single-device
  // math by construction (see computeSectionReconciles invariants).
  const { systemTotal: terminalSystemTotal } = useMemo(
    () => computeTerminalReconcile(reconcileInput),
    [reconcileInput],
  );
  // tender_key → the section whose reason it carries at submit.
  const sectionOfCarrier = useMemo(() => {
    const map = new Map<string, string>();
    for (const sr of sectionReconciles) {
      for (const key of sr.reasonCarrierKeys) map.set(key, sr.section.key);
    }
    return map;
  }, [sectionReconciles]);
  // Sections whose variance is out of tolerance (carriers present) but whose
  // reason input is still empty — blocks submit, mirroring the backend gate.
  const sectionsMissingReason = useMemo(
    () =>
      sectionReconciles.filter(
        (sr) =>
          sr.reasonCarrierKeys.size > 0 &&
          !(sectionReasons[sr.section.key] ?? "").trim(),
      ),
    [sectionReconciles, sectionReasons],
  );

  // Counted cash = denomination total + odd change. The denomination table
  // can only express multiples of configured denominations; `cashAdjustment`
  // adds sub-denomination change so the counted total matches the physical
  // drawer. Backend mirrors this exactly: counted_cash = Σ(denoms) +
  // closing_cash_adjustment (issue #542).
  const countedCash = useMemo(
    () => sumCountedCash(denomCash, num(cashAdjustment)),
    [denomCash, cashAdjustment],
  );

  const cashExpected = reconciliation.data?.cash.expected_cash ?? 0;
  const cashVariance = useMemo(
    () => Math.round((countedCash - cashExpected) * 100) / 100,
    [countedCash, cashExpected],
  );

  const outOfToleranceMissingReason = useMemo(() => {
    let missing = false;

    // Non-cash (payment terminals): each section's reason covers the
    // out-of-tolerance tenders living in THAT section. The union of section
    // carrier sets is exactly what the backend gate (per-anchor +
    // per-category rollup in TillSessionService::close) needs a reason on,
    // and `buildPayload` stamps each section's reason onto its carriers — so
    // requiring every affected section here mirrors the backend and close
    // never 422s with VARIANCE_REASON_REQUIRED.
    if (sectionsMissingReason.length > 0) missing = true;

    // Cash drawer variance is annotated via the closing note (cash reconciles
    // via the denomination counter, never as a terminal tender).
    if (Math.abs(cashVariance) > tolerance && !closingNote.trim()) {
      missing = true;
    }
    return missing;
  }, [sectionsMissingReason, cashVariance, tolerance, closingNote]);

  function buildPayload() {
    const closing_counts = Object.entries(closingCounts)
      .filter(([, q]) => q > 0)
      .map(([denomination_id, quantity]) => ({ denomination_id, quantity }));
    // #1156 — each section's batch-slip total rides `terminal_batch_total` on
    // ONE row of that section (its anchor row when present, else its first
    // tender) so per-device slips stay auditable without double counting.
    const typeByKey = new Map(
      (tenderTypes.data ?? []).map((tt) => [tt.tender_key, tt]),
    );
    const batchRowOfSection = new Map<string, string>();
    for (const section of terminalSections) {
      const raw = (sectionBatchTotals[section.key] ?? "").trim();
      if (!raw) continue;
      const anchor = section.tenderKeys.find(
        (k) => typeByKey.get(k)?.is_expected_anchor,
      );
      const rowKey = anchor ?? section.tenderKeys[0];
      if (rowKey) batchRowOfSection.set(section.key, rowKey);
    }
    const batchTotalFor = (tenderKey: string): number | null => {
      for (const [sectionKey, rowKey] of batchRowOfSection) {
        if (rowKey === tenderKey) {
          return num(sectionBatchTotals[sectionKey] ?? "");
        }
      }
      return null;
    };
    const tender_details = (tenderTypes.data ?? [])
      .map((tt) => {
        const ti = tenders[tt.tender_key];
        const gross = ti ? num(ti.gross) : 0;
        const cancel = ti ? num(ti.cancel) : 0;
        // Each section-level reason is stamped onto exactly the tenders the
        // backend gate needs it on (anchor rows + one carrier per out-of-tol
        // category, partitioned per section); everything else carries none.
        const carrierSection = sectionOfCarrier.get(tt.tender_key);
        const variance_reason = carrierSection
          ? (sectionReasons[carrierSection] ?? "").trim() || null
          : null;
        const terminal_batch_total = batchTotalFor(tt.tender_key);
        if (
          gross === 0 &&
          cancel === 0 &&
          !variance_reason &&
          terminal_batch_total === null
        ) {
          return null;
        }
        return {
          tender_key: tt.tender_key,
          gross_amount: gross,
          cancel_amount: cancel,
          terminal_batch_total,
          variance_reason,
        };
      })
      .filter(Boolean) as Array<{
      tender_key: string;
      gross_amount: number;
      cancel_amount: number;
      terminal_batch_total: number | null;
      variance_reason: string | null;
    }>;
    return {
      closing_counts,
      tender_details,
      closing_note: closingNote.trim() || null,
      closing_cash_adjustment: cashAdjustment.trim() ? num(cashAdjustment) : null,
    };
  }

  async function handleSaveDraft() {
    try {
      await saveDraft.mutateAsync(buildPayload());
      toast.success(t("shift.close.success.draft"));
    } catch (e) {
      toast.error(
        e instanceof Error ? e.message : t("shift.close.error.save_failed"),
      );
    }
  }

  async function handleConfirmClose() {
    const payload = buildPayload();
    if (payload.closing_counts.length === 0) {
      toast.error(t("shift.close.error.closing_counts_required"));
      return;
    }
    const isHandover = settleKind === "handover";
    try {
      const mut = isHandover ? handoverMut : closeMut;
      const res = await mut.mutateAsync({
        ...payload,
        closing_counts: payload.closing_counts,
        tender_details: payload.tender_details,
      });
      // Distinct confirmation per kind — after the dialog closes this toast is
      // the ONLY trace of which of the two the cashier just performed, and the
      // consequences differ (chain still open vs chain ended).
      toast.success(
        isHandover
          ? t("shift.handover.success.settled")
          : t("shift.close.final.success.settled"),
      );
      setConfirmOpen(false);
      // Leave for レジ開け IMMEDIATELY on a successful settle — the shift is
      // done, the next cashier must land on the open screen. Two hazards this
      // ordering avoids:
      //  1. The slip print below (workstationPrintService via lanFetch) has NO
      //     timeout — a slow/offline thermal printer would block the redirect
      //     indefinitely, leaving the cashier "stuck" in POS until a manual
      //     reload. So print AFTER navigating, in the background.
      //  2. We no longer lean on the till-current refetch reflecting the settle
      //     (the ShiftClosePage `!sessionActive` effect) to bounce us out — that
      //     path fires the misleading "no_session" toast and is at the mercy of
      //     the workstation clearing current_session_id before the refetch lands.
      navigate(`/shop/${shopSlug}/shift/open`, { replace: true });
      // Fire the single-shift slip (handover → 引き継ぎ header; final → 精算) to
      // the workstation thermal printer as a best-effort background task: the
      // shift is already settled, so a printer hiccup only surfaces a warning
      // toast (sonner is app-global, so it still shows after we navigate).
      // #3050 lỗ 4 — chưa ghép máy trạm thì TRƯỚC ĐÂY im lặng hoàn toàn: không
      // in, không toast, không gì cả, và ca vẫn đóng. Với quán CÓ máy trạm mà
      // pairing rơi, đó là mất giấy trong im lặng — dạng hỏng đắt nhất, vì
      // không ai biết để đi tìm.
      if (!workstationPrintService.enabled) {
        toast.warning(t("shift.close.print.no_workstation"), {
          duration: PRINT_WARNING_MS,
        });
      }

      if (workstationPrintService.enabled) {
        // #3050 — HAI lượt in ĐỘC LẬP, không phải một chuỗi `await`.
        //
        // Bản trước xếp phiếu chuỗi sau `await printShiftReport(...)` trong
        // cùng một `try`. Hợp đồng nói ba hàm báo cáo "resolve, không ném" với
        // máy nguội / không có máy in / bản cũ 404 — nhưng **5xx thật thì vẫn
        // nổi lên**, và `rp.Connect()` hỏng ở máy trạm chính là 5xx.
        //
        // Nên một lần kết nối hỏng ở phiếu CA lấy mất luôn phiếu CHUỖI: tờ
        // tổng hợp của cả ngày, thứ đắt hơn hẳn, và không ai biết nó đã không
        // được thử. Hai chứng từ khác nhau không được dùng chung một điểm hỏng.
        //
        // Tách rồi thì mỗi tờ tự chịu trách nhiệm: phiếu ca hỏng vẫn còn phiếu
        // chuỗi, và ngược lại. Vẫn là best-effort — ca đã settle, một máy in
        // trục trặc không được phép lật ngược việc đóng ca (plan-052 §4).
        void (async () => {
          try {
            const printed = await workstationPrintService.printShiftReport({
              shopSlug,
              sessionId: res.data.id,
              reportKind: isHandover ? "handover" : "settlement",
            });
            if (printed.status === "no_printer") {
              warnWithReprint(t("shift.close.print.no_printer"));
            } else if (
              printed.status === "offline" ||
              printed.status === "unsupported"
            ) {
              warnWithReprint(t("shift.close.print.offline"));
            }
          } catch {
            warnWithReprint(t("shift.close.print.failed"));
          }
        })();

        // Plan-046 — kết ca CUỐI in thêm phiếu chuỗi tổng hợp. Lượt riêng, để
        // nó sống sót khi phiếu ca ở trên hỏng.
        if (!isHandover && res.data.chain_id) {
          const chainId = res.data.chain_id;
          void (async () => {
            try {
              await workstationPrintService.printChainReport({
                shopSlug,
                chainId,
              });
            } catch {
              warnWithReprint(t("shift.close.print.chain_failed"));
            }
          })();
        }
      }
    } catch (err) {
      if (err instanceof ApiError) {
        const code = err.body?.code as string | undefined;
        if (code === "VARIANCE_REASON_REQUIRED") {
          toast.error(t("shift.close.error.variance_reason"));
        } else if (code === "SHIFT_NOT_OPEN") {
          toast.error(t("shift.close.error.shift_not_open"));
          navigate(`/shop/${shopSlug}/shift/open`, { replace: true });
        } else {
          toast.error(err.message);
        }
      } else {
        toast.error(
          err instanceof Error ? err.message : t("shift.close.error.failed"),
        );
      }
    } finally {
      setConfirmOpen(false);
    }
  }

  if (!sessionActive) return null;

  // Shop-defined custom categories carry their own name; the four seeded
  // `is_system` rows are platform vocabulary and get translated, so the close
  // screen and the payment dialog head their groups identically in whichever
  // language the cashier picked. The seeder writes one fixed language for
  // every organization on the platform, so "the names already match the i18n
  // copy" was only ever true for a Vietnamese operator.
  const categoryLabels: Record<string, string> = Object.fromEntries(
    visibleCategories.map((c) => [c.key, tenderCategoryLabel(c, t)]),
  );

  return (
    <div className="min-h-screen bg-muted/30 pb-24 text-foreground">
      <PosHeader
        shopName={device?.branch_name ?? "—"}
        breadcrumb={{
          parent: t("shift.breadcrumb.parent"),
          current: t("shift.close.title"),
        }}
        helpTopic="shift-close"
      />
      <main className="mx-auto max-w-4xl px-4 py-6 sm:px-6 sm:py-8">
        {/* Page header */}
        <header className="mb-5">
          <Button
            variant="outline"
            size="sm"
            onClick={() => navigate(`/shop/${shopSlug}`)}
            className="mb-3 h-9 gap-1.5 rounded-full border-primary/30 bg-primary/5 px-3.5 text-[13px] font-medium text-primary shadow-sm hover:border-primary/50 hover:bg-primary/10 hover:text-primary"
          >
            <span className="flex size-5 items-center justify-center rounded-full bg-primary/15">
              <ChevronLeftIcon className="size-3.5" />
            </span>
            {t("shift.close.back")}
          </Button>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-[22px] font-semibold leading-tight">
              {t("shift.close.title")}
            </h1>
            {/* Plan-046 — chain position badge ("Ca N"). */}
            {session?.chain_id ? (
              <Badge variant="outline" className="text-[11px]">
                {t("shift.badge.chain", {
                  seq: String(session.chain_sequence ?? 1),
                })}
              </Badge>
            ) : null}
          </div>
          <p className="mt-1 text-[13px] text-muted-foreground">
            {t("shift.close.subtitle")}
          </p>
        </header>

        {/* Session info card */}
        <Card className="mb-4 gap-0 p-0">
          <CardHeader className="border-b px-5 py-4">
            <CardTitle className="text-[15px] font-semibold">
              {t("shift.close.session.section")}
            </CardTitle>
          </CardHeader>
          <CardContent className="grid grid-cols-1 gap-3 px-5 py-4 text-[13px] sm:grid-cols-2">
            <KV
              label={t("shift.close.session.code")}
              value={session.session_code}
            />
            <KV
              label={t("shift.close.session.opened_at")}
              value={session.opened_at?.replace("T", " ").slice(0, 16) ?? "—"}
            />
            <KV
              label={t("shift.close.session.opening_float")}
              value={`${formatAmount(session.opening_float_amount, cur)} ${currency}`}
              strong
            />
            <KV
              label={t("shift.close.session.cash_sales")}
              value={`${formatAmount((reconciliation.data?.cash.cash_sales ?? 0), cur)} ${currency}`}
              strong
            />
            <KV
              label={t("shift.close.session.paid_in")}
              value={`${formatAmount((reconciliation.data?.cash.paid_in ?? 0), cur)} ${currency}`}
            />
            <KV
              label={t("shift.close.session.paid_out")}
              value={`${formatAmount((reconciliation.data?.cash.paid_out ?? 0), cur)} ${currency}`}
            />
            <div className="col-span-1 sm:col-span-2 mt-1 flex items-center justify-between rounded-md bg-muted/60 px-3 py-2">
              <span className="text-[13px] font-medium text-muted-foreground">
                {t("shift.close.session.expected_cash")}
              </span>
              <span className="text-[16px] font-bold tabular-nums">
                {formatAmount(cashExpected, cur)} {currency}
              </span>
            </div>
          </CardContent>
        </Card>

        {/* Cash count */}
        <Card className="mb-4 gap-0 p-0">
          <CardHeader className="border-b px-5 py-4">
            <CardTitle className="text-[15px] font-semibold">
              {t("shift.close.cash_count.section")}
            </CardTitle>
          </CardHeader>
          <CardContent className="px-5 py-5">
            {/* Reconciliation dashboard — cash + payment-terminal summaries
                pulled to the very top so both variances track at a glance while
                counting (user request 2026-07-20). The detailed counting +
                per-method terminal entry live below / in the next card. */}
            <div className="mb-5 space-y-3">
              {/* Cash: counted drawer vs expected drawer cash. */}
              <div className="rounded-lg border border-primary/25 bg-primary/5 px-4 py-3.5">
                <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                  <div className="min-w-0">
                    <div className="text-[13px] font-semibold">
                      {t("shift.close.reconcile.cash_recon.label")}
                    </div>
                    <p className="mt-0.5 text-[12px] text-muted-foreground">
                      {t("shift.close.reconcile.cash_recon.hint")}
                    </p>
                  </div>
                  <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                    <div className="text-right">
                      <div className="text-[11px] text-muted-foreground">
                        {t("shift.close.reconcile.cash_recon.counted")}
                      </div>
                      <div className="text-[17px] font-bold tabular-nums">
                        {formatAmount(countedCash, cur)} {currency}
                      </div>
                    </div>
                    <div className="text-right">
                      <div className="text-[11px] text-muted-foreground">
                        {t("shift.close.reconcile.cash_recon.expected")}
                      </div>
                      <div className="text-[17px] font-bold tabular-nums text-muted-foreground">
                        {formatAmount(cashExpected, cur)} {currency}
                      </div>
                    </div>
                    <div className="flex flex-col items-end gap-1">
                      <span className="text-[11px] text-muted-foreground">
                        {t("shift.close.reconcile.cash_recon.variance")}
                      </span>
                      <VarianceChip
                        value={cashVariance}
                        tolerance={tolerance}
                        currency={currency}
                      />
                    </div>
                  </div>
                </div>
              </div>

              {/* Payment terminals (#1156): one live summary box PER SECTION —
                  Σ of the cashier's entries vs the system expected attributed
                  to that terminal. Per-method inputs + the batch-slip total
                  stay in the reconciliation card below; these update live.
                  A shop with no device data shows exactly one generic box
                  (the old single-terminal behaviour). */}
              {hasNonCashTenders &&
                sectionReconciles.map((sr) => {
                  // Generic bucket: alone it keeps the historic "terminal
                  // grand total" wording; next to real device sections it
                  // must read as "the shared/uncovered bucket" instead.
                  const label =
                    sr.section.deviceName ??
                    (sectionReconciles.length === 1
                      ? t("shift.close.reconcile.terminal_total.label")
                      : t("shift.close.reconcile.terminal_section_generic"));
                  return (
                    <div
                      key={sr.section.key}
                      className="rounded-lg border border-primary/25 bg-primary/5 px-4 py-3.5"
                    >
                      <div className="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                        <div className="min-w-0">
                          <div className="text-[13px] font-semibold">
                            {label}
                          </div>
                          <p className="mt-0.5 text-[12px] text-muted-foreground">
                            {t("shift.close.reconcile.terminal_total.hint")}
                          </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                          <div className="text-right">
                            <div className="text-[11px] text-muted-foreground">
                              {t("shift.close.reconcile.terminal_entered")}
                            </div>
                            <div className="text-[17px] font-bold tabular-nums">
                              {formatAmount(sr.declaredTotal, cur)} {currency}
                            </div>
                          </div>
                          <div className="text-right">
                            <div className="text-[11px] text-muted-foreground">
                              {t("shift.close.reconcile.terminal_system")}
                            </div>
                            <div className="text-[17px] font-bold tabular-nums text-muted-foreground">
                              {formatAmount(sr.systemTotal, cur)} {currency}
                            </div>
                          </div>
                          <div className="flex flex-col items-end gap-1">
                            <span className="text-[11px] text-muted-foreground">
                              {t("shift.close.reconcile.terminal_variance")}
                            </span>
                            <VarianceChip
                              value={sr.variance}
                              tolerance={tolerance}
                              currency={currency}
                              offsetting={sr.reasonCarrierKeys.size > 0}
                            />
                          </div>
                        </div>
                      </div>
                      {/* One reason per terminal when out of tolerance —
                          stamped onto the backend's per-anchor / per-category
                          carriers living in this section on submit. */}
                      {sr.reasonCarrierKeys.size > 0 && (
                        <div className="mt-3 border-t border-primary/15 pt-3">
                          {/* #2616 — NÓI RA dòng nào lệch, bao nhiêu.
                              Hai tổng phía trên có thể cùng hiện `±0` khi hai
                              dòng lệch NGƯỢC DẤU triệt tiêu nhau, trong khi luật
                              khoá nút chạy ở mức TỪNG DÒNG. Thiếu khối này, thu
                              ngân nhìn một màn hình toàn số 0 kèm một lỗi chung
                              chung, giữa lúc kết ca. */}
                          {/* #3049 — NÓI RA chuyện bù trừ.
                              Khi tổng nằm trong ngưỡng mà bên dưới vẫn có dòng
                              lệch, người đọc cần biết vì sao một màn hình toàn
                              số 0 lại chặn mình. Không nói thì thu ngân gõ bừa
                              cho qua — và ta mất đúng thứ cổng sinh ra để lấy:
                              lý do THẬT. */}
                          {Math.abs(sr.variance) <= tolerance &&
                            sr.reasonCarriers.length > 0 && (
                              <p className="mb-2.5 text-[12px] leading-snug text-amber-700 dark:text-amber-300">
                                {t("shift.close.reconcile.offsetting_variance", {
                                  count: String(sr.reasonCarriers.length),
                                })}
                              </p>
                            )}
                          {sr.reasonCarriers.length > 0 && (
                            <ul className="mb-2.5 space-y-1">
                              {sr.reasonCarriers.map((c) => (
                                <li
                                  key={c.tenderKey}
                                  className="flex items-center justify-between gap-3 text-[12px]"
                                >
                                  <span className="min-w-0 truncate text-muted-foreground">
                                    {typeof c.name === "string"
                                      ? c.name
                                      : (c.name[locale] ??
                                        Object.values(c.name)[0] ??
                                        c.tenderKey)}
                                    {c.rule === "category" && (
                                      <span className="ml-1 text-[11px] opacity-70">
                                        {t("shift.close.reconcile.carrier_group_note")}
                                      </span>
                                    )}
                                  </span>
                                  <span
                                    className={cn(
                                      "shrink-0 font-semibold tabular-nums",
                                      c.variance > 0
                                        ? "text-amber-600"
                                        : "text-destructive",
                                    )}
                                  >
                                    {c.variance > 0 ? "+" : ""}
                                    {formatAmount(c.variance, cur)} {currency}
                                  </span>
                                </li>
                              ))}
                            </ul>
                          )}
                          <Input
                            className="h-9 text-[13px]"
                            placeholder={t(
                              "shift.close.reconcile.variance_reason_placeholder",
                            )}
                            value={sectionReasons[sr.section.key] ?? ""}
                            onChange={(e) =>
                              setSectionReasons((prev) => ({
                                ...prev,
                                [sr.section.key]: e.target.value,
                              }))
                            }
                            aria-label={`${label} — ${t(
                              "shift.close.reconcile.variance_reason_placeholder",
                            )}`}
                          />
                        </div>
                      )}
                    </div>
                  );
                })}
            </div>
            <DenominationCounter
              denominations={denominations.data ?? []}
              values={closingCounts}
              onChange={(next, total) => {
                setClosingCounts(next);
                setDenomCash(total);
              }}
              currencyCode={currency}
              totalPosition="top"
            />
            {/* Odd change / adjustment — cash that can't be built from the
                denomination table (sub-denomination change). Added to the
                counted total so variance reflects the physical drawer. */}
            <div className="mt-4 rounded-md border bg-muted/20 px-3.5 py-3">
              <div className="flex items-center justify-between gap-3">
                <Label
                  htmlFor="cash-odd-change"
                  className="text-[13px] font-medium"
                >
                  {t("shift.close.cash_count.odd_change.label")}
                </Label>
                <div className="flex items-center gap-2">
                  <Input
                    id="cash-odd-change"
                    className="h-9 w-32 text-right text-[14px] tabular-nums"
                    inputMode="decimal"
                    placeholder={t(
                      "shift.close.cash_count.odd_change.placeholder",
                    )}
                    value={cashAdjustment}
                    onChange={(e) =>
                      setCashAdjustment(e.target.value.replace(/[^\d.]/g, ""))
                    }
                    aria-label={t("shift.close.cash_count.odd_change.label")}
                  />
                  <span className="text-[13px] text-muted-foreground">
                    {currency}
                  </span>
                </div>
              </div>
              <p className="mt-1.5 text-[12px] text-muted-foreground">
                {t("shift.close.cash_count.odd_change.hint")}
              </p>
            </div>
          </CardContent>
        </Card>

        {/* Reconciliation */}
        <Card className="mb-4 gap-0 p-0">
          <CardHeader className="border-b px-5 py-4">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <CardTitle className="text-[15px] font-semibold">
                  {t("shift.close.reconcile.section")}
                </CardTitle>
                <p className="mt-1 text-[12px] text-muted-foreground">
                  {t("shift.close.reconcile.hint")}
                </p>
              </div>
              {/* System-recorded terminal revenue — the ONE place the aggregate
                  expected shows (on the title row per request). Per-method rows
                  below no longer repeat it; the full comparison lives in the
                  device-total box at the bottom of this card. */}
              {hasNonCashTenders && (
                <div className="shrink-0 text-right">
                  <div className="text-[11px] text-muted-foreground">
                    {t("shift.close.reconcile.terminal_system")}
                  </div>
                  <div className="text-[18px] font-bold tabular-nums">
                    {formatAmount(terminalSystemTotal, cur)} {currency}
                  </div>
                </div>
              )}
            </div>
          </CardHeader>
          <CardContent className="space-y-4 px-5 py-5">
            {/*
              Cash deliberately excluded — staff reconciles the cash drawer
              via the DenominationCounter card above, not as a tender-device
              row here. Backend still computes cash variance from
              closing_counts; reason text (when needed) goes into
              `closingNote` below.

              List is driven by the CRUD `tender-categories` endpoint so
              shop-defined custom categories (voucher / crypto / loyalty
              points / …) show up here automatically once created in shop
              settings.
            */}
            {/* #1156 — one block per payment-terminal section. Within a
                section, tenders keep their category grouping + subtotal. */}
            {sectionReconciles.map((sr) => {
              const sectionLabel =
                sr.section.deviceName ??
                t("shift.close.reconcile.terminal_section_generic");
              const sectionTenderKeys = new Set(sr.section.tenderKeys);
              const batchRaw = sectionBatchTotals[sr.section.key] ?? "";
              const batchEntered = batchRaw.trim() !== "";
              const batchMismatch =
                batchEntered &&
                Math.abs(num(batchRaw) - sr.declaredTotal) > tolerance;
              return (
                <div
                  key={sr.section.key}
                  className="rounded-lg border border-primary/20"
                >
                  {/* Section header: terminal name + the machine's own batch
                      grand total (端末日計合計) keyed from its slip. */}
                  <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-t-lg border-b bg-primary/5 px-3.5 py-2.5">
                    <div className="min-w-0">
                      <h3 className="text-[13px] font-semibold">
                        {sectionLabel}
                      </h3>
                      <span className="text-[11px] text-muted-foreground">
                        {t("shift.close.reconcile.sigma_net")}{" "}
                        <span className="tabular-nums text-foreground">
                          {formatAmount(sr.declaredTotal, cur)} {currency}
                        </span>
                      </span>
                    </div>
                    <div className="flex items-center gap-2">
                      <Label
                        htmlFor={`batch-total-${sr.section.key}`}
                        className="text-[12px] font-medium text-muted-foreground"
                      >
                        {t("shift.close.reconcile.batch_total_label")}
                      </Label>
                      <Input
                        id={`batch-total-${sr.section.key}`}
                        className="h-9 w-32 text-right text-[14px] tabular-nums"
                        inputMode="numeric"
                        placeholder="0"
                        value={batchRaw}
                        onChange={(e) =>
                          setSectionBatchTotals((prev) => ({
                            ...prev,
                            [sr.section.key]: e.target.value.replace(
                              /[^\d.]/g,
                              "",
                            ),
                          }))
                        }
                        aria-label={`${sectionLabel} — ${t(
                          "shift.close.reconcile.batch_total_label",
                        )}`}
                      />
                      <span className="text-[12px] text-muted-foreground">
                        {currency}
                      </span>
                    </div>
                  </div>
                  {batchMismatch && (
                    <p className="border-b bg-amber-50 px-3.5 py-1.5 text-[12px] text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                      {t("shift.close.reconcile.batch_mismatch", {
                        diff: formatAmount(num(batchRaw) - sr.declaredTotal, cur),
                      })}
                    </p>
                  )}
                  <div className="space-y-3 p-3">
                    {visibleCategories.map((catRow) => {
                      const cat = catRow.key;
                      const list = (grouped[cat] ?? []).filter((tt) =>
                        sectionTenderKeys.has(tt.tender_key),
                      );
                      if (list.length === 0) return null;
                      const declared = list.reduce((sum, tt) => {
                        const ti = tenders[tt.tender_key];
                        return (
                          sum +
                          (ti ? num(ti.gross) - num(ti.cancel) : 0)
                        );
                      }, 0);
                      const catLabel = categoryLabels[cat];
                      return (
                        <div key={cat} className="rounded-md border">
                          {/* Label + the cashier's OWN subtotal only. The
                              system expected shows once per section header
                              box above, never per method. */}
                          <div className="flex items-center justify-between gap-3 border-b bg-muted/40 px-3.5 py-2.5">
                            <h4 className="text-[13px] font-semibold">
                              {catLabel}
                            </h4>
                            <span className="text-[11px] text-muted-foreground">
                              {t("shift.close.reconcile.sigma_net")}{" "}
                              <span className="tabular-nums text-foreground">
                                {formatAmount(declared, cur)} {currency}
                              </span>
                            </span>
                          </div>
                          <div className="divide-y">
                            {list.map((tt) => {
                        const ti = tenderOf(tt.tender_key);
                        const net = num(ti.gross) - num(ti.cancel);
                        const nameLabel = tenderDisplayName(tt, locale);
                        // Just the entry: revenue − cancel = the row's net.
                        // No per-method expected / variance / reason — those
                        // moved to the device-total box below.
                        return (
                          <div
                            key={tt.tender_key}
                            className="grid grid-cols-12 items-center gap-2 px-3.5 py-2.5"
                          >
                            <div className="col-span-12 text-[13px] font-medium sm:col-span-4">
                              {nameLabel}
                            </div>
                            <Input
                              className="col-span-6 h-9 text-right text-[14px] tabular-nums sm:col-span-3"
                              placeholder={t(
                                "shift.close.reconcile.gross_placeholder",
                              )}
                              inputMode="numeric"
                              value={ti.gross}
                              onChange={(e) =>
                                updateTender(tt.tender_key, {
                                  gross: e.target.value.replace(/[^\d.]/g, ""),
                                })
                              }
                              aria-label={t("shift.close.reconcile.aria_gross", {
                                name: nameLabel,
                              })}
                            />
                            <Input
                              className="col-span-6 h-9 text-right text-[14px] tabular-nums sm:col-span-3"
                              placeholder={t(
                                "shift.close.reconcile.cancel_placeholder",
                              )}
                              inputMode="numeric"
                              value={ti.cancel}
                              onChange={(e) =>
                                updateTender(tt.tender_key, {
                                  cancel: e.target.value.replace(/[^\d.]/g, ""),
                                })
                              }
                              aria-label={t(
                                "shift.close.reconcile.aria_cancel",
                                { name: nameLabel },
                              )}
                            />
                            <div className="col-span-12 text-right font-mono text-[13px] tabular-nums text-muted-foreground sm:col-span-2">
                              {t("shift.close.reconcile.net")}{" "}
                              {formatAmount(net, cur)}
                            </div>
                          </div>
                        );
                      })}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>
              );
            })}

          </CardContent>
        </Card>

        {/* Order summary — moved below the cash-count + terminal reconciliation
            per request so the money-counting stays front-and-center; this stays
            reference info. Paid orders settle into THIS shift; unpaid orders
            carry naturally to the next (plan-044 R2). */}
        {orderSummary.data && (
          <Card className="mb-4 gap-0 p-0">
            <CardHeader className="border-b px-5 py-4">
              <CardTitle className="text-[15px] font-semibold">
                {t("shift.close.order_summary.section")}
              </CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-3 px-5 py-4 sm:grid-cols-2">
              <div className="rounded-md border bg-muted/40 px-4 py-3">
                <div className="text-[12px] text-muted-foreground">
                  {t("shift.close.order_summary.paid_label")}
                </div>
                <div className="mt-1 flex items-baseline gap-2">
                  <span className="text-[22px] font-bold tabular-nums">
                    {orderSummary.data.paid_orders_count}
                  </span>
                  <span className="text-[12px] text-muted-foreground">
                    {t("shift.close.order_summary.orders_unit")}
                  </span>
                </div>
                <div className="text-[13px] font-medium tabular-nums text-muted-foreground">
                  {formatAmount(orderSummary.data.paid_orders_total, cur)}{" "}
                  {currency}
                </div>
              </div>
              <div className="rounded-md border border-amber-200 bg-amber-50/50 px-4 py-3 dark:border-amber-700/40 dark:bg-amber-950/20">
                <div className="text-[12px] text-muted-foreground">
                  {t("shift.close.order_summary.unpaid_label")}
                </div>
                <div className="mt-1 flex items-baseline gap-2">
                  <span className="text-[22px] font-bold tabular-nums">
                    {orderSummary.data.unpaid_carry_count}
                  </span>
                  <span className="text-[12px] text-muted-foreground">
                    {t("shift.close.order_summary.orders_unit")}
                  </span>
                </div>
                <div className="text-[12px] leading-snug text-muted-foreground">
                  {t("shift.close.order_summary.carry_hint")}
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Note */}
        <Card className="mb-4 gap-0 p-0">
          <CardContent className="px-5 py-4">
            <Label
              htmlFor="closing-note"
              className="mb-1.5 block text-[13px] font-medium"
            >
              {t("shift.close.note.label")}
            </Label>
            <Textarea
              id="closing-note"
              value={closingNote}
              onChange={(e) => setClosingNote(e.target.value)}
              rows={2}
              maxLength={2000}
              className="min-h-[64px] text-[14px]"
            />
          </CardContent>
        </Card>

        {outOfToleranceMissingReason && (
          <Alert variant="destructive" className="mb-4">
            <AlertTriangleIcon className="size-4" />
            <AlertDescription>
              {t("shift.close.alert.variance_reason_required")}
            </AlertDescription>
          </Alert>
        )}
      </main>

      {/* Sticky footer */}
      <div className="fixed inset-x-0 bottom-0 z-10 border-t bg-background/95 px-4 py-3 backdrop-blur sm:px-6">
        <div className="mx-auto flex max-w-4xl items-center justify-between gap-3">
          <div className="min-w-0 space-y-0.5">
            <div className="text-[11px] uppercase tracking-wide text-muted-foreground">
              {t("shift.close.footer.counted_vs_expected")}
            </div>
            <div className="flex items-center gap-2.5">
              {/* Counted total — secondary; the variance is the headline. */}
              <span className="text-[14px] font-medium tabular-nums text-muted-foreground">
                {formatAmount(countedCash, cur)} {currency}
              </span>
              {/* Variance (過不足) — the number that actually matters at close,
                  so it's rendered big + colour-coded instead of a small badge. */}
              {Math.abs(cashVariance) <= tolerance ? (
                <span className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-3 py-1.5 text-[17px] font-bold tabular-nums text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30">
                  <CheckCircle2Icon className="size-[18px]" />
                  ±0 {currency}
                </span>
              ) : (
                <span
                  className={cn(
                    "inline-flex items-center gap-1.5 rounded-lg px-3.5 py-1.5 text-[19px] font-bold tabular-nums shadow-sm ring-1",
                    cashVariance < 0
                      ? "bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/40"
                      : "bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/40",
                  )}
                  aria-live="polite"
                >
                  <AlertTriangleIcon className="size-5" />
                  {cashVariance > 0 ? "+" : ""}
                  {formatAmount(cashVariance, cur)} {currency}
                </span>
              )}
            </div>
          </div>
          <div className="flex gap-2">
            <Button
              variant="outline"
              onClick={handleSaveDraft}
              disabled={saveDraft.isPending}
              className="h-11 gap-1.5"
            >
              {saveDraft.isPending ? (
                <Spinner className="size-4" />
              ) : (
                <SaveIcon className="size-4" />
              )}
              {saveDraft.isPending
                ? t("shift.close.action.saving")
                : t("shift.close.action.save_draft")}
            </Button>
            <Button
              variant="outline"
              disabled={
                closeMut.isPending ||
                handoverMut.isPending ||
                outOfToleranceMissingReason ||
                Object.values(closingCounts).every((q) => q === 0)
              }
              /* #1501 — kết toán ca cần Cloud tính lại snapshot uy quyền;
                 mất mạng thì khoá, không xếp hàng. */
              {...network.blockedProps}
              onClick={() => {
                setSettleKind("handover");
                setConfirmOpen(true);
              }}
              className="h-11 gap-1.5"
            >
              <PrinterIcon className="size-4" />
              {t("shift.handover.button")}
            </Button>
            <Button
              disabled={
                closeMut.isPending ||
                handoverMut.isPending ||
                outOfToleranceMissingReason ||
                Object.values(closingCounts).every((q) => q === 0)
              }
              {...network.blockedProps}
              onClick={() => {
                setSettleKind("final");
                setConfirmOpen(true);
              }}
              className="h-11 gap-1.5"
            >
              <PrinterIcon className="size-4" />
              {t("shift.close.final.button")}
            </Button>
          </div>
        </div>
      </div>

      <Dialog open={confirmOpen} onOpenChange={setConfirmOpen}>
        <DialogContent className="flex max-h-[85vh] max-w-md flex-col gap-0 rounded-2xl p-0 sm:max-w-md sm:rounded-lg">
          <DialogHeader className="flex flex-row items-start gap-3 border-b px-4 py-4 sm:px-6">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
              <LockIcon className="size-5" />
            </span>
            <div className="min-w-0 flex-1 space-y-1">
              <div className="flex items-center gap-1.5">
                <DialogTitle className="text-base sm:text-lg">
                  {settleKind === "handover"
                    ? t("shift.handover.confirm.title")
                    : t("shift.close.final.confirm.title")}
                </DialogTitle>
                <HelpButton topic="shift-settle-confirm" className="size-7" />
              </div>
              <DialogDescription className="text-xs sm:text-sm">
                {settleKind === "handover"
                  ? t("shift.handover.confirm.body")
                  : t("shift.close.final.confirm.body", {
                      count: String(session?.chain_sequence ?? 1),
                    })}
              </DialogDescription>
            </div>
          </DialogHeader>

          <div className="flex-1 space-y-4 overflow-y-auto px-4 py-4 sm:px-6 sm:py-5">
            {/* Hard warning banner */}
            <div className="flex items-start gap-2.5 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 dark:border-amber-700/40 dark:bg-amber-950/30">
              <AlertTriangleIcon className="mt-0.5 size-4 shrink-0 text-amber-600" />
              <p className="text-[12px] leading-relaxed text-amber-900 dark:text-amber-100">
                {t("shift.close.confirm.warning")}
              </p>
            </div>

            {/* Review summary */}
            <div>
              <div className="mb-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                {t("shift.close.confirm.review_title")}
              </div>
              <div className="divide-y rounded-md border bg-card">
                <ReviewRow
                  label={t("shift.close.session.expected_cash")}
                  value={`${formatAmount(cashExpected, cur)} ${currency}`}
                />
                <ReviewRow
                  label={t("shift.close.confirm.counted_cash")}
                  value={`${formatAmount(countedCash, cur)} ${currency}`}
                  strong
                />
                <ReviewRow
                  label={t("shift.close.confirm.cash_variance")}
                  value={
                    <span
                      className={cn(
                        "tabular-nums",
                        Math.abs(cashVariance) <= tolerance
                          ? "text-emerald-600 dark:text-emerald-400"
                          : cashVariance < 0
                            ? "text-destructive"
                            : "text-amber-700 dark:text-amber-300",
                      )}
                    >
                      {cashVariance > 0 ? "+" : ""}
                      {formatAmount(cashVariance, cur)} {currency}
                    </span>
                  }
                  strong
                />
              </div>
            </div>
          </div>

          {/* #3048 — cảnh báo máy in, KHÔNG chặn nút chốt ca. */}
          {receiptPrinterDown && (
            <div className="shrink-0 border-t bg-amber-50 px-4 py-3 text-xs text-amber-900 sm:px-6 dark:bg-amber-950/40 dark:text-amber-200">
              {t("shift.close.printer_offline_warning")}
            </div>
          )}

          <DialogFooter className="grid shrink-0 grid-cols-2 gap-2 border-t bg-muted/30 px-4 py-3 sm:px-6">
            <Button
              variant="outline"
              onClick={() => setConfirmOpen(false)}
              disabled={closeMut.isPending}
              className="h-11"
            >
              {t("shift.open.action.cancel")}
            </Button>
            <Button
              onClick={handleConfirmClose}
              disabled={closeMut.isPending || handoverMut.isPending}
              className="h-11 gap-1.5"
            >
              {closeMut.isPending || handoverMut.isPending ? (
                <Spinner className="size-4" />
              ) : (
                <LockIcon className="size-4" />
              )}
              {/* The CTA must name the action being confirmed. Both settle
                  kinds used to read "Xác nhận & Kết ca", so the last thing the
                  cashier saw before an irreversible, chain-ending close was
                  identical to the reversible-in-spirit handover. */}
              {closeMut.isPending || handoverMut.isPending
                ? t("shift.close.action.confirming")
                : settleKind === "handover"
                  ? t("shift.handover.action.confirm")
                  : t("shift.close.final.action.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

/**
 * Prominent signed-variance chip (過不足 style) shared by the cash + terminal
 * device-reconciliation summaries. Module-level so React never remounts it.
 */
function VarianceChip({
  value,
  tolerance,
  currency,
  offsetting = false,
}: {
  value: number;
  tolerance: number;
  currency: string;
  /**
   * #3049 — tổng NẰM TRONG ngưỡng nhưng bên dưới còn phương thức lệch, và
   * chúng bù trừ nhau.
   *
   * 本郷店: màn hình hiện `±0` xanh HAI LẦN rồi bắt nhập 「Lý do sai số」, trong
   * khi ba dòng bên dưới là −6.120 / +2.920 / +3.200 — cộng lại đúng bằng 0.
   * Nhân viên đọc hai huy hiệu xanh to trước, rồi bị chặn mà không hiểu vì sao.
   *
   * Cổng KHÔNG sai: backend gác theo TỪNG phương thức, vì ¥6.120 lẽ ra là thẻ
   * tín dụng mà ghi thành PayPay thì đối soát với từng nhà cung cấp sẽ sai —
   * tiền không mất, nhưng tiền đứng nhầm chỗ. Sai là ở chỗ MÀU nói ngược lại
   * cái nút đang làm.
   */
  offsetting?: boolean;
}) {
  if (Math.abs(value) <= tolerance && !offsetting) {
    return (
      <span className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-3 py-1 text-[15px] font-bold tabular-nums text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30">
        <CheckCircle2Icon className="size-4" />
        ±0 {currency}
      </span>
    );
  }

  // Tổng vẫn là ±0 — nói dối con số sẽ tệ hơn nói dối màu. Chỉ đổi MÀU và ICON,
  // và giữ nguyên giá trị, để người đọc thấy đúng hai điều: tổng bằng 0, và
  // vẫn có chuyện cần xem.
  if (Math.abs(value) <= tolerance) {
    return (
      <span
        className="inline-flex items-center gap-1.5 rounded-lg bg-amber-50 px-3 py-1 text-[15px] font-bold tabular-nums text-amber-700 ring-1 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/40"
        aria-live="polite"
      >
        <AlertTriangleIcon className="size-4" />
        ±0 {currency}
      </span>
    );
  }
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-lg px-3 py-1 text-[16px] font-bold tabular-nums shadow-sm ring-1",
        value < 0
          ? "bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/40"
          : "bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/40",
      )}
      aria-live="polite"
    >
      <AlertTriangleIcon className="size-4" />
      {value > 0 ? "+" : ""}
      {formatAmount(value, getCurrencyConfig(currency))} {currency}
    </span>
  );
}

function KV({
  label,
  value,
  strong,
}: {
  label: string;
  value: string;
  strong?: boolean;
}) {
  return (
    <div className="flex items-baseline justify-between gap-3">
      <span className="text-[12px] text-muted-foreground">{label}</span>
      <span
        className={cn(
          "tabular-nums",
          strong ? "text-[14px] font-semibold" : "text-[13px]",
        )}
      >
        {value}
      </span>
    </div>
  );
}

/** Row used inside the confirm-settle dialog summary. Higher density than KV. */
function ReviewRow({
  label,
  value,
  strong,
}: {
  label: string;
  value: React.ReactNode;
  strong?: boolean;
}) {
  return (
    <div className="flex items-baseline justify-between gap-3 px-3 py-2.5">
      <span className="text-[13px] text-muted-foreground">{label}</span>
      <span
        className={cn(
          "tabular-nums",
          strong ? "text-[16px] font-bold" : "text-[14px] font-medium",
        )}
      >
        {value}
      </span>
    </div>
  );
}
