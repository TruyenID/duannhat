// src/providers/payment-flow-provider.tsx
import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useReducer,
  type ReactNode,
} from "react";
import type {
  CompletedPayment,
  ItemAllocation,
  Order,
  PaymentMethod,
  SplitMode,
} from "../types/kiosk";
import { generateIdempotencyKey } from "../lib/idempotency-key";

interface PaymentFlowState {
  tableId: string | null;
  orderCode: string | null;
  orderId: string | null;
  order: Order | null;
  splitMode: SplitMode;
  numberOfPeople: number;
  currentPersonIndex: number;
  payments: CompletedPayment[];
  currentAmount: number;
  payAllRemaining: boolean;
  selectedMethod: PaymentMethod | null;
  idempotencyKey: string | null;
  /** by_items: order items + units the current guest is paying for. */
  itemAllocations: ItemAllocation[];
}

const initialState: PaymentFlowState = {
  tableId: null,
  orderCode: null,
  orderId: null,
  order: null,
  splitMode: "full",
  numberOfPeople: 2,
  currentPersonIndex: 0,
  payments: [],
  currentAmount: 0,
  payAllRemaining: false,
  selectedMethod: null,
  idempotencyKey: null,
  itemAllocations: [],
};

type Action =
  | { type: "SET_TABLE"; tableId: string }
  | { type: "SET_ORDER_CODE"; orderCode: string }
  | { type: "SET_ORDER"; order: Order }
  | { type: "SET_SPLIT_MODE"; mode: SplitMode }
  | { type: "SET_NUMBER_OF_PEOPLE"; count: number }
  | { type: "SET_CURRENT_AMOUNT"; amount: number }
  | { type: "SET_ITEM_ALLOCATIONS"; allocations: ItemAllocation[]; amount: number }
  | { type: "SET_PAY_ALL_REMAINING"; value: boolean }
  | { type: "SET_METHOD"; method: PaymentMethod }
  | { type: "RECORD_PAYMENT"; payment: CompletedPayment }
  | { type: "NEW_ATTEMPT"; key: string }
  | { type: "NEXT_PERSON" }
  | { type: "RESET" };

function reducer(state: PaymentFlowState, action: Action): PaymentFlowState {
  switch (action.type) {
    case "SET_TABLE":
      return { ...state, tableId: action.tableId, orderCode: null };
    case "SET_ORDER_CODE":
      return { ...state, orderCode: action.orderCode, tableId: null };
    case "SET_ORDER": {
      const paidAmt = action.order.paid_amount ?? 0;
      const syntheticPrior =
        paidAmt > 0
          ? [{ payment_id: '', reference_no: '', method: 'cash' as const, amount: paidAmt, timestamp: '' }]
          : [];
      // A DIFFERENT order id = a brand-new payment flow. Clear all per-order
      // progress (payments, split mode, person index, allocations…) so the
      // previous order's paid amount can't bleed into this one — that was the
      // "order B's ¥3,914 shows up on order A" bug. Keep the table/orderCode
      // identity that was set just before navigating to the bill.
      if (state.orderId !== action.order.id) {
        return {
          ...initialState,
          tableId: state.tableId,
          orderCode: state.orderCode,
          order: action.order,
          orderId: action.order.id,
          payments: syntheticPrior,
        };
      }
      // Same order (React Query re-fetch / back-nav) — keep recorded payments;
      // only seed the prior-paid synthetic entry if we have none yet.
      const priorPaid =
        paidAmt > 0 && state.payments.length === 0 ? syntheticPrior : state.payments;
      return {
        ...state,
        order: action.order,
        orderId: action.order.id,
        payments: priorPaid,
      };
    }
    case "SET_SPLIT_MODE":
      if (state.splitMode === action.mode) return state;
      // The split methods are INDEPENDENT and each works on the REMAINING
      // balance. Switching method resets the per-method progress (people count,
      // person index, current amount, item selection) so e.g. split-even after
      // by-items starts fresh: remaining ÷ a freshly-chosen headcount, not the
      // leftover person index from the by-items flow. Recorded `payments` are
      // kept — that's real money already paid, which `remainingAmount` reflects.
      return {
        ...state,
        splitMode: action.mode,
        numberOfPeople: 2,
        currentPersonIndex: 0,
        currentAmount: 0,
        payAllRemaining: false,
        selectedMethod: null,
        idempotencyKey: null,
        itemAllocations: [],
      };
    case "SET_NUMBER_OF_PEOPLE":
      return {
        ...state,
        numberOfPeople: Math.max(2, Math.min(20, action.count)),
      };
    case "SET_CURRENT_AMOUNT":
      return { ...state, currentAmount: action.amount };
    case "SET_ITEM_ALLOCATIONS":
      return {
        ...state,
        itemAllocations: action.allocations,
        currentAmount: action.amount,
      };
    case "SET_PAY_ALL_REMAINING":
      return { ...state, payAllRemaining: action.value };
    case "SET_METHOD":
      return { ...state, selectedMethod: action.method };
    case "RECORD_PAYMENT":
      return {
        ...state,
        payments: [...state.payments, action.payment],
      };
    case "NEW_ATTEMPT":
      return { ...state, idempotencyKey: action.key };
    case "NEXT_PERSON":
      return {
        ...state,
        currentPersonIndex: state.currentPersonIndex + 1,
        selectedMethod: null,
        currentAmount: 0,
        payAllRemaining: false,
        idempotencyKey: null,
        itemAllocations: [],
      };
    case "RESET":
      return initialState;
    default:
      return state;
  }
}

