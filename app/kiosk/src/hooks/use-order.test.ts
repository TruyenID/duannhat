// src/hooks/use-order.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createElement, act, useRef } from 'react';
import { createRoot } from 'react-dom/client';
import { useOrder } from './use-order';

vi.mock('../lib/api', () => ({
  fetchOrderByTable: vi.fn(),
  fetchOrderByCode: vi.fn(),
}));
import { fetchOrderByTable } from '../lib/api';
const mockByTable = vi.mocked(fetchOrderByTable);

function renderHook<T>(
  hookFn: () => T,
  options?: { wrapper?: React.ComponentType<{ children: React.ReactNode }> },
): { result: { current: T } } {
  const result: { current: T } = { current: undefined as unknown as T };

  const container = document.createElement('div');
  document.body.appendChild(container);

  const Wrapper = options?.wrapper;

  function HookHarness() {
    const ref = useRef<T>(undefined as unknown as T);
    ref.current = hookFn();
    result.current = ref.current;
    return null;
  }

  const root = createRoot(container);
  act(() => {
    const element = Wrapper
      ? createElement(Wrapper, { children: createElement(HookHarness) })
      : createElement(HookHarness);
    root.render(element);
  });

  return { result };
}

async function waitFor(assertion: () => void, timeout = 3000): Promise<void> {
  const start = Date.now();
  while (true) {
    try {
      await act(async () => {});
      assertion();
      return;
    } catch (err) {
      if (Date.now() - start > timeout) throw err;
      await new Promise((r) => setTimeout(r, 20));
    }
  }
}

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return createElement(QueryClientProvider, { client: qc }, children);
}

const mockOrder = {
  id: 'order-1', table_id: 'table-3', table_name: 'Bàn 03',
  items: [{ id: 'item-1', name: 'Phở bò', quantity: 2, unit_price: 50000 }],
  subtotal: 100000, discount: 0, total: 100000, currency: 'VND',
};

describe('useOrder', () => {
  beforeEach(() => vi.clearAllMocks());

  it('returns order data when fetch succeeds', async () => {
    mockByTable.mockResolvedValueOnce(mockOrder);
    const { result } = renderHook(() => useOrder('table-3'), { wrapper });
    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(result.current.order?.id).toBe('order-1');
    expect(result.current.error).toBeNull();
  });

  it('returns error when fetch fails', async () => {
    mockByTable.mockRejectedValueOnce(new Error('Network error'));
    const { result } = renderHook(() => useOrder('table-3'), { wrapper });
    await waitFor(() => expect(result.current.isLoading).toBe(false));
    expect(result.current.order).toBeNull();
    expect(result.current.error).toBe('Network error');
  });

  it('does not fetch when tableId is empty', () => {
    const { result } = renderHook(() => useOrder(''), { wrapper });
    expect(result.current.isLoading).toBe(false);
    expect(mockByTable).not.toHaveBeenCalled();
  });
});
