// src/lib/api.test.ts
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('./device-token', () => ({
  getDeviceToken: vi.fn(),
  setDeviceToken: vi.fn(),
  clearDeviceToken: vi.fn(),
}));

vi.mock('@react-native-async-storage/async-storage', () => ({
  default: { getItem: vi.fn().mockResolvedValue('ja') },
}));

// react-native's index.js contains Flow syntax that vitest's parser rejects.
// Stub the surface area api.ts uses (Platform.OS, Platform.Version).
vi.mock('react-native', () => ({
  Platform: { OS: 'ios', Version: '17.0' },
}));

vi.mock('expo-application', () => ({
  getIosIdForVendorAsync: vi.fn().mockResolvedValue('test-vendor-id'),
  getAndroidId: vi.fn().mockReturnValue('test-android-id'),
  nativeApplicationVersion: '1.0.0',
  nativeBuildVersion: '1',
}));

vi.mock('expo-device', () => ({
  modelName: 'Test Device',
  brand: 'Test',
  deviceName: 'Test Kiosk',
}));

import {
  ApiError,
  apiFetch,
  resolveQrCode,
  setDeviceToken,
  setUnauthorizedHandler,
} from './api';
import { getDeviceToken, clearDeviceToken } from './device-token';

const mockGetToken = vi.mocked(getDeviceToken);
const mockClearToken = vi.mocked(clearDeviceToken);

function mockFetchResponse(status: number, body: Record<string, unknown> = {}): void {
  globalThis.fetch = vi.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    json: vi.fn().mockResolvedValue(body),
  }) as unknown as typeof fetch;
}

describe('ApiError classification', () => {
  it('isAuthError is true only for 401', () => {
    expect(new ApiError(401, {}).isAuthError).toBe(true);
    expect(new ApiError(403, {}).isAuthError).toBe(false);
    expect(new ApiError(422, {}).isAuthError).toBe(false);
    expect(new ApiError(500, {}).isAuthError).toBe(false);
  });

  it('isForbidden is true only for 403', () => {
    expect(new ApiError(403, {}).isForbidden).toBe(true);
    expect(new ApiError(401, {}).isForbidden).toBe(false);
  });

  it('isValidationError is true only for 422', () => {
    expect(new ApiError(422, {}).isValidationError).toBe(true);
    expect(new ApiError(400, {}).isValidationError).toBe(false);
  });

  it('isServerError is true for 5xx', () => {
    expect(new ApiError(500, {}).isServerError).toBe(true);
    expect(new ApiError(503, {}).isServerError).toBe(true);
    expect(new ApiError(499, {}).isServerError).toBe(false);
  });
});

describe('resolveQrCode — decode scanned token', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mockGetToken.mockResolvedValue(null);
  });

  it('hits the customer qr endpoint with the url-encoded token', async () => {
    mockFetchResponse(200, { data: { type: 'table', table: { id: 't1', code: 'T-01' } } });

    await resolveQrCode('ab/cd 12');

    const url = vi.mocked(globalThis.fetch).mock.calls[0]?.[0] as string;
    expect(url).toContain('/api/v1/customer/qr/ab%2Fcd%2012');
  });

  it('maps a table token to the table flow (name → table_name)', async () => {
    mockFetchResponse(200, {
      data: { type: 'table', table: { id: 't1', code: 'T-01', name: 'Cửa sổ 1' } },
    });

    const res = await resolveQrCode('tok-table');

    expect(res).toEqual({ type: 'table', table_id: 't1', table_name: 'Cửa sổ 1' });
  });

  it('falls back to table code when name is null', async () => {
    mockFetchResponse(200, {
      data: { type: 'table', table: { id: 't1', code: 'T-01', name: null } },
    });

    const res = await resolveQrCode('tok-table');

    expect(res).toEqual({ type: 'table', table_id: 't1', table_name: 'T-01' });
  });

  it('maps an order token to the order flow', async () => {
    mockFetchResponse(200, {
      data: { type: 'order', order: { id: 'o1', code: 'ORD-2026-1234' } },
    });

    const res = await resolveQrCode('tok-order');

    expect(res).toEqual({ type: 'order', order_code: 'ORD-2026-1234', order_id: 'o1' });
  });

  it('throws ApiError when the token is unknown (404)', async () => {
    mockFetchResponse(404, { message: 'Not found' });

    await expect(resolveQrCode('bad-token')).rejects.toBeInstanceOf(ApiError);
  });
});

describe('resolveQrCode — pins to Cloud even when a workstation is active', () => {
  beforeEach(() => {
    vi.resetModules();
  });

  it('bypasses the workstation proxy (forceCloud) so it never hits the SPA fallback', async () => {
    vi.doMock('./device-token', () => ({
      getDeviceToken: vi.fn().mockResolvedValue(null),
      setDeviceToken: vi.fn(),
      clearDeviceToken: vi.fn(),
    }));
    vi.doMock('@react-native-async-storage/async-storage', () => ({
      default: { getItem: vi.fn().mockResolvedValue('ja') },
    }));
    vi.doMock('../services/workstation/base-url-resolver', () => ({
      CLOUD_URL: 'http://cloud',
      // A workstation IS discovered — without forceCloud this would be the base.
      resolveBaseUrl: () => 'http://workstation.lan',
      markWorkstationUnreachable: vi.fn(),
    }));

    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ data: { type: 'order', order: { id: 'o1', code: 'ORD-1' } } }),
    }) as never;

    const { resolveQrCode } = await import('./api');
    await resolveQrCode('tok');

    const url = vi.mocked(global.fetch).mock.calls[0]?.[0] as string;
    expect(url).toBe('http://cloud/api/v1/customer/qr/tok');
  });
});

