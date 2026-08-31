/**
 * Structured error thrown by `apiFetch` on every non-2xx response.
 *
 * Callers should branch on the helper getters (`isAuthError`, `isValidationError`,
 * `isServerError`) rather than parsing the raw `body`, since the JSON shape of
 * server responses can change. The getters are stable.
 *
 * On non-JSON responses (HTML 500 from the framework, network failures wrapped
 * by the runtime), `body` is `{}` and the message falls back to the HTTP status
 * line — code consuming the error still gets a usable `.message`.
 */
export class ApiError extends Error {
  public readonly status: number;
  public readonly body: Record<string, unknown>;

  constructor(status: number, body: Record<string, unknown>, message?: string) {
    super(message ?? extractMessage(body) ?? `HTTP ${status}`);
    this.name = 'ApiError';
    this.status = status;
    this.body = body;
  }

  /** 401 / 403 — token missing, expired, or device unauthorised. */
  get isAuthError(): boolean {
    return this.status === 401 || this.status === 403;
  }

  /** 422 — Laravel validation envelope. `errors` field holds field → string[]. */
  get isValidationError(): boolean {
    return this.status === 422;
  }

  /** 5xx — server-side fault, retryable in most flows. */
  get isServerError(): boolean {
    return this.status >= 500 && this.status <= 599;
  }
}

function extractMessage(body: Record<string, unknown>): string | undefined {
  const message = body['message'];
  return typeof message === 'string' ? message : undefined;
}
