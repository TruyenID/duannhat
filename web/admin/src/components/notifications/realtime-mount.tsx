"use client";

/**
 * Mounts the notification realtime hook for the active HQ brand context
 * (plan-012 T4.13). Tears down + re-inits on brand switch (Next remounts
 * the route segment).
 */

import { useQuery } from "@tanstack/react-query";

import { useNotificationRealtime } from "@/hooks/notifications/use-notification-realtime";
import { apiFetch } from "@/lib/api";

interface ContextResponse {
  user: { id: string; name: string; email: string };
}

interface RealtimeMountProps {
  brandSlug?: string | null;
  shopSlug?: string | null;
}

export function RealtimeMount({ brandSlug, shopSlug }: RealtimeMountProps) {
  const { data } = useQuery({
    queryKey: ["me", "context"],
    queryFn: () => apiFetch<ContextResponse>("/api/v1/me/context"),
    staleTime: 60_000,
  });

  useNotificationRealtime({
    brandSlug: brandSlug ?? null,
    shopSlug: shopSlug ?? null,
    userId: data?.user.id ?? null,
  });

  return null;
}