describe('apiFetch — 401 handling', () => {
  beforeEach(async () => {
    vi.clearAllMocks();
    mockGetToken.mockResolvedValue('valid-token-at-request-time');
    // Reset the module-level unauthorized guard between tests by simulating
    // a re-pairing. Without this, the first 401 in a test would set the
    // guard and subsequent tests would no-op the clear/handler chain.
    await setDeviceToken('valid-token-at-request-time');
  });

  afterEach(() => {
    setUnauthorizedHandler(null);
  });

  it('clears device token and notifies handler when response is 401', async () => {
    mockFetchResponse(401, { message: 'Invalid device token.' });
    const handler = vi.fn();
    setUnauthorizedHandler(handler);

    await expect(apiFetch('/api/v1/kiosk/me')).rejects.toBeInstanceOf(ApiError);

    expect(mockClearToken).toHaveBeenCalledTimes(1);
    expect(handler).toHaveBeenCalledTimes(1);
  });

  it('does NOT trigger handler on 403 (wrong scope, not expired token)', async () => {
    mockFetchResponse(403, { message: 'Device type not allowed for this endpoint.' });
    const handler = vi.fn();
    setUnauthorizedHandler(handler);

    await expect(apiFetch('/api/v1/kiosk/me')).rejects.toBeInstanceOf(ApiError);

    expect(mockClearToken).not.toHaveBeenCalled();
    expect(handler).not.toHaveBeenCalled();
  });

  it('does NOT trigger handler on 422 validation error', async () => {
    mockFetchResponse(422, { message: 'Validation failed' });
    const handler = vi.fn();
    setUnauthorizedHandler(handler);

    await expect(apiFetch('/api/v1/kiosk/payments', { method: 'POST' })).rejects.toBeInstanceOf(
      ApiError,
    );

    expect(mockClearToken).not.toHaveBeenCalled();
    expect(handler).not.toHaveBeenCalled();
  });

  it('still throws ApiError after 401 cleanup so caller can react', async () => {
    mockFetchResponse(401, { message: 'Invalid device token.' });
    setUnauthorizedHandler(vi.fn());

    let caught: unknown;
    try {
      await apiFetch('/api/v1/kiosk/orders');
    } catch (e) {
      caught = e;
    }

    expect(caught).toBeInstanceOf(ApiError);
    expect((caught as ApiError).status).toBe(401);
    expect((caught as ApiError).isAuthError).toBe(true);
  });

  it('tolerates a null handler (no crash when none registered)', async () => {
    mockFetchResponse(401);
    setUnauthorizedHandler(null);

    await expect(apiFetch('/api/v1/kiosk/me')).rejects.toBeInstanceOf(ApiError);
    expect(mockClearToken).toHaveBeenCalledTimes(1);
  });

  it('successful 2xx response does not invoke handler or clear token', async () => {
    mockFetchResponse(200, { data: { id: 'dev-1' } });
    const handler = vi.fn();
    setUnauthorizedHandler(handler);

    const result = await apiFetch<{ data: { id: string } }>('/api/v1/kiosk/me');
    expect(result.data.id).toBe('dev-1');
    expect(mockClearToken).not.toHaveBeenCalled();
    expect(handler).not.toHaveBeenCalled();
  });
});

describe('apiFetch — 401 handling (C-2)', () => {
  let mockClear: ReturnType<typeof vi.fn>;
  let mockHandler: (() => void) & { mock: { calls: unknown[][] } };

  beforeEach(async () => {
    vi.resetModules();
    mockClear = vi.fn().mockResolvedValue(undefined);
    mockHandler = vi.fn() as unknown as typeof mockHandler;

    vi.doMock('./device-token', () => ({
      getDeviceToken: vi.fn().mockResolvedValue('token-x'),
      setDeviceToken: vi.fn().mockResolvedValue(undefined),
      clearDeviceToken: mockClear,
    }));

    vi.doMock('@react-native-async-storage/async-storage', () => ({
      default: { getItem: vi.fn().mockResolvedValue('ja'), setItem: vi.fn() },
    }));

    vi.doMock('../services/workstation/base-url-resolver', () => ({
      CLOUD_URL: 'http://test',
      resolveBaseUrl: () => 'http://test',
      markWorkstationUnreachable: vi.fn(),
    }));

    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 401,
      json: async () => ({ message: 'Unauthorized' }),
    }) as never;
  });

  it('clearDeviceToken runs at most once across concurrent 401s', async () => {
    const { apiFetch, setUnauthorizedHandler } = await import('./api');
    setUnauthorizedHandler(mockHandler);

    await Promise.all([
      apiFetch('/a').catch(() => {}),
      apiFetch('/b').catch(() => {}),
      apiFetch('/c').catch(() => {}),
    ]);

    expect(mockClear).toHaveBeenCalledTimes(1);
    expect(mockHandler).toHaveBeenCalled();
  });

  it('suppressUnauthorizedHandler: true skips clear + handler', async () => {
    const { apiFetch, setUnauthorizedHandler } = await import('./api');
    setUnauthorizedHandler(mockHandler);

    await apiFetch('/audit-logs', {
      method: 'POST',
      suppressUnauthorizedHandler: true,
    } as never).catch(() => {});

    expect(mockClear).not.toHaveBeenCalled();
    expect(mockHandler).not.toHaveBeenCalled();
  });

  it('setDeviceToken re-enables clear (resets the guard)', async () => {
    const api = await import('./api');
    api.setUnauthorizedHandler(mockHandler);

    await api.apiFetch('/a').catch(() => {});
    expect(mockClear).toHaveBeenCalledTimes(1);

    // Simulate re-pairing through the public api re-export
    await api.setDeviceToken('new-token');

    await api.apiFetch('/b').catch(() => {});
    expect(mockClear).toHaveBeenCalledTimes(2);
  });
});
