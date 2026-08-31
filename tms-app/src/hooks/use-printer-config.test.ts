import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react-hooks';
import { usePrinterConfig } from './use-printer-config';

// Mock AsyncStorage
vi.mock('@react-native-async-storage/async-storage', () => ({
  default: {
    getItem: vi.fn(),
    setItem: vi.fn(),
  },
}));

import AsyncStorage from '@react-native-async-storage/async-storage';

const mockGetItem = AsyncStorage.getItem as ReturnType<typeof vi.fn>;
const mockSetItem = AsyncStorage.setItem as ReturnType<typeof vi.fn>;

const STORAGE_KEY = 'tms_printer_ip';

beforeEach(() => {
  vi.clearAllMocks();
  mockSetItem.mockResolvedValue(null);
});

// ── Load từ AsyncStorage khi mount ──────────────────────────────────

describe('load on mount', () => {
  it('load IP đã lưu từ AsyncStorage', async () => {
    mockGetItem.mockResolvedValue('192.168.1.232');

    const { result, waitForNextUpdate } = renderHook(() => usePrinterConfig());

    expect(result.current.isLoading).toBe(true);
    await waitForNextUpdate();

    expect(result.current.printerIp).toBe('192.168.1.232');
    expect(result.current.isLoading).toBe(false);
    expect(mockGetItem).toHaveBeenCalledWith(STORAGE_KEY);
  });

  it('printerIp rỗng khi AsyncStorage chưa có gì', async () => {
    mockGetItem.mockResolvedValue(null);

    const { result, waitForNextUpdate } = renderHook(() => usePrinterConfig());

    await waitForNextUpdate();

    expect(result.current.printerIp).toBe('');
    expect(result.current.isLoading).toBe(false);
  });
});

// ── savePrinterIp — IP hợp lệ ───────────────────────────────────────

describe('savePrinterIp — valid IP', () => {
  beforeEach(() => {
    mockGetItem.mockResolvedValue(null);
  });

  it('lưu IP hợp lệ vào AsyncStorage và cập nhật state', async () => {
    const { result, waitForNextUpdate } = renderHook(() => usePrinterConfig());
    await waitForNextUpdate();

    await act(async () => {
      await result.current.savePrinterIp('192.168.1.232');
    });

    expect(mockSetItem).toHaveBeenCalledWith(STORAGE_KEY, '192.168.1.232');
    expect(result.current.printerIp).toBe('192.168.1.232');
  });

  it('trim whitespace trước khi lưu', async () => {
    const { result, waitForNextUpdate } = renderHook(() => usePrinterConfig());
    await waitForNextUpdate();

    await act(async () => {
      await result.current.savePrinterIp('  10.0.0.1  ');
    });

    expect(mockSetItem).toHaveBeenCalledWith(STORAGE_KEY, '10.0.0.1');
    expect(result.current.printerIp).toBe('10.0.0.1');
  });

  it('chấp nhận các IP biên hợp lệ: 0.0.0.0 và 255.255.255.255', async () => {
    const { result, waitForNextUpdate } = renderHook(() => usePrinterConfig());
    await waitForNextUpdate();

    await act(async () => {
      await result.current.savePrinterIp('0.0.0.0');
    });
    expect(result.current.printerIp).toBe('0.0.0.0');

    await act(async () => {
      await result.current.savePrinterIp('255.255.255.255');
    });
    expect(result.current.printerIp).toBe('255.255.255.255');
  });
});

// ── savePrinterIp — IP không hợp lệ ────────────────────────────────

describe('savePrinterIp — invalid IP', () => {
  beforeEach(() => {
    mockGetItem.mockResolvedValue(null);
  });

  const invalidCases = [
    ['chuỗi rỗng', ''],
    ['chỉ có chữ', 'abc'],
    ['thiếu octet', '192.168.1'],
    ['octet vượt 255', '999.0.0.1'],
    ['octet âm', '-1.0.0.1'],
    ['có khoảng trắng giữa', '192.168. 1.1'],
    ['5 octet', '192.168.1.1.1'],
  ];

  it.each(invalidCases)('%s → throw invalid_ip', async (_label, ip) => {
    const { result, waitForNextUpdate } = renderHook(() => usePrinterConfig());
    await waitForNextUpdate();

    await expect(
      act(async () => {
        await result.current.savePrinterIp(ip);
      }),
    ).rejects.toThrow('invalid_ip');

    expect(mockSetItem).not.toHaveBeenCalled();
    expect(result.current.printerIp).toBe('');
  });
});
