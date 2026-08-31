/**
 * REST API client for ws-app.
 * Replaces all `window.go?.app?.Service?.*` Wails bindings with fetch calls.
 *
 * Base URL defaults to the current origin (works when frontend is served by
 * the Go server). Override with VITE_API_BASE_URL env var if needed.
 */

const BASE_URL =
  (import.meta as any).env?.VITE_API_BASE_URL?.replace(/\/+$/, "") ||
  // Default to the page's own origin so the UI works both natively
  // (webview at http://localhost:8080) and when served through a tunnel /
  // reverse proxy. Falls back to localhost for non-browser (SSR/test) contexts.
  (typeof window !== "undefined" ? window.location.origin : "http://localhost:8080");

// ─── Helpers ───────────────────────────────────────────────────────────

// ApiError carries the HTTP status + parsed body so callers can branch on a
// specific response (e.g. the 409 unpair guard, whose body holds the unsynced
// counts + amount). It still extends Error and sets `.message`, so every
// existing `err.message` / `instanceof Error` / `String(err)` call site keeps
// working unchanged.
export class ApiError extends Error {
  status: number;
  data: any;
  constructor(status: number, message: string, data: any) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.data = data;
  }
}

async function request<T>(
  method: string,
  path: string,
  body?: unknown,
): Promise<T> {
  const url = `${BASE_URL}${path}`;
  const opts: RequestInit = {
    method,
    headers: { "Content-Type": "application/json" },
  };
  if (body !== undefined) {
    opts.body = JSON.stringify(body);
  }
  const res = await fetch(url, opts);
  if (!res.ok) {
    const data = await res.json().catch(() => ({ error: res.statusText }));
    throw new ApiError(res.status, data.message ?? data.error ?? res.statusText, data);
  }
  return res.json() as Promise<T>;
}

function get<T>(path: string): Promise<T> {
  return request<T>("GET", path);
}

function post<T>(path: string, body?: unknown): Promise<T> {
  return request<T>("POST", path, body);
}

function put<T>(path: string, body?: unknown): Promise<T> {
  return request<T>("PUT", path, body);
}

function del<T>(path: string): Promise<T> {
  return request<T>("DELETE", path);
}

// ─── Types ─────────────────────────────────────────────────────────────

export interface DashboardStats {
  active_orders: number;
  today_orders: number;
  today_revenue: number;
  device_count: number;
  online_devices: number;
  sync_status: string;
}

export interface LANInfo {
  ip: string;
  port: number;
  url: string;
  ws_clients: number;
}

// Aligned with cloud customer_orders (Sprint 4).
export interface Order {
  id: string;
  order_code: string;
  order_number?: number;
  order_type: string;
  status: string;
  table_id?: string;
  table_number?: string;
  guest_count: number;
  note?: string;
  total_amount: number;
  subtotal: number;
  tax_amount: number;
  paid_amount: number;
  items: OrderItem[];
  opened_at: string;
  created_at: string;
}

export interface OrderItem {
  id: string;
  menu_item_name: string;
  quantity: number;
  unit_price: number;
  subtotal?: number;
  note?: string;
  status: string;
}

// Matches service.CreateOrderInput on the Go side.
export interface CreateOrderInput {
  table_id?: string;
  order_type: string;
  guest_count: number;
  note?: string;
  items: { menu_item_id?: string; product_sku_id?: string; quantity: number; note?: string }[];
}

export interface MenuItem {
  id: string;
  name: string;
  name_ja: string;
  category: string;
  price: number;
  printer_group: string;
  is_active: boolean;
}

export type PrinterRole =
  | "kitchen_printer"
  | "hold_printer"
  | "bar_printer"
  | "receipt_printer";

export interface DeviceInfo {
  id: string;
  type: string;
  roles: PrinterRole[];
  name: string;
  connection_type: string;
  address: string;
  status: string;
  /** 'cloud' (synced from admin-web) or 'local' (added on this workstation). */
  origin: string;
}

export interface AddPrinterInput {
  name: string;
  roles: PrinterRole[];
  connection_type: string;
  address: string;
  paper_width: number;
}

export interface SyncInfo {
  status: string;
  pending_count: number;
  failed_count: number;
  // plan-042 — dead-letter surface + rate-limit state.
  dead_letter_count: number;
  payment_orphan_count: number;
  dead_letters: DeadLetterItem[];
  throttled: boolean;
  cooldown_until: string | null;
  history: QueueItem[];
  trace: SyncTraceEvent[];
}

