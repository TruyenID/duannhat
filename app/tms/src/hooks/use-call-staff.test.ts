import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useClearCall } from './use-call-staff';

// Mock apiFetch
vi.mock('@/lib/api', () => ({
  apiFetch: vi.fn(),
}));

// Mock react-query
const mockInvalidateQueries = vi.fn();
vi.mock('@tanstack/react-query', () => ({
  useMutation: vi.fn(({ mutationFn, onSuccess }) => ({
    mutate: async (tableId: string) => {
      const result = await mutationFn(tableId);
      onSuccess?.(result);
      return result;
    },
    isPending: false,
  })),
  useQueryClient: () => ({
    invalidateQueries: mockInvalidateQueries,
  }),
}));

import { apiFetch } from '@/lib/api';
import { zoneKeys } from './query-keys';

const mockApiFetch = apiFetch as ReturnType<typeof vi.fn>;

beforeEach(() => {
  vi.clearAllMocks();
});

describe('useClearCall', () => {
  it('gọi DELETE /api/v1/tms/tables/{id}/call với table id đúng', async () => {
    mockApiFetch.mockResolvedValue({ data: { id: 'table-1', call_requested_at: null } });

    const { result } = renderHook(() => useClearCall());

    await act(async () => {
      await result.current.mutate('table-1');
    });

    expect(mockApiFetch).toHaveBeenCalledWith('/api/v1/tms/tables/table-1/call', {
      method: 'DELETE',
    });
  });

  it('invalidate zoneKeys.list sau khi clearCall thành công', async () => {
    mockApiFetch.mockResolvedValue({ data: { id: 'table-1', call_requested_at: null } });

    const { result } = renderHook(() => useClearCall());

    await act(async () => {
      await result.current.mutate('table-1');
    });

    expect(mockInvalidateQueries).toHaveBeenCalledWith({
      queryKey: zoneKeys.list('_'),
    });
  });

  it('không invalidate nếu API lỗi', async () => {
    mockApiFetch.mockRejectedValue(new Error('Network error'));

    const { result } = renderHook(() => useClearCall());

    await act(async () => {
      try {
        await result.current.mutate('table-1');
      } catch {
        // expected
      }
    });

    expect(mockInvalidateQueries).not.toHaveBeenCalled();
  });
});
