/**
 * Device Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Device as DeviceBase } from './base/Device';
import {
  baseDeviceSchemas,
  baseDeviceCreateSchema,
  baseDeviceUpdateSchema,
  deviceI18n,
  getDeviceLabel,
  getDeviceFieldLabel,
  getDeviceFieldPlaceholder,
} from './base/Device';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

/**
 * #2900 — nguồn của số phiên bản, do backend kết luận (`DeviceResource`).
 *
 * Ba trạng thái này KHÔNG đáng tin như nhau, và đó là lý do trường tồn tại:
 *
 *  - `heartbeat` — máy tự báo trên một request đã xác thực. Là hiện trạng.
 *  - `pairing`   — giá trị DUY NHẤT từng ghi là payload lúc ghép cặp. Máy có
 *                  thể đã nâng cấp bao nhiêu lần từ đó; con số này là một phỏng
 *                  đoán mặc áo phép đo.
 *  - `unknown`   — chưa bao giờ báo gì.
 *
 * Gộp `pairing`/`unknown` thành `heartbeat` là cái hỏng đáng kể: đo trên
 * production 2026-08-15, **12/19 máy** mang số nguồn `pairing`. Hiện chúng như
 * hiện trạng là dựng ra một con số để người vận hành ra quyết định.
 */
export type DeviceAppVersionSource = 'heartbeat' | 'pairing' | 'unknown';

export interface Device extends DeviceBase {
  /**
   * Ba trường do `DeviceResource` gắn thêm khi serialize — KHÔNG có trong
   * `base/Device.ts` vì generator chỉ biết cột DB, còn đây là kết luận rút từ
   * `device_info`. Khai ở đây để frontend đọc có kiểu.
   */
  app_version?: string | null;
  app_version_source?: DeviceAppVersionSource;
  app_version_seen_at?: string | null;
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const deviceSchemas = { ...baseDeviceSchemas };
export const deviceCreateSchema = baseDeviceCreateSchema;
export const deviceUpdateSchema = baseDeviceUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type DeviceCreate = z.infer<typeof deviceCreateSchema>;
export type DeviceUpdate = z.infer<typeof deviceUpdateSchema>;

// Re-export i18n and helpers
export {
  deviceI18n,
  getDeviceLabel,
  getDeviceFieldLabel,
  getDeviceFieldPlaceholder,
};

// Re-export base type for internal use
export type { DeviceBase };