interface PaymentFlowContextValue {
  state: PaymentFlowState;
  // Derived values
  totalAmount: number;
  paidAmount: number;
  remainingAmount: number;
  perPersonAmount: number;
  isComplete: boolean;
  currentPayAmount: number;
  // Actions
  setTable: (tableId: string) => void;
  setOrderCode: (orderCode: string) => void;
  setOrder: (order: Order) => void;
  setSplitMode: (mode: SplitMode) => void;
  setNumberOfPeople: (count: number) => void;
  setCurrentAmount: (amount: number) => void;
  setItemAllocations: (allocations: ItemAllocation[], amount: number) => void;
  setPayAllRemaining: (value: boolean) => void;
  setMethod: (method: PaymentMethod) => void;
  recordPayment: (payment: CompletedPayment) => void;
  refreshOrder: () => Promise<void>;
  newAttempt: () => string;
  nextPerson: () => void;
  reset: () => void;
}

const PaymentFlowContext = createContext<PaymentFlowContextValue | undefined>(
  undefined,
);

export function PaymentFlowProvider({ children }: { children: ReactNode }) {
  const [state, dispatch] = useReducer(reducer, initialState);

  const totalAmount = state.order?.total ?? 0;
  // `paidAmount` is server-authoritative (the order is re-fetched after each
  // payment via recordPayment → refreshOrder): take the larger of the server
  // figure and the client running-sum so a just-recorded payment shows instantly
  // even before the re-fetch lands. `remainingAmount` is ALWAYS derived from it
  // so that paid + remaining === total — never a separate (possibly stale or
  // inconsistent) server `remaining_amount` that wouldn't match the paid shown.
  const clientPaid = state.payments.reduce((sum, p) => sum + p.amount, 0);
  const paidAmount = Math.max(state.order?.paid_amount ?? 0, clientPaid);
  const remainingAmount = Math.max(0, totalAmount - paidAmount);
  // Split the REMAINING balance among the people who haven't paid yet
  // (numberOfPeople − those already done), NOT the fixed headcount. Dividing by
  // the full count every round just halves the remainder forever (5864 → 2932 →
  // 1466 …): the balance never reaches 0, so it never completes and the person
  // index runs past N (e.g. "person 3/2"). With remaining-people, the last
  // person pays the exact remainder → balance hits 0 → isComplete fires.
  const remainingPeople = Math.max(1, state.numberOfPeople - state.currentPersonIndex);
  const perPersonAmount = Math.ceil(remainingAmount / remainingPeople);

  const isComplete = remainingAmount <= 0 && totalAmount > 0;

  const currentPayAmount = useMemo(() => {
    if (state.splitMode === "full") return remainingAmount;
    if (state.splitMode === "split_even") return perPersonAmount;
    // by_items: bill total is computed server-side (preview) and stored in
    // currentAmount when the guest confirms their item selection.
    if (state.splitMode === "by_items") return state.currentAmount;
    if (state.payAllRemaining) return remainingAmount;
    return state.currentAmount;
  }, [
    state.splitMode,
    state.payAllRemaining,
    state.currentAmount,
    totalAmount,
    perPersonAmount,
    remainingAmount,
  ]);

  const setTable = useCallback(
    (tableId: string) => dispatch({ type: "SET_TABLE", tableId }),
    [],
  );
  const setOrderCode = useCallback(
    (orderCode: string) => dispatch({ type: "SET_ORDER_CODE", orderCode }),
    [],
  );
  const setOrder = useCallback(
    (order: Order) => dispatch({ type: "SET_ORDER", order }),
    [],
  );
  const setSplitMode = useCallback(
    (mode: SplitMode) => dispatch({ type: "SET_SPLIT_MODE", mode }),
    [],
  );
  const setNumberOfPeople = useCallback(
    (count: number) => dispatch({ type: "SET_NUMBER_OF_PEOPLE", count }),
    [],
  );
  const setCurrentAmount = useCallback(
    (amount: number) => dispatch({ type: "SET_CURRENT_AMOUNT", amount }),
    [],
  );
  const setItemAllocations = useCallback(
    (allocations: ItemAllocation[], amount: number) =>
      dispatch({ type: "SET_ITEM_ALLOCATIONS", allocations, amount }),
    [],
  );
  const setPayAllRemaining = useCallback(
    (value: boolean) => dispatch({ type: "SET_PAY_ALL_REMAINING", value }),
    [],
  );
  const setMethod = useCallback(
    (method: PaymentMethod) => dispatch({ type: "SET_METHOD", method }),
    [],
  );
  // Re-fetch the order so the server-authoritative figures (paid_amount,
  // remaining_amount, items[].remaining_units) reflect the latest payment.
  // Fire-and-forget: the client running-sum keeps the UI correct meanwhile, and
  // a failed refresh just leaves the last-known order in place.
  const refreshOrder = useCallback(async () => {
    try {
      // Dynamic import so the provider's static module graph doesn't pull in
      // api.ts (and its react-native deps) — keeps it out of unit-test graphs.
      const api = await import("../lib/api");
      const fresh = state.orderCode
        ? await api.fetchOrderByCode(state.orderCode)
        : state.tableId
          ? await api.fetchOrderByTable(state.tableId)
          : null;
      if (fresh) dispatch({ type: "SET_ORDER", order: fresh });
    } catch {
      // keep last-known order; client-side fallback still drives progress
    }
  }, [state.orderCode, state.tableId]);
  const recordPayment = useCallback(
    (payment: CompletedPayment) => {
      dispatch({ type: "RECORD_PAYMENT", payment });
      void refreshOrder();
    },
    [refreshOrder],
  );
  const newAttempt = useCallback(() => {
    const key = generateIdempotencyKey();
    dispatch({ type: "NEW_ATTEMPT", key });
    return key;
  }, []);
  const nextPerson = useCallback(
    () => dispatch({ type: "NEXT_PERSON" }),
    [],
  );
  const reset = useCallback(() => dispatch({ type: "RESET" }), []);

  const value = useMemo<PaymentFlowContextValue>(
    () => ({
      state,
      totalAmount,
      paidAmount,
      remainingAmount,
      perPersonAmount,
      isComplete,
      currentPayAmount,
      setTable,
      setOrderCode,
      setOrder,
      setSplitMode,
      setNumberOfPeople,
      setCurrentAmount,
      setItemAllocations,
      setPayAllRemaining,
      setMethod,
      recordPayment,
      refreshOrder,
      newAttempt,
      nextPerson,
      reset,
    }),
    [
      state,
      totalAmount,
      paidAmount,
      remainingAmount,
      perPersonAmount,
      isComplete,
      currentPayAmount,
      setTable,
      setOrderCode,
      setOrder,
      setSplitMode,
      setNumberOfPeople,
      setCurrentAmount,
      setItemAllocations,
      setPayAllRemaining,
      setMethod,
      recordPayment,
      refreshOrder,
      newAttempt,
      nextPerson,
      reset,
    ],
  );

  return (
    <PaymentFlowContext.Provider value={value}>
      {children}
    </PaymentFlowContext.Provider>
  );
}

export function usePaymentFlow(): PaymentFlowContextValue {
  const context = useContext(PaymentFlowContext);
  if (!context) {
    throw new Error("usePaymentFlow must be used within <PaymentFlowProvider>");
  }
  return context;
}