// DeadLetterItem mirrors internal/service.DeadLetterItem — one unresolved
// dead-lettered sync row shown on the recovery page. plan-042.
export interface DeadLetterItem {
  id: number;
  entity_type: string;
  entity_id: string;
  operation: string;
  dead_lettered_at: string;
  dead_letter_reason: string;
  last_error?: string;
  created_at: string;
  is_payment: boolean;
}

// SyncTraceEvent mirrors internal/service.SyncTraceEvent — one entry in the
// live sync activity feed across all flows.
export interface SyncTraceEvent {
  seq: number;
  at: string;
  flow: "up" | "down" | "kds" | "conn" | "lan";
  phase: string;
  trace_id: string;
  entity_type: string;
  operation: string;
  entity_id: string;
  status: "ok" | "error" | "skip" | "retry";
  latency_ms?: number;
  attempt?: number;
  count?: number;
  status_code?: number;
  error?: string;
}

export interface QueueItem {
  id: number;
  entity_type: string;
  entity_id: string;
  operation: string;
  attempts: number;
  last_error: string;
  created_at: string;
  synced_at: string;
}

export interface DailyReport {
  date: string;
  total_orders: number;
  paid_orders: number;
  cancelled_orders: number;
  total_revenue: number;
  avg_order_value: number;
}

export interface PopularItem {
  name: string;
  category: string;
  quantity: number;
  revenue: number;
}

export interface AppConfig {
  store_name: string;
  store_address: string;
  // plan-043 (T3.7) — `tax_rate` was removed from the machine-local config. The
  // consumption-tax rate is the SYNCED per-branch tax_rate + per-line tax_types
  // snapshots pulled from Cloud, not a value edited in the desktop Settings box.
  server_port: number;
}

// ─── Dashboard ─────────────────────────────────────────────────────────

export async function getDashboardStats(): Promise<DashboardStats> {
  return get<DashboardStats>("/api/dashboard/stats");
}

export async function getLANInfo(): Promise<LANInfo> {
  return get<LANInfo>("/api/lan");
}

// ─── Orders ────────────────────────────────────────────────────────────

export async function listActiveOrders(): Promise<Order[]> {
  const data = await get<{ orders: Order[] }>("/api/orders");
  return data.orders ?? [];
}

// Recently paid/closed bills — kiosk/customer orders confirmed in Cloud arrive
// already closed via pull-down, so they never appear on the active board.
export async function listPaidOrders(): Promise<Order[]> {
  const data = await get<{ orders: Order[] }>("/api/orders?status=closed");
  return data.orders ?? [];
}

export async function getOrder(id: string): Promise<Order> {
  const data = await get<{ order: Order }>(`/api/orders/${id}`);
  return data.order;
}

export async function createOrder(input: CreateOrderInput): Promise<Order> {
  const data = await post<{ order: Order }>("/api/orders", input);
  return data.order;
}

export async function updateOrderStatus(
  id: string,
  status: string,
): Promise<Order> {
  const data = await put<{ order: Order }>(`/api/orders/${id}`, { status });
  return data.order;
}

export async function addOrderItems(
  orderId: string,
  items: { menu_item_id: string; quantity: number; notes: string }[],
): Promise<Order> {
  const data = await put<{ order: Order }>(`/api/orders/${orderId}`, {
    add_items: items,
  });
  return data.order;
}

export async function recordPayment(
  orderId: string,
  method: string = "cash",
): Promise<void> {
  await post(`/api/orders/${orderId}/payment`, { method });
}

export async function printOrder(
  orderId: string,
  type: string,
): Promise<{ status: string; errors?: string[] }> {
  return post(`/api/orders/${orderId}/print`, { type });
}

// ─── Menu ──────────────────────────────────────────────────────────────

export async function listMenuItems(): Promise<MenuItem[]> {
  const data = await get<{ items: MenuItem[] }>("/api/menu");
  return data.items ?? [];
}

export async function createMenuItem(
  name: string,
  nameJa: string,
  category: string,
  price: number,
  printerGroup: string,
): Promise<MenuItem> {
  const data = await post<{ item: MenuItem }>("/api/menu", {
    name,
    name_ja: nameJa,
    category,
    price,
    printer_group: printerGroup,
  });
  return data.item;
}

