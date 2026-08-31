/**
 * #1806 S3 — hook cho màn HQ gom cảnh báo máy trạm theo quán.
 *
 * `refetchInterval` 60s khớp nhịp đồng bộ của máy trạm (nó đẩy alert theo tick
 * 60s). Poll dày hơn chỉ tốn request mà không sớm hơn được một giây nào, vì
 * nguồn dữ liệu ở đầu kia mới là thứ quyết định độ trễ.
 */

import { keepPreviousData, useQuery } from "@tanstack/react-query";

import {
  fetchWorkstationAlerts,
  type WorkstationAlertQuery,
} from "@/services/hq-workstation-alert-service";

export const workstationAlertKeys = {
  all: (brandSlug: string) => ["hq", brandSlug, "workstation-alerts"] as const,
  feed: (brandSlug: string, query: WorkstationAlertQuery) =>
    ["hq", brandSlug, "workstation-alerts", query] as const,
};

export function useWorkstationAlertFeed(brandSlug: string, query: WorkstationAlertQuery) {
  return useQuery({
    queryKey: workstationAlertKeys.feed(brandSlug, query),
    queryFn: () => fetchWorkstationAlerts(brandSlug, query),
    // Giữ dữ liệu cũ khi đổi cửa sổ/mức: bảng trống một nhịp trên màn CẢNH BÁO
    // đọc y hệt "không có sự cố nào".
    placeholderData: keepPreviousData,
    refetchInterval: 60_000,
    refetchOnWindowFocus: true,
    staleTime: 30_000,
  });
}
