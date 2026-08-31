/**
 * Ambient declarations for Omnify-generated service files.
 *
 * Generated services reference `apiFetch` and `PaginatedResponse` without
 * explicit imports — they are injected at the factory level by user-land code.
 * This file provides type declarations to satisfy TypeScript.
 */

declare function apiFetch<T>(path: string, options?: RequestInit): Promise<T>;

interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}
