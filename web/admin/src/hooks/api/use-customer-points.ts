/**
 * Customer point hooks — #1700. Chỉ ĐỌC: điểm chỉ đổi được qua hành động của
 * khách (tích khi đơn đóng, tiêu khi khách bấm đổi), không có đường ghi nào từ
 * admin. Sửa điểm bằng tay là một tính năng khác, cần chốt quyền hạn trước.
 */

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import {
  customerPointService,
  scopeKey,
  type PointScope,
  type RedemptionFilters,
} from "@/services/customer-point-service";
import { useTranslation } from "@/providers/app-provider";
import { customerPointKeys } from "./query-keys";

/**
 * Sổ điểm của một khách.
 *
 * Trả 403 với khách chưa gắn tổ chức (`organization_id = NULL` — dữ liệu cũ
 * trước #1505). Màn hình gọi hook này phải coi lỗi là "không xem được ở đây",
 * không phải "khách không có điểm": nhật ký đổi thưởng vẫn thấy họ.
 */
export function useCustomerPoints(scope: PointScope, customerId: string | null, page = 1) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: customerPointKeys.forCustomer(scopeKey(scope), locale, customerId ?? "", page),
    queryFn: () => customerPointService.pointsForCustomer(scope, customerId as string, { page }),
    enabled: !!customerId,
  });
}

/**
 * Nhật ký đổi thưởng của brand.
 *
 * `keepPreviousData` vì màn này gần như luôn được dùng qua bộ lọc: đổi ngày
 * hay đổi phần thưởng mà bảng nháy về khung xương thì mất chỗ đang đọc.
 */
export function useRedemptions(scope: PointScope, filters?: RedemptionFilters) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: customerPointKeys.redemptions(scopeKey(scope), locale, filters),
    queryFn: () => customerPointService.redemptions(scope, filters),
    placeholderData: keepPreviousData,
  });
}
