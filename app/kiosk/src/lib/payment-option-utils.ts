import type { PaymentMethod, PaymentPayload } from '../types/kiosk';
import type {
  EffectivePaymentOptionRow,
  SelectedPaymentOption,
} from '../types/effective-payment-options';

const RAIL_ROUTE: Record<string, PaymentMethod | null> = {
  cash: 'cash',
  card: 'card',
  qr: 'qr',
  wallet: 'qr',
  e_money: 'emoney',
  bank_transfer: 'qr',
};

const RAIL_ICON: Record<string, string> = {
  cash: '💴',
  card: '💳',
  qr: '📱',
  wallet: '📱',
  e_money: '📲',
  bank_transfer: '🏦',
};

/** Legacy PaymentMethod.code the backend resolver still accepts (T6.2 bridge). */
const RAIL_LEGACY_METHOD: Record<string, string> = {
  cash: 'cash',
  card: 'card',
  qr: 'qr',
  wallet: 'transfer',
  e_money: 'e_wallet',
  bank_transfer: 'transfer',
};

const ROUTE_LABEL_KEY: Record<PaymentMethod, string> = {
  cash: 'kiosk.payment_method.cash',
  card: 'kiosk.payment_method.card',
  qr: 'kiosk.payment_method.qr',
  emoney: 'kiosk.payment_method.emoney',
};

const ROUTE_SUB_KEY: Record<PaymentMethod, string> = {
  cash: 'kiosk.payment_method.cash_sub',
  card: 'kiosk.payment_method.card_sub',
  qr: 'kiosk.payment_method.qr_sub',
  emoney: 'kiosk.payment_method.emoney_sub',
};

export interface PaymentOptionTile {
  option: EffectivePaymentOptionRow;
  route: PaymentMethod;
  icon: string;
  labelKey: string;
  subKey: string;
  /** Prefer API display_name; labelKey is fallback for known rails. */
  displayName: string;
}

export function routeForRail(rail: string): PaymentMethod | null {
  return RAIL_ROUTE[rail] ?? null;
}

export function legacyMethodForRail(rail: string): string {
  return RAIL_LEGACY_METHOD[rail] ?? rail;
}

export function effectiveOptionsToTiles(
  options: EffectivePaymentOptionRow[],
): PaymentOptionTile[] {
  return options
    .filter((row) => row.effective)
    .map((row) => {
      const route = routeForRail(row.rail);
      if (!route) return null;
      return {
        option: row,
        route,
        icon: RAIL_ICON[row.rail] ?? '💳',
        labelKey: ROUTE_LABEL_KEY[route],
        subKey: ROUTE_SUB_KEY[route],
        displayName: row.display_name,
      };
    })
    .filter((tile): tile is PaymentOptionTile => tile !== null);
}

export function toSelectedPaymentOption(
  row: EffectivePaymentOptionRow,
  policyRevision: number,
): SelectedPaymentOption | null {
  const route = routeForRail(row.rail);
  if (!route) return null;
  return {
    optionId: row.id,
    connectionId: row.connection_id,
    connectionOptionId: row.connection_option_id,
    policyRevision,
    rail: row.rail,
    route,
    displayName: row.display_name,
    provider: row.provider,
  };
}

export function buildPaymentPayload(
  base: Omit<PaymentPayload, 'method' | 'option_id' | 'policy_revision' | 'connection_id' | 'connection_option_id'>,
  selected: SelectedPaymentOption,
): PaymentPayload {
  return {
    ...base,
    method: selected.route,
    option_id: selected.optionId,
    policy_revision: selected.policyRevision,
    connection_id: selected.connectionId ?? undefined,
    connection_option_id: selected.connectionOptionId ?? undefined,
  };
}

/** Block deep-link to a payment screen when that route is not in the effective set. */
export function isRouteAllowed(
  route: PaymentMethod,
  tiles: PaymentOptionTile[],
): boolean {
  return tiles.some((tile) => tile.route === route);
}
