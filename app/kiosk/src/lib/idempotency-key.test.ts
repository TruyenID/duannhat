import { describe, it, expect } from 'vitest';
import { generateIdempotencyKey } from './idempotency-key';

describe('generateIdempotencyKey', () => {
  it('returns a UUID v4 string', () => {
    const key = generateIdempotencyKey();
    expect(key).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    );
  });

  it('returns a unique value each call', () => {
    const keys = new Set(Array.from({ length: 100 }, generateIdempotencyKey));
    expect(keys.size).toBe(100);
  });
});
