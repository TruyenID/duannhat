"use client";

import { useState, useEffect, useRef } from "react";
import { useParams } from "next/navigation";
import { useRouter } from "@/i18n/routing";
import { useTranslations } from 'next-intl';
import { AlertCircle, ArrowLeft, Loader2, QrCode } from "lucide-react";
import { apiFetch, ApiError } from "@/lib/api";
import { sessionContinuity } from "@/lib/dine-in-session-continuity";
import {
  branchFromTablePayload,
  type TableBranchPayload,
} from "@/lib/branch-from-table-payload";
import { useBrand } from "@/context/brand-context";
import { useCart } from "@/context/cart-context";
import { useTableSessionRealtime } from "@/hooks/use-table-session-realtime";
import type { ActiveOrder } from "@/data/orders";
import Header from "@/components/Header";
import SummaryView from "./components/summary-view";
import MenuView from "./components/menu-view";
import PaymentView from "./components/payment-view";
import PaidView from "./components/paid-view";

export type TableView = "summary" | "menu" | "payment" | "paid";
export interface TableInfo { code: string; zone: string; seats: number; name?: string; }

/**
 * Gate state gated before the user can interact with menu/order.
 *   • `cleaning` — bàn đang dọn, block
 *   • `occupied` — bàn đang dùng (có order đang mở của khách khác), block
 *   • `rescan`   — user chọn "Đổi bàn", yêu cầu quét QR bàn khác
 *   • `null`     — cho phép vào menu (hoặc status = paid → view paid)
 *
 * Bàn `free` cho vào thẳng menu. Ngay sau khi load, FE gọi POST
 * /api/v1/customer/tables/{qrToken}/occupy để chuyển status `free → occupied`
 * — đánh dấu "có người ngồi" ngay tại thời điểm quét QR (yêu cầu UX), không
 * chờ đến lần đặt món đầu tiên. Endpoint idempotent: bàn đã `occupied` thì
 * trả 200, bàn ở status khác (cleaning/reserved/…) thì 409 và FE đã block ở
 * gate trước đó nên không bao giờ tới được call này.
 */
type StatusGate = "cleaning" | "occupied" | "rescan" | "session_ended" | null;

interface TableApiData {
  table: { id: string; number: string; seats: number; status: string; qr_token: string };
  zone: { id: string; name: string } | null;
  /** #1778 — shape lives in `lib/branch-from-table-payload.ts` alongside the
   * fold that consumes it. Duplicating it here is how `prices_include_tax`
   * went missing: this page cannot see which fields `/customer/branches`
   * carries that this payload does not. */
  branch: TableBranchPayload | null;
}

/**
 * Wrapper component — chỉ đọc qrToken/shop từ URL và truyền xuống. Dùng
 * `key={qrToken}` để khi URL đổi sang bàn khác, React UNMOUNT toàn bộ
 * TablePageInner (kèm state) rồi MOUNT mới hoàn toàn — không có nhịp
 * render nào với qrToken mới + state cũ.
 *
 * Nếu để toàn bộ state ở TablePage, khi qrToken đổi sẽ có 1 render với
 * qrToken mới nhưng `order`/`view`/`tableInfo` của bàn cũ trước khi
 * useEffect kịp chạy reset → user thoáng thấy lịch sử bàn cũ trên bàn mới.
 */
export default function TablePage() {
  const { shop, qrToken } = useParams<{ shop: string; qrToken: string }>();
  return <TablePageInner key={qrToken} qrToken={qrToken} shop={shop} />;
}