export async function updateMenuItem(
  id: string,
  name: string,
  nameJa: string,
  category: string,
  price: number,
  printerGroup: string,
): Promise<void> {
  await put(`/api/menu/${id}`, {
    name,
    name_ja: nameJa,
    category,
    price,
    printer_group: printerGroup,
  });
}

export async function deleteMenuItem(id: string): Promise<void> {
  await del(`/api/menu/${id}`);
}

export async function seedDemoMenu(): Promise<void> {
  await post("/api/menu/seed");
}

// ─── Devices ───────────────────────────────────────────────────────────

export interface DeviceList {
  devices: DeviceInfo[];
  missing_roles: PrinterRole[];
}

export async function listDevices(): Promise<DeviceList> {
  const data = await get<{ devices: DeviceInfo[]; missing_roles: PrinterRole[] }>(
    "/api/devices",
  );
  return {
    devices: data.devices ?? [],
    missing_roles: data.missing_roles ?? [],
  };
}

export async function addPrinter(input: AddPrinterInput): Promise<DeviceInfo> {
  const data = await post<{ device: DeviceInfo }>("/api/devices", input);
  return data.device;
}

export async function removeDevice(id: string): Promise<void> {
  await del(`/api/devices/${id}`);
}

/**
 * Replace which roles an existing printer answers for. Lets a shop give one
 * device an extra role (e.g. the kitchen printer also serving `bar_printer`
 * when there is no separate bar station) without delete + re-add.
 */
export async function updateDeviceRoles(
  id: string,
  roles: PrinterRole[],
): Promise<{ id: string; roles: PrinterRole[] }> {
  return request<{ id: string; roles: PrinterRole[] }>(
    "PATCH",
    `/api/devices/${id}/roles`,
    { roles },
  );
}

export async function testPrinter(id: string): Promise<void> {
  await post(`/api/devices/${id}/test`);
}

// ─── Sync ──────────────────────────────────────────────────────────────

export async function getSyncInfo(): Promise<SyncInfo> {
  return get<SyncInfo>("/api/sync");
}

export async function retrySyncFailed(): Promise<{ count: number }> {
  return post<{ count: number }>("/api/sync/retry");
}

// plan-042 recovery actions.
export async function discardSync(id: number): Promise<{ id: number; resolution: string }> {
  return post<{ id: number; resolution: string }>(`/api/sync/${id}/discard`);
}

export async function reResolveSync(id: number): Promise<{ id: number; resolution: string }> {
  return post<{ id: number; resolution: string }>(`/api/sync/${id}/re-resolve`);
}

export async function recoverOrderSync(orderId: string): Promise<{ order_id: string; resolution: string }> {
  return post<{ order_id: string; resolution: string }>(
    `/api/sync/orders/${encodeURIComponent(orderId)}/recover`,
  );
}

// ─── Reports ───────────────────────────────────────────────────────────

export async function getDailyReport(date: string): Promise<DailyReport> {
  return get<DailyReport>(`/api/reports/daily?date=${encodeURIComponent(date)}`);
}

export async function getPopularItems(
  date: string,
  limit: number = 10,
): Promise<PopularItem[]> {
  const data = await get<{ items: PopularItem[] }>(
    `/api/reports/popular?date=${encodeURIComponent(date)}&limit=${limit}`,
  );
  return data.items ?? [];
}

// ─── Device Auth ──────────────────────────────────────────────────────

export interface DeviceStatus {
  paired: boolean;
  device_name: string;
  device_type: string;
  needs_repair?: boolean; // cloud revoked token mid-session → re-pair needed (#437)
  token?: string; // only present when paired (localOnly endpoint)
}

export interface PairDeviceResponse {
  device_token: string;
  device: {
    id: string;
    name: string;
    type: string;
  };
}

export async function pairDevice(code: string): Promise<PairDeviceResponse> {
  return post<PairDeviceResponse>("/api/device/pair", { pairing_code: code });
}

export async function getDeviceStatus(): Promise<DeviceStatus> {
  return get<DeviceStatus>("/api/device/status");
}

// UnpairResult is the 200 body from POST /api/device/unpair. `data_kept` is true
// when a forced unpair preserved unsynced transaction data on disk for recovery.
export interface UnpairResult {
  status: string;
  device_id: string;
  data_kept?: boolean;
  unsynced_amount?: number;
}

