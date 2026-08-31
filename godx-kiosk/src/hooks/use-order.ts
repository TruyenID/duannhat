// src/hooks/use-order.ts
import { useQuery } from '@tanstack/react-query';
import { fetchOrderByCode, fetchOrderByTable } from '../lib/api';
import { orderKeys } from './query-keys';

export function useOrder(tableId: string) {
  const { data, isLoading, error } = useQuery({
    queryKey: orderKeys.byTable(tableId),
    queryFn: () => fetchOrderByTable(tableId),
    enabled: tableId.length > 0,
  });

  return {
    order: data ?? null,
    isLoading,
    error: error instanceof Error ? error.message : error ? String(error) : null,
  };
}

export function useOrderByCode(orderCode: string) {
  const { data, isLoading, error } = useQuery({
    queryKey: orderKeys.byCode(orderCode),
    queryFn: () => fetchOrderByCode(orderCode),
    enabled: orderCode.length > 0,
  });

  return {
    order: data ?? null,
    isLoading,
    error: error instanceof Error ? error.message : error ? String(error) : null,
  };
}
