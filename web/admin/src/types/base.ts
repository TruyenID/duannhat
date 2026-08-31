/**
 * Shared API response shapes used across services and form-error mapping.
 *
 * `ResponseError` mirrors the Laravel validation envelope returned by the
 * backend on 4xx responses — see e.g. /api/v1/hq/{brandSlug}/* endpoints.
 * Used by `src/lib/errors.ts::handleErrorApi` to surface field-level errors
 * back into react-hook-form.
 */

export interface FieldError {
  /** Form field name (matches react-hook-form's Path<T>). */
  field: string;
  /** Human-readable validation message. */
  message: string;
}

export interface ResponseError {
  /** Top-level error message — fallback when there are no field errors. */
  message: string;
  /** Optional per-field validation errors (Laravel 422 shape). */
  errors?: FieldError[];
}
