import { useQuery } from '@tanstack/react-query';
import { fetchEffectivePaymentOptions } from '../lib/api';
import {
  effectiveOptionsToTiles,
  type PaymentOptionTile,
} from '../lib/payment-option-utils';
import type { EffectivePaymentOptionsSnapshot } from '../types/effective-payment-options';
import { paymentKeys } from './query-keys';

export interface UseEffectivePaymentOptionsResult {
  snapshot: EffectivePaymentOptionsSnapshot | undefined;
  tiles: PaymentOptionTile[];
  revision: number;
  isLoading: boolean;
  isError: boolean;
  error: Error | null;
  isEmpty: boolean;
  refetch: () => void;
}

export function useEffectivePaymentOptions(): UseEffectivePaymentOptionsResult {
  const query = useQuery({
    queryKey: paymentKeys.effectiveOptions(),
    queryFn: fetchEffectivePaymentOptions,
    staleTime: 60_000,
  });

  const snapshot = query.data;
  const revision = snapshot?.revision ?? 0;
  const tiles = effectiveOptionsToTiles(snapshot?.options ?? []);

  return {
    snapshot,
    tiles,
    revision,
    isLoading: query.isLoading,
    isError: query.isError,
    error: query.error instanceof Error ? query.error : null,
    isEmpty: !query.isLoading && !query.isError && tiles.length === 0,
    refetch: () => {
      void query.refetch();
    },
  };
}
