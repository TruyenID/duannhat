/**
 * Readiness hook — React wrapper around readinessService (#2350).
 */

import { useQuery } from "@tanstack/react-query";

import { readinessService } from "@/services/readiness-service";

export const readinessKeys = {
  all: (brandSlug: string) => ["readiness", brandSlug] as const,
};

export function useReadiness(brandSlug: string) {
  return useQuery({
    queryKey: readinessKeys.all(brandSlug),
    queryFn: () => readinessService.get(brandSlug),
    enabled: !!brandSlug,
    // Baseline chỉ đổi khi ai đó chạy reconcile hoặc tạo chi nhánh — không có
    // lý do gì để poll. Người dùng bấm "làm mới" khi vừa chạy lệnh xong.
    staleTime: 60_000,
  });
}
