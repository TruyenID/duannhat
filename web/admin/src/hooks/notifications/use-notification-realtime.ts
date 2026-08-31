/**
 * Laravel Echo / Pusher wrapper for the NotificationReceived broadcast
 * (plan-012 T4.13). Subscribes the authenticated user's private channel,
 * invalidates the notifications query on each incoming event.
 *
 * Brand switch: pass a new `brandSlug`; the hook tears down + re-creates
 * the Echo connection with the new app_key returned by GET /me/reverb-config.
 *
 * WS loss fallback: if the connection stays disconnected more than 10s, the
 * hook returns `{ pollFallback: true }` so the caller can bump its polling
 * interval; on reconnect the flag resets.
 */

"use client";

import { useQueryClient } from "@tanstack/react-query";
import Echo from "laravel-echo";
import Pusher from "pusher-js";
import { useEffect, useState } from "react";

import { apiFetch } from "@/lib/api";

interface ReverbConfig {
  app_key: string;
  cluster: string;
  host: string;
  port: number;
  scheme: string;
}

interface UseNotificationRealtimeArgs {
  /** Optional — when set, fetch /me/reverb-config?brand=<slug>. */
  brandSlug?: string | null;
  /** Optional — when set, fetch /me/reverb-config?shop=<slug> so the backend resolves brand via the shop. */
  shopSlug?: string | null;
  userId: string | null;
  authToken?: string | null;
}

export function useNotificationRealtime({
  brandSlug,
  shopSlug,
  userId,
  authToken,
}: UseNotificationRealtimeArgs) {
  const qc = useQueryClient();
  const [pollFallback, setPollFallback] = useState(false);

  useEffect(() => {
    if (!userId) {
      return;
    }

    let echo: Echo<"pusher"> | null = null;
    let disconnectTimer: ReturnType<typeof setTimeout> | null = null;
    let cancelled = false;

    (async () => {
      const qs = new URLSearchParams();
      if (brandSlug) qs.set("brand", brandSlug);
      else if (shopSlug) qs.set("shop", shopSlug);
      const qsStr = qs.toString();

      const res = await apiFetch<{ data: ReverbConfig }>(
        `/api/v1/me/reverb-config${qsStr ? `?${qsStr}` : ""}`,
        { silent401: true }
      ).catch(() => null);

      if (cancelled || !res) return;
      const cfg = res.data;

      // Brand not yet provisioned (pre-plan-012 row without the BackfillBrandReverbAppsSeeder
      // run, or Reverb disabled in this environment). Skip silently — caller
      // falls back to polling via the pollFallback flag below.
      if (!cfg.app_key) {
        setPollFallback(true);
        return;
      }

      (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;
      const bearer = authToken;
      const authHeaders: Record<string, string> = bearer
        ? { Authorization: `Bearer ${bearer}`, Accept: "application/json" }
        : { Accept: "application/json" };
      // Laravel registers /broadcasting/auth at the site root (without the
      // /api/v1 prefix). The channels.php file pulls sso.auth middleware
      // onto it so Bearer tokens are accepted.
      echo = new Echo({
        broadcaster: "pusher",
        key: cfg.app_key,
        cluster: cfg.cluster,
        wsHost: cfg.host,
        wsPort: cfg.port,
        wssPort: cfg.port,
        forceTLS: cfg.scheme === "https",
        enabledTransports: ["ws", "wss"],
        authEndpoint: "/broadcasting/auth",
        auth: { headers: authHeaders },
      });

      const pusher = (echo.connector as unknown as { pusher: Pusher }).pusher;
      pusher.connection.bind("disconnected", () => {
        disconnectTimer = setTimeout(() => setPollFallback(true), 10_000);
      });
      pusher.connection.bind("connected", () => {
        if (disconnectTimer) clearTimeout(disconnectTimer);
        setPollFallback(false);
      });

      echo.private(`user.${userId}.notifications`).listen(".notification.received", () => {
        qc.invalidateQueries({ queryKey: ["notifications"] });
      });
    })();

    return () => {
      cancelled = true;
      if (disconnectTimer) clearTimeout(disconnectTimer);
      echo?.disconnect();
    };
  }, [brandSlug, shopSlug, userId, authToken, qc]);

  return { pollFallback };
}
