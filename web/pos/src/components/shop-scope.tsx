/**
 * ShopScope — publishes the URL's `:shopSlug` into the module-level shop
 * context that `apiFetch` reads when stamping `X-Shop-Slug`.
 *
 * This has to happen during RENDER, not in an effect. `ShiftGate` fires
 * `GET /pos/till/current` on mount and returns `null` while it resolves, so
 * `PosPage` — which used to own this assignment in a `useLayoutEffect` — never
 * mounts in time. And a parent's effect is no help either: React runs child
 * effects before parent effects, so the gate's query would still leave first.
 *
 * Rendering top-down is the only ordering that beats it. Assigning during
 * render is safe here precisely because the target is plain module state, not
 * React state: it is idempotent, triggers no re-render, and a double-invoked
 * render in StrictMode just writes the same slug twice.
 *
 * Without this, the very first POS request goes out with no `X-Shop-Slug` and
 * the backend's ResolvePosShop middleware answers
 * `400 "X-Shop-Slug header is required."`, which surfaces as ShiftGate's
 * generic error screen — i.e. the POS is unusable on a cold load.
 */

import type { ReactNode } from "react";
import { useParams } from "react-router-dom";
import { setCurrentShopSlug } from "@/lib/shop-context";

export interface ShopScopeProps {
  children: ReactNode;
}

export function ShopScope({ children }: ShopScopeProps) {
  const { shopSlug = "" } = useParams<{ shopSlug: string }>();

  setCurrentShopSlug(shopSlug);

  return <>{children}</>;
}
