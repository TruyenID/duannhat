import { CLOUD_URL } from "../base-url-resolver";

export interface PairRequest {
  pairing_code: string;
  /** #935 — device types this app accepts; backend 422s a mismatched code
   *  BEFORE mutating, so the code stays usable by the right app. */
  expected_type?: string;
  device_info: {
    user_agent: string;
    app_version: string;
  };
}

export interface PairResponse {
  device_token: string;
  device: {
    id: string;
    name: string;
    type: string;
    branch_id: string;
    organization_id?: string;
    paired_at?: string;
    branch?: { id: string; name: string };
  };
}

export class PairError extends Error {
  readonly status: number;
  readonly body: unknown;
  constructor(status: number, body: unknown, message: string) {
    super(message);
    this.status = status;
    this.body = body;
  }
}

export async function pairDevice(req: PairRequest): Promise<PairResponse> {
  // Always pair against CLOUD (never workstation LAN — the device-token doesn't
  // exist yet, so workstation can't validate. Pair flow is cloud-only.)
  const res = await fetch(`${CLOUD_URL}/api/v1/devices/pair`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify(req),
  });
  if (!res.ok) {
    const body = (await res.json().catch(() => ({}))) as {
      message?: string;
      errors?: Record<string, string[]>;
    };
    // Prefer the per-field validation message (the expected_type gate puts a
    // localized "this code belongs to a X device" line on pairing_code).
    throw new PairError(
      res.status,
      body,
      body.errors?.pairing_code?.[0] ?? body.message ?? `Pair failed: ${res.status}`,
    );
  }
  const data = (await res.json()) as PairResponse;
  // #935 — success-path guard against an OLD backend that ignored
  // expected_type: never hand back a non-kds device (the KDS surface would
  // 403 on every call and render an empty board).
  if (data.device.type !== "kds") {
    throw new PairError(422, data, `wrong_device_type:${data.device.type}`);
  }
  return data;
}
