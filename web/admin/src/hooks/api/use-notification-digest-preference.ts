/**
 * Plan-023 M5 T5.9 — TanStack Query wrapper for the digest preference.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  notificationDigestPreferenceService,
  type DigestPreferencePayload,
} from "@/services/notification-digest-preference-service";

const KEY = ["notifications", "digest-preference"] as const;

export function useDigestPreference() {
  return useQuery({
    queryKey: KEY,
    queryFn: () => notificationDigestPreferenceService.show(),
    staleTime: 60_000,
  });
}

export function useUpdateDigestPreference() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: DigestPreferencePayload) =>
      notificationDigestPreferenceService.update(payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: KEY }),
  });
}
