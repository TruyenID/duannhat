// src/hooks/use-split-preview.ts
import { useQuery, keepPreviousData } from '@tanstack/react-query';
import { fetchSplitByItemsPreview } from '../lib/api';
import { paymentKeys } from './query-keys';
import type { ItemAllocation, SplitByItemsPreview } from '../types/kiosk';

/**
 * Stable hash of a candidate allocation so the query key changes only when the
 * selection actually changes (order-independent).
 */
function hashAllocations(allocations: ItemAllocation[]): string {
  return allocations
    .filter((a) => a.units > 0)
    .map((a) => `${a.item_id}:${a.units}`)
    .sort()
    .join('|');
}

/**
 * By-items split preview. Re-fetches whenever the candidate selection changes,
 * keeping the previous result on screen so the running total doesn't flicker.
 *
 * `staleTime: 0` + `refetchOnMount` so re-entering the picker after a committed
 * payment reflects the freshly-claimed units (`units_remaining`).
 */
export function useSplitByItemsPreview(
  orderId: string,
  allocations: ItemAllocation[],
  enabled = true,
) {
  const selected = allocations.filter((a) => a.units > 0);

  const query = useQuery<SplitByItemsPreview>({
    queryKey: paymentKeys.splitPreview(orderId, hashAllocations(allocations)),
    queryFn: () => fetchSplitByItemsPreview(orderId, selected),
    enabled: enabled && orderId.length > 0,
    staleTime: 0,
    refetchOnMount: 'always',
    placeholderData: keepPreviousData,
  });

  return {
    preview: query.data ?? null,
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    error: query.error instanceof Error ? query.error.message : null,
  };
}
