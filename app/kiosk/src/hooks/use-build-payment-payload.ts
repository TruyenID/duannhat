import { useCallback } from 'react';
import { usePaymentFlow } from '../providers/payment-flow-provider';
import { buildPaymentPayload } from '../lib/payment-option-utils';
import type { PaymentPayload } from '../types/kiosk';

type PaymentPayloadBase = Omit<
  PaymentPayload,
  'method' | 'option_id' | 'policy_revision' | 'connection_id' | 'connection_option_id'
>;

export function useBuildPaymentPayload() {
  const { state } = usePaymentFlow();

  return useCallback(
    (base: PaymentPayloadBase): PaymentPayload => {
      if (!state.selectedOption) {
        throw new Error('No effective payment option selected');
      }
      return buildPaymentPayload(base, state.selectedOption);
    },
    [state.selectedOption],
  );
}
