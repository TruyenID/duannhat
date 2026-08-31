/**
 * Staff Service — Plan 030.
 *
 * GET /api/v1/pos/staff returns the active staff list for the resolved
 * shop. pos-web calls this through workstation LAN by default (the
 * workstation proxies the request to cloud — see workstation-app
 * internal/handler/cloud_proxy.go).
 *
 * Unlike the till-service which forces cloud (because workstation does
 * NOT mirror /pos/till/* in v1), the staff endpoint IS available on the
 * workstation as a thin reverse proxy. So we leave the default
 * LAN-first resolution in place.
 */

import { apiFetch } from "@/lib/api";

export interface StaffMember {
  id: string;
  name: string;
  email: string | null;
  avatar_url: string | null;
}

export const staffService = {
  list: () =>
    apiFetch<{ data: StaffMember[] }>("/api/v1/pos/staff"),
};
