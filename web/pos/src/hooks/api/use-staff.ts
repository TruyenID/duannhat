/**
 * use-staff — Plan 030 staff list for the Open Shift "Người mở ca"
 * dropdown.
 *
 * Cached for 5 minutes — the list is read-mostly (changes only when an
 * admin adds/removes someone from the org).
 */

import { useQuery } from "@tanstack/react-query";
import { staffService, type StaffMember } from "@/services/staff-service";

export const staffKeys = {
  all: (shopSlug: string) => ["staff", shopSlug] as const,
};

export function useShopStaff(shopSlug: string) {
  return useQuery({
    queryKey: staffKeys.all(shopSlug),
    queryFn: () => staffService.list(),
    select: (r) => r.data,
    enabled: !!shopSlug,
    staleTime: 5 * 60 * 1000,
  });
}

export type { StaffMember };