// UnpairBlocked is the 409 body (thrown as ApiError.data) when the device still
// holds revenue that has not reached Cloud. plan-818.
export interface UnpairBlocked {
  error: string;
  message: string;
  unsynced_payments: number;
  unsynced_amount: number;
  unsynced_refunds: number;
  unsynced_orders: number;
  unsynced_items: number;
  queue_pending: number;
  queue_dead_letter: number;
  has_unsynced: boolean;
}

export async function unpairDevice(force = false): Promise<UnpairResult> {
  return post<UnpairResult>("/api/device/unpair" + (force ? "?force=true" : ""));
}

// ─── Settings ──────────────────────────────────────────────────────────

export async function getConfig(): Promise<AppConfig> {
  return get<AppConfig>("/api/config");
}

export async function getVersion(): Promise<string> {
  const data = await get<{ version: string }>("/api/version");
  return data.version;
}

export async function getSetting(key: string): Promise<string> {
  const data = await get<{ value: string }>(`/api/settings/${encodeURIComponent(key)}`);
  return data.value ?? "";
}

export async function setSetting(key: string, value: string): Promise<void> {
  await put(`/api/settings/${encodeURIComponent(key)}`, { value });
}

// ─── Audit Log ────────────────────────────────────────────────────────

export interface AuditEntry {
  id: number;
  timestamp: string;
  actor: string;
  action: string;
  entity_type: string;
  entity_id: string;
  details: string;
  ip_address: string;
}

export async function getAuditLog(params?: {
  from?: string;
  to?: string;
  action?: string;
  entity_type?: string;
  limit?: number;
}): Promise<AuditEntry[]> {
  const searchParams = new URLSearchParams();
  if (params?.from) searchParams.set("from", params.from);
  if (params?.to) searchParams.set("to", params.to);
  if (params?.action) searchParams.set("action", params.action);
  if (params?.entity_type) searchParams.set("entity_type", params.entity_type);
  if (params?.limit) searchParams.set("limit", String(params.limit));
  const qs = searchParams.toString();
  const data = await get<{ entries: AuditEntry[] }>(
    `/api/audit${qs ? `?${qs}` : ""}`,
  );
  return data.entries ?? [];
}

// ─── Load Monitor ─────────────────────────────────────────────────────

export interface LoadSnapshot {
  active_connections: number;
  total_requests: number;
  requests_per_minute: number;
  ws_clients: number;
  goroutines: number;
  memory_mb: number;
  uptime: string;
}

export async function getLoadSnapshot(): Promise<LoadSnapshot> {
  return get<LoadSnapshot>("/api/monitor");
}

// ─── Peripheral devices (P400 / 釣銭機) ──────────────────────────────────
// Cloud is source-of-truth; the workstation reads the local replica and
// forwards writes to Cloud with its device token (see local_peripheral_devices.go).

export type PeripheralType =
  | "payment_terminal"
  | "coin_changer"
  | "receipt_printer"
  | "kitchen_printer"
  | "bar_printer";

/** Types that connect over the LAN and require metadata.host. */
export const NETWORK_PERIPHERAL_TYPES: PeripheralType[] = ["payment_terminal", "coin_changer"];

export interface PeripheralDevice {
  id: string;
  name: string;
  type: string;
  is_active: boolean;
  metadata: { host?: string; port?: number; model?: string } | null;
  branch_id?: string | null;
  pending_sync?: boolean;
}

/** Glory cash-changer models reachable via the YRT-R08-MN adapter. */
export const GLORY_MODELS = ["RT-R08", "RT-RAD-300", "RT-RAD-380"];

export interface PeripheralInput {
  name: string;
  type: string;
  is_active?: boolean;
  metadata?: { host: string; port?: number } | null;
}

export async function listPeripherals(): Promise<PeripheralDevice[]> {
  const data = await get<{ data: PeripheralDevice[] }>("/api/peripheral-devices");
  return data.data;
}

export async function createPeripheral(input: PeripheralInput): Promise<PeripheralDevice> {
  const data = await post<{ data: PeripheralDevice }>("/api/peripheral-devices", input);
  return data.data;
}

export async function updatePeripheral(
  id: string,
  input: Partial<PeripheralInput>,
): Promise<PeripheralDevice> {
  const data = await put<{ data: PeripheralDevice }>(`/api/peripheral-devices/${id}`, input);
  return data.data;
}

export async function deletePeripheral(id: string): Promise<void> {
  await del(`/api/peripheral-devices/${id}`);
}
