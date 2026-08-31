/**
 * Broadcast composer mutation hook (plan-012 T4.12).
 */

import { useMutation, useQueryClient } from "@tanstack/react-query";

import { broadcastService, type BroadcastInput } from "@/services/notification-broadcast-service";

export function useBroadcast(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: BroadcastInput) => broadcastService.send(brandSlug, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["hq", brandSlug, "notifications"] });
    },
  });
}