function TablePageInner({ qrToken, shop }: { qrToken: string; shop: string }) {
  const t = useTranslations('dineIn');
  const router = useRouter();
  const { setCurrentBranch } = useBrand();
  const {
    dineInTable: existingTable,
    setOrderType,
    setDineInTable,
    setIsTableLocked,
    clearCart,
  } = useCart();

  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);
  const [tableInfo, setTableInfo] = useState<TableInfo | null>(null);
  // issue #537 — raw table.status from the API (TableApiData), kept in its own
  // state. `tableInfo` (TableInfo) intentionally does NOT carry a `.table`
  // field, so the PaidView gate must read status from here, not
  // `tableInfo.table.status` (which is undefined → TypeError → white screen).
  const [tableStatus, setTableStatus] = useState<string | null>(null);

  const [view, setView] = useState<TableView>("menu");
  const [order, setOrder] = useState<ActiveOrder | null>(null);
  const [paidOrder, setPaidOrder] = useState<ActiveOrder | null>(null);
  const [gate, setGate] = useState<StatusGate>(null);
  // plan-034 — sessionId read from localStorage (or set fresh by /join).
  // The realtime hook below uses it to subscribe to the public Reverb
  // channel `table-session.{id}` so this device sees order edits from
  // any other device that joined the same table.
  const [sessionId, setSessionId] = useState<string | null>(() => {
    if (typeof window === 'undefined') return null;
    try {
      return localStorage.getItem(`dine_in_session_${qrToken}`);
    } catch {
      return null;
    }
  });

  // plan-034 — Reverb subscription. When any other device in the same
  // session adds an item or POS staff opens a soft-lock, this hook
  // updates local state so the UI reacts within < 1s.
  const { editingByStaff } = useTableSessionRealtime(sessionId, {
    onItemAdded: () => {
      // Refetch the active order so the bottom-of-screen cart count
      // and the summary view reflect what the other device added.
      if (!order?.id) return;
      void apiFetch<{ data: ActiveOrder }>(
        `/api/v1/customer/orders/${order.id}`,
      )
        .then((res) => setOrder(res.data))
        .catch(() => {
          // Best-effort — manual refresh still works.
        });
    },
  });

	  // Refs hold latest values of cross-context handlers + previous qrToken.
	  // Fetch effect only depends on qrToken/shop, không muốn chạy lại mỗi
	  // khi cart state đổi — đọc giá trị mới nhất qua ref thay vì đưa vào deps.
	  const previousQrTokenRef = useRef<string | null>(null);
  const setCurrentBranchRef = useRef(setCurrentBranch);
  const setOrderTypeRef = useRef(setOrderType);
  const setDineInTableRef = useRef(setDineInTable);
  const setIsTableLockedRef = useRef(setIsTableLocked);
  const clearCartRef = useRef(clearCart);
  useEffect(() => {
    setCurrentBranchRef.current = setCurrentBranch;
    setOrderTypeRef.current = setOrderType;
    setDineInTableRef.current = setDineInTable;
    setIsTableLockedRef.current = setIsTableLocked;
    clearCartRef.current = clearCart;
  });

  useEffect(() => {
    let cancelled = false;

    // RESET ALL STATE IMMEDIATELY when qrToken changes (different table)
    // Legitimate reset-on-prop-change: when qrToken changes (new table) we wipe
    // all derived state before re-fetching. React Compiler can't tell this is
    // intentional, hence the disable.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setLoading(true);
    setNotFound(false);
    setTableInfo(null);
    setTableStatus(null);
    setView("menu");
    setOrder(null);
    setPaidOrder(null);
    setGate(null);

    async function load() {
      try {
        // #5: GET /api/v1/customer/tables/{qrToken}
        const tableRes = await apiFetch<{ data: TableApiData }>(
          `/api/v1/customer/tables/${qrToken}`,
        );
        if (cancelled) return;

        const { table, zone, branch } = tableRes.data;
        setTableInfo({
          code: table.number,
          zone: zone?.name ?? "",
          seats: table.seats,
        });
        setTableStatus(table.status);

        // Update brand-context with real branch data from API (shop param as fallback slug)
        if (branch) {
          // #1778 — FOLD, do not replace. This used to build a fresh object out
          // of the table payload, which meant every field that payload does not
          // carry became its default — `prices_include_tax` fell to `false` and
          // the menu labelled tax-inclusive prices "Chưa gồm thuế". It flipped
          // per load because `/customer/branches` writes the same state and the
          // two responses race. `branchFromTablePayload` keeps what the other
          // source knew for the SAME branch, and still starts clean for a
          // different one (no cross-branch tax/currency leak).
          setCurrentBranchRef.current((prev) => branchFromTablePayload(prev, branch));
        } else if (shop) {
          setCurrentBranchRef.current({ id: "", name: "", slug: shop, brand: { id: "", slug: "", name: "" } });
        }

	        // Đồng bộ CartContext với bàn QR hiện tại.
	        // Nếu qrToken khác với lần trước → đây là bàn mới → clear cart
	        const prevQrToken = previousQrTokenRef.current;
	        const isDifferentTable = prevQrToken !== null && prevQrToken !== qrToken;

	        if (isDifferentTable) {
	          // Clear cart khi đổi bàn (qrToken thay đổi)
	          clearCartRef.current();
	        }

	        // Cập nhật previousQrTokenRef để lần sau so sánh
	        previousQrTokenRef.current = qrToken;
        setOrderTypeRef.current("dine_in");
        setDineInTableRef.current({
          id: table.id,
          number: table.number,
          seats: table.seats,
          zoneName: zone?.name ?? "",
          qr_token: table.qr_token,
        });
        setIsTableLockedRef.current(true);

	        // #8: GET /api/v1/customer/tables/{qrToken}/order
	        // Luôn đồng bộ lại state order theo từng bàn.
	        // Nếu bàn mới KHÔNG có đơn nào → setOrder(null) để tránh giữ lịch sử từ bàn cũ.
	        let fetchedOrder: ActiveOrder | null = null;
	        try {
	          // Add cache-busting timestamp to prevent browser cache from returning stale data
	          const cacheBuster = `?_t=${Date.now()}`;
	          const orderRes = await apiFetch<{ data: { order: ActiveOrder | null } }>(
	            `/api/v1/customer/tables/${qrToken}/order${cacheBuster}`,
	            { cache: 'no-store' }, // Force no-cache for this critical request
	          );
	          if (!cancelled) {
	            fetchedOrder = orderRes.data.order ?? null;
	            setOrder(fetchedOrder);
	            setPaidOrder(null);
	          }
	        } catch (err) {
	          // Phân biệt 404 (bàn chưa có đơn — đúng intent của FE) vs các
	          // lỗi khác (5xx / network) — nếu là lỗi server thật, không thể
	          // an toàn cho user vào menu (risk: user đặt order mới khi BE
	          // đã có đơn cũ → duplicate). Đẩy lên outer catch để FE vào
	          // "tableNotFound" UX, user refresh.
	          const isNotFound = err instanceof ApiError && err.status === 404;
	          if (!isNotFound) {
	            console.error('❌ [TablePage] Order fetch failed (non-404):', err);
	            throw err;
	          }
	          // 404 = bàn không có đơn nào → setOrder(null) là đúng
	          if (!cancelled) {
	            fetchedOrder = null;
	            setOrder(null);
	            setPaidOrder(null);
	          }
	        }

        if (!cancelled) {
          const status = table.status;

          // issue #362 — Path B "khách refresh tab sau khi đã thanh toán":
          // device này có localStorage `dine_in_session_*` (tức là chính
          // nó là người vừa thanh toán) AND BE đã đóng order / dọn bàn
          // (status `cleaning`/`free`/`paid` thay vì `occupied`) → kéo
          // thẳng về homepage.
          //
          // OrderClosingService flip `tables.status` sang `cleaning` chứ
          // không phải `paid`, nên check ở plan-034 trước đây không
          // trigger. Broaden điều kiện: BẤT KỲ status hậu-đóng-đơn nào
          // kết hợp với localStorage session đều coi là Path B.
          //
          // Device B fresh (không có localStorage) → fall-through xuống
          // nhánh plan-034, PaidView vẫn hiện kèm nút "Đặt thêm món".
          let hadSession = false;
          if (typeof window !== 'undefined') {
            try {
              hadSession = !!localStorage.getItem(`dine_in_session_${qrToken}`);
            } catch {
              // localStorage có thể bị disable → coi như chưa từng join.
            }
          }
          // Chỉ redirect khi BÀN đang ở trạng thái HẬU-pay-CHƯA-DỌN-XONG
          // (`cleaning`, hoặc legacy `paid`). Tuyệt đối KHÔNG redirect khi
          // bàn `free` vì đó là bàn đã được dọn, sẵn sàng cho lượt khách
          // tiếp theo — kể cả khi localStorage còn dư session id từ phiên
          // trước (browser-cache scenario), vẫn cần cho device quét QR
          // vào menu bình thường (bug khẩn 2026-06-12: device dư
          // localStorage bị redirect oan khỏi bàn free).
          const sessionLooksStale =
            hadSession &&
            (status === 'paid' || status === 'cleaning');
          if (sessionLooksStale) {
            try {
              localStorage.removeItem(`dine_in_session_${qrToken}`);
              localStorage.removeItem(`dine_in_occupied_${qrToken}`);
            } catch {
              // ignore
            }
            router.replace('/');
            return;
          }

          // plan-034 — every status branch funnels through POST /join.
          // The endpoint returns a `joined` payload for free/occupied
          // tables (so device B reuses device A's session + order), and
          // a `paid_recent` payload when the previous customer just
          // settled — in that case we render the PaidView with a "Đặt
          // thêm món" button that re-calls /join with `force_new`.
          if (status === "cleaning") {
            setGate("cleaning");
            setView("menu");
          } else if (status === "reserved" || status === "out_of_service") {
            setGate("occupied");
            setView("menu");
          } else if (status === "paid") {
            // Device B arrived after the last customer paid. Try /join
            // — BE may return paid_recent (user has to confirm "tôi
            // đang dùng tiếp") or, if device A scanned again before
            // staff cleared, fold into an already-reopened session.
            const joinResponse = await markTableJoin(qrToken).catch(() => null);
            if (joinResponse?.status === "paid_recent") {
              setView("paid");
              setGate(null);
            } else if (joinResponse?.status === "joined") {
              setView("menu");
              setGate(null);
            } else {
              // /join 423/404 — fall back to existing paid behaviour.
              setView("paid");
              setGate(null);
            }
          } else {
            // free OR occupied — both routed to /join. Session id stored
            // in localStorage is what unlocks subsequent scans on the
            // same device + the multi-device shared-order flow.
            setView("menu");
            setGate(null);
            void markTableJoin(qrToken);
          }
        }
      } catch {
        if (!cancelled) setNotFound(true);
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [qrToken, shop]);

  /**
   * plan-034 — POST /api/v1/customer/tables/{qrToken}/join.
   *
   * Replaces the old `/occupy`. The endpoint is idempotent and
   * branch-aware so the same call handles every scenario:
   *
   *   - free table → flip to occupied, open a TableSession, return
   *     {status:"joined", session, order:null}.
   *   - occupied table → return the existing open session + order so
   *     device B/C/N share device A's CustomerOrder.
   *   - paid table → return {status:"paid_recent", paid_order} so the
   *     caller can render PaidView with a "Đặt thêm món" button.
   *   - cleaning / reserved / out_of_service → 423 Locked (the parent
   *     load() function caught these earlier via the table status).
   *
   * The returned `session.id` is persisted to localStorage so
   * subsequent loads on the same device skip the "occupied by someone
   * else" gate and so the Reverb subscription has a stable channel id.
   */
  async function markTableJoin(
    token: string,
    options: { forceNew?: boolean } = {},
  ): Promise<{ status: 'joined' | 'paid_recent'; sessionId?: string } | null> {
    try {
      const query = options.forceNew ? '?force_new=true' : '';
      const res = await apiFetch<{
        data: {
          status: 'joined' | 'paid_recent';
          session?: { id: string; opened_at: string };
        };
      }>(`/api/v1/customer/tables/${token}/join${query}`, { method: 'POST' });

      if (res.data.session?.id) {
        // #2634 — phiên khách đang giữ có còn là phiên hiện hành không?
        // So ID, KHÔNG so `tables.status`: status gộp "phiên của tôi vừa bị
        // thay" với "tôi có rác localStorage và bàn giờ trống", và gate theo
        // nó chính là bug khẩn 2026-06-12 (đá oan người quét QR vào bàn free).
        let previouslyStored: string | null = null;
        try {
          previouslyStored = localStorage.getItem(`dine_in_session_${token}`);
        } catch {
          // localStorage bị tắt → coi như chưa từng join.
        }
        if (sessionContinuity(previouslyStored, res.data.session.id) === 'replaced') {
          // Nhân viên đã đóng phiên (thường là lúc trả bàn về `free`, #2611).
          // Món cũ nằm lại ở đơn cũ; đi tiếp trong im lặng là để khách mất giỏ
          // hàng mà không biết. Dừng lại, nói ra, và mời quét lại.
          try {
            localStorage.removeItem(`dine_in_session_${token}`);
            localStorage.removeItem(`dine_in_occupied_${token}`);
          } catch {
            // ignore
          }
          setOrder(null);
          setPaidOrder(null);
          setGate('session_ended');
          return null;
        }

        setSessionId(res.data.session.id);
        try {
          localStorage.setItem(`dine_in_session_${token}`, res.data.session.id);
          // Legacy flag kept for backward-compatibility with pre-plan-034
          // builds still running in other tabs / windows.
          localStorage.setItem(`dine_in_occupied_${token}`, 'true');
        } catch {
          // incognito disables localStorage — BE remains source of truth.
        }
      }

      return {
        status: res.data.status,
        sessionId: res.data.session?.id,
      };
    } catch (err) {
      console.warn('[TablePage] join failed (non-fatal):', err);
      return null;
    }
  }

  async function handleOrderCreated(newOrder: ActiveOrder) {
    // Đánh dấu device này là "người vừa đặt bàn" — lần load sau, dù BE
    // trả về status=occupied, FE vẫn cho vào (occupiedByMe = true). Cờ
    // được xoá khi thanh toán xong.
    try {
      localStorage.setItem(`dine_in_occupied_${qrToken}`, "true");
    } catch {
      // localStorage có thể bị disable trong incognito — không sao,
      // BE-side order vẫn là source of truth cho lần load kế.
    }

    // Refetch full order from server to get complete item history
    try {
      const res = await apiFetch<{ data: ActiveOrder }>(`/api/v1/customer/orders/${newOrder.id}`);
      setOrder(res.data);
    } catch (err) {
      console.error('[TablePage] Failed to refetch order after creation:', err);
      // Fallback to newOrder if refetch fails
      setOrder(newOrder);
    }
    setView("menu");
  }

  // Khi chuyển sang payment/summary, refetch /order để đồng bộ với server
  // (state local có thể lệch do mergeNewItems merge theo name, hoặc thiếu
  // image_url trên items đã đặt trước khi backend bổ sung field này).
  //
  // NOTE: `order` is intentionally NOT in the deps array — including it would
  // cause an infinite refetch loop (setOrder → effect → fetch → setOrder → …).
  // The refetch is triggered only when the user navigates to payment/summary.
  const orderRef = useRef(order);
  useEffect(() => {
    orderRef.current = order;
  }, [order]);

  useEffect(() => {
    if (view !== "payment" && view !== "summary") return;
    // Fresh table (or just reset) — nothing to reconcile against the server.
    if (orderRef.current === null) return;

    let cancelled = false;
    (async () => {
      try {
        const res = await apiFetch<{ data: { order: ActiveOrder | null } }>(
          `/api/v1/customer/tables/${qrToken}/order`,
        );
        if (!cancelled) {
          setOrder(res.data.order ?? null);
        }
      } catch (err) {
        // Non-fatal: the summary/payment view keeps the last known order,
        // which may now be stale — worth a line when a total looks wrong.
        console.warn('[TablePage] refetch on view change failed:', err);
      }
    })();
    return () => { cancelled = true; };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [view, qrToken]);

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center min-h-screen gap-3 bg-[#FAFAFA]">
        <Loader2 className="size-8 animate-spin text-primary" />
        <p className="text-sm text-muted-foreground">{t('loading')}</p>
      </div>
    );
  }

  if (notFound || !tableInfo) {
    return (
      <div className="flex flex-col items-center justify-center min-h-screen gap-3 px-6 text-center bg-[#FAFAFA]">
        <AlertCircle className="size-12 text-red-300" />
        <p className="font-semibold text-neutral-700">{t('tableNotFound')}</p>
        <p className="text-sm text-neutral-400">{t('scanOtherTable')}</p>
      </div>
    );
  }

  // Blocker full-screen cho các trạng thái không cho phép dùng bàn
  // hoặc khi user chọn "Đổi bàn" để quét lại QR.
  if (gate === "cleaning" || gate === "occupied" || gate === "rescan" || gate === "session_ended") {
    return <TableBlocker variant={gate} table={tableInfo} />;
  }

  let mainView: React.ReactNode = null;
  switch (view) {
    case "summary":
      mainView = (
        <SummaryView
          table={tableInfo}
          order={order}
          onBack={() => setView("menu")}
          onPay={() => router.push(`/dine-in/${shop}/table/${qrToken}/payment`)}
        />
      );
      break;
    case "menu": {
      const hasItems = !!order && order.items.length > 0;
      mainView = (
        <MenuView
          table={tableInfo}
          qrToken={qrToken}
          hasExistingOrder={hasItems}
          editingByStaff={editingByStaff}
          onBack={hasItems ? () => setView("summary") : undefined}
          onPay={hasItems ? () => router.push(`/dine-in/${shop}/table/${qrToken}/payment`) : undefined}
        />
      );
      break;
    }
    case "paid":
      // plan-034 — only show the "Đặt thêm món" button when the table
      // is still flagged paid (i.e. device B scanned a paid-but-uncleared
      // table). If the user just finished payment in this same browser
      // session, `paidOrder` is local and we keep the original behaviour
      // (review / back-to-home only).
      mainView = (
        <PaidView
          table={tableInfo}
          order={paidOrder ?? order}
          onAddMoreItems={
            tableStatus === 'paid' && !paidOrder
              ? async () => {
                  const res = await markTableJoin(qrToken, { forceNew: true });
                  if (res?.status === 'joined') {
                    setView('menu');
                    setGate(null);
                  }
                }
              : undefined
          }
        />
      );
      break;
    case "payment":
      // Payment đã được tách ra route riêng /payment — case này không bao giờ
      // chạy vì onPay đã dùng router.push thay vì setView("payment"). Giữ
      // empty fallback để TS exhaustive check.
      mainView = null;
      break;
  }

  return (
    // Page bg theo yêu cầu — #FAFAFA phủ toàn route dine-in (summary / menu /
    // payment / paid). Header tự override bằng bg-white; các view con render
    // trên nền này thay vì bg-background mặc định.
    <div className="min-h-screen bg-[#FAFAFA]">
      <Header showLogo hideSwitcher hideAuth hideOrderCta hideOrderHistory hideShadow={view === "summary" || view === "payment"} />
      {mainView}
    </div>
  );
}

/** Full-screen blocker hiển thị khi bàn không khả dụng hoặc yêu cầu quét lại QR. */
function TableBlocker({
  variant,
  table,
}: {
  variant: "cleaning" | "occupied" | "rescan" | "session_ended";
  table: TableInfo;
}) {
  const t = useTranslations('dineIn');
  // `session_ended` dùng CHUNG khung quét-lại với `rescan` (cùng icon QR, cùng
  // hành động), nhưng KHÁC lời: `rescan` là "khách tự chọn đổi bàn", còn đây là
  // "phiên của bạn vừa bị kết thúc". Dùng lại nguyên văn `rescan` sẽ nói sai
  // chuyện đang xảy ra (#2634).
  const isRescan = variant === "rescan" || variant === "session_ended";
  const title =
    variant === "cleaning"
      ? t('tableCleaningTitle')
      : variant === "occupied"
        ? t('tableOccupiedTitle')
        : t('scanOtherTableTitle');
  const message =
    variant === "cleaning"
      ? t('tableCleaning')
      : variant === "occupied"
        ? t('tableOccupied')
        : t('scanOtherTable');
  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-4 px-6 text-center">
      <div
        className={`flex size-16 items-center justify-center rounded-full ${
          isRescan ? "bg-primary/10" : "bg-amber-100"
        }`}
      >
        {isRescan ? (
          <QrCode className="size-8 text-primary" />
        ) : (
          <AlertCircle className="size-8 text-amber-600" />
        )}
      </div>
      <div className="space-y-1">
        <h1 className="text-lg font-semibold text-neutral-800">{title}</h1>
        <p className="max-w-sm text-sm text-neutral-500">{message}</p>
        {!isRescan && (
          <p className="pt-2 text-xs text-neutral-400">
            {t('tableDesc', { code: table.code })}
            {table.zone ? ` · ${table.zone}` : ""}
          </p>
        )}
      </div>
      {variant === "occupied" && (
        <button
          type="button"
          onClick={() => {
            if (window.history.length > 1) {
              window.history.back();
            } else {
              window.close();
            }
          }}
          style={{ backgroundColor: '#2D8336' }}
          className="mt-2 inline-flex h-10 items-center gap-2 rounded-md px-4 text-sm font-normal text-white shadow-sm hover:opacity-90"
        >
          <ArrowLeft className="size-4" />
          {t('chooseOtherTable')}
        </button>
      )}
    </div>
  );
}
