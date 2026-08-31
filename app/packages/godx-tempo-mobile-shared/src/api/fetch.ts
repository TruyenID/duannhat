import { ApiError } from './error';

export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

/**
 * Resolver functions the host app provides at apiFetch creation time.
 *
 * Each app handles auth + locale persistence differently (some use SecureStore,
 * some use AsyncStorage, web fallbacks vary). Rather than baking a particular
 * storage layer into this package, callers wire the resolvers when they
 * construct their `apiFetch` instance via {@link createApiFetch}.
 */
export interface ApiFetchResolvers {
  /** Returns the bearer token to send, or null to skip the Authorization header. */
  getToken(): Promise<string | null>;
  /** Returns the locale code to send as the Accept-Language header. */
  getLocale(): Promise<string>;
  /** Optional hook fired when a 401 lands; clear the cached token, etc. */
  onUnauthorized?(): Promise<void> | void;
}

export interface ApiFetchOptions extends Omit<RequestInit, 'signal'> {
  /**
   * AbortController signal forwarded to the underlying fetch. The package
   * handles its own 15s timeout via a separate controller; the caller's
   * signal is composed with it so either source can cancel the request.
   */
  signal?: AbortSignal;
  /**
   * Treat 401 responses as a normal error to be thrown instead of triggering
   * the `onUnauthorized` resolver. Use for fire-and-forget calls (preference
   * persistence, heartbeats) where bouncing the user to /login would surprise.
   */
  silent401?: boolean;
}

export interface CreateApiFetchOptions {
  baseUrl: string;
  resolvers: ApiFetchResolvers;
  /** Default request timeout in milliseconds. Defaults to 15_000. */
  timeoutMs?: number;
}

/**
 * Builds a fetch wrapper that handles auth, locale, timeout, and structured
 * errors uniformly across an app. Each app owns a single instance — having
 * multiple wrappers leads to inconsistent error shapes downstream.
 *
 * Example:
 *
 *     export const apiFetch = createApiFetch({
 *       baseUrl: process.env.EXPO_PUBLIC_API_URL ?? 'http://localhost:5400',
 *       resolvers: {
 *         getToken: () => getDeviceToken(),
 *         getLocale: () => AsyncStorage.getItem('locale').then(v => v ?? 'ja'),
 *         onUnauthorized: () => clearDeviceToken(),
 *       },
 *     });
 */
export function createApiFetch(options: CreateApiFetchOptions) {
  const { baseUrl, resolvers, timeoutMs = 15_000 } = options;

  return async function apiFetch<T = unknown>(
    path: string,
    init: ApiFetchOptions = {},
  ): Promise<T> {
    const { silent401, signal: callerSignal, ...rest } = init;

    const [token, locale] = await Promise.all([
      resolvers.getToken(),
      resolvers.getLocale(),
    ]);

    const headers = new Headers(rest.headers);
    if (!headers.has('Accept')) headers.set('Accept', 'application/json');
    if (rest.body && !headers.has('Content-Type')) {
      headers.set('Content-Type', 'application/json');
    }
    if (locale) headers.set('Accept-Language', locale);
    if (token) headers.set('Authorization', `Bearer ${token}`);

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);

    // Compose caller's signal with our timeout signal — either source aborts.
    const onCallerAbort = () => controller.abort();
    callerSignal?.addEventListener('abort', onCallerAbort);

    let response: Response;
    try {
      response = await fetch(buildUrl(baseUrl, path), {
        ...rest,
        headers,
        signal: controller.signal,
      });
    } catch (error) {
      if ((error as Error).name === 'AbortError') {
        throw new ApiError(0, {}, 'Request timed out or was cancelled');
      }
      throw new ApiError(0, {}, (error as Error).message);
    } finally {
      clearTimeout(timeout);
      callerSignal?.removeEventListener('abort', onCallerAbort);
    }

    if (response.status === 401 && !silent401) {
      await resolvers.onUnauthorized?.();
    }

    if (!response.ok) {
      const body = await safeJson(response);
      throw new ApiError(response.status, body);
    }

    if (response.status === 204) return undefined as T;
    return (await response.json()) as T;
  };
}

function buildUrl(baseUrl: string, path: string): string {
  if (path.startsWith('http')) return path;
  const base = baseUrl.replace(/\/+$/, '');
  const tail = path.startsWith('/') ? path : `/${path}`;
  return `${base}${tail}`;
}

async function safeJson(response: Response): Promise<Record<string, unknown>> {
  try {
    return (await response.json()) as Record<string, unknown>;
  } catch {
    return {};
  }
}
