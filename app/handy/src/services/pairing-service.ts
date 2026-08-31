export interface DeviceResource {
  id: string;
  name: string;
  type: string;
  status: string;
  branch_id: string;
  branch: { id: string; name: string; slug?: string };
  paired_at: string;
}

export interface PairResponse {
  device_token: string;
  device: DeviceResource;
}

export interface PairError422 {
  message: string;
  errors: { pairing_code: string[] };
}

/**
 * Device types the Handy app can operate as. Sent to the backend as `expected_type`
 * so a pairing code belonging to another device type (kds/tms/kiosk…) is rejected
 * at pair time instead of pairing into an app where every screen 403s. Kept in sync
 * with the backend `device.auth:handy,pos` guard on `/api/v1/handy/*`.
 */
export const HANDY_DEVICE_TYPES = ['handy', 'pos'] as const;

export async function pair(
  pairingCode: string,
  deviceInfo?: { user_agent?: string; app_version?: string },
  expectedType: string = HANDY_DEVICE_TYPES.join(','),
): Promise<PairResponse> {
  const baseUrl = process.env.EXPO_PUBLIC_API_URL ?? '';
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 15_000);

  let response: Response;
  try {
    response = await fetch(`${baseUrl}/api/v1/devices/pair`, {
      method: 'POST',
      signal: controller.signal,
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'Accept-Language': 'ja',
      },
      body: JSON.stringify({ pairing_code: pairingCode, expected_type: expectedType, device_info: deviceInfo }),
    });
  } catch (fetchErr) {
    console.error('[pair] network error', fetchErr);
    throw fetchErr;
  } finally {
    clearTimeout(timer);
  }

  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    console.error('[pair] failed', { status: response.status, body });
    const err = new Error('pair_failed') as Error & { status: number; body: unknown };
    err.status = response.status;
    err.body = body;
    throw err;
  }

  return response.json() as Promise<PairResponse>;
}
