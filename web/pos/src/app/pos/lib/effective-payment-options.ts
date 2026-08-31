import type {
  EffectivePaymentOption,
  PaymentMethod,
} from "@/app/pos/types";

/** Split-bill surfaces still consume the legacy PaymentMethod shape. */
export function effectiveOptionToPaymentMethod(
  option: EffectivePaymentOption,
): PaymentMethod {
  const code = option.legacy_payment_method_code ?? option.rail;

  return {
    id: option.legacy_payment_method_id ?? option.id,
    code,
    name: option.display_name,
    type: option.method_type ?? option.rail,
    is_auto_confirm: option.client?.immediate_settlement ?? false,
    requires_tendered: option.client?.requires_tendered ?? false,
    is_active: option.effective,
    sort_order: 0,
    branch_id: null,
    organization_id: "",
    translations: {},
    created_at: null,
    updated_at: null,
    deleted_at: null,
  };
}

export function checkoutCapableOptions(
  options: EffectivePaymentOption[] | undefined,
): EffectivePaymentOption[] {
  // `client?.` guard: an option from a workstation LAN binary that predates the
  // client-block mirror fix has no `client`. Treat a missing block as
  // not-checkout-capable rather than throwing — a POS that shows no payment
  // methods is recoverable; a white-screened POS is not.
  return (options ?? []).filter(
    (option) => option.effective && (option.client?.supports_pos_checkout ?? false),
  );
}

/**
 * What the payment dialog should render for the method grid.
 *
 * Why this exists: "the request failed" and "this shop configured nothing"
 * produced the SAME screen — a red «no checkout payment methods configured»
 * card — because the dialog only ever asked `checkoutOptions.length > 0` and a
 * failed query yields an empty list. A cashier then called a manager to fix a
 * payment policy that was never broken; the real fault was that the terminal
 * was talking to the wrong host (#1511 follow-up). The two states demand
 * opposite actions — retry vs. call a manager — so they are separated here,
 * once, instead of in JSX.
 */
export type PaymentOptionsState =
  | { kind: "loading" }
  | { kind: "error" }
  | { kind: "empty" }
  | { kind: "ready"; options: EffectivePaymentOption[] };

export function paymentOptionsState(input: {
  loading: boolean;
  /** Query error, if the last fetch failed. Anything truthy counts. */
  error?: unknown;
  options: EffectivePaymentOption[] | undefined;
}): PaymentOptionsState {
  if (input.loading) return { kind: "loading" };

  const options = checkoutCapableOptions(input.options);
  // Usable data wins over a failed refetch: a background error mid-shift must
  // not blank a grid the cashier is mid-tap on. TanStack Query keeps serving
  // the last good payload alongside `error`, so this is the cached list.
  if (options.length > 0) return { kind: "ready", options };

  if (input.error) return { kind: "error" };
  return { kind: "empty" };
}
