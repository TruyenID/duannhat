/**
 * Generate a UUID v4 string for use as an Idempotency-Key header.
 *
 * Uses Math.random() — not cryptographically strong, but the 122 bits of
 * randomness give collision probability ~0 for the realistic workload
 * (one key per checkout attempt, per kiosk). Avoids pulling in expo-crypto
 * just for this single use site.
 */
export function generateIdempotencyKey(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}
