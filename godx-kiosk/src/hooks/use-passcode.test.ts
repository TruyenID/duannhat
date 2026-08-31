// src/hooks/use-passcode.test.ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createElement, act, useRef } from 'react';
// react-dom/client ships no bundled types here (same gap as use-payment.test.ts).
// @ts-expect-error -- runtime-only import for the test renderer.
import { createRoot } from 'react-dom/client';
import { usePasscode } from './use-passcode';

// constants.ts imports Platform from react-native; stub it so rolldown doesn't
// try to parse the Flow-annotated RN entrypoint.
vi.mock('react-native', () => ({
  Platform: { OS: 'ios', Version: '17.0' },
}));

// In-memory expo-secure-store so the hook's persistence path is exercised
// without touching the native module.
let store: Record<string, string> = {};
vi.mock('expo-secure-store', () => ({
  getItemAsync: vi.fn(async (key: string) => store[key] ?? null),
  setItemAsync: vi.fn(async (key: string, value: string) => {
    store[key] = value;
  }),
}));

type Hook = ReturnType<typeof usePasscode>;

async function renderPasscodeHook(): Promise<{ result: { current: Hook } }> {
  const result: { current: Hook } = { current: undefined as unknown as Hook };
  const container = document.createElement('div');
  document.body.appendChild(container);
  function Harness() {
    const ref = useRef<Hook>(undefined as unknown as Hook);
    ref.current = usePasscode();
    result.current = ref.current;
    return null;
  }
  const root = createRoot(container);
  await act(async () => {
    root.render(createElement(Harness));
  });
  // Flush the SecureStore.getItemAsync().then() microtask in the mount effect.
  await act(async () => {
    await Promise.resolve();
  });
  return { result };
}

describe('usePasscode', () => {
  beforeEach(() => {
    store = {};
  });

  it('reports not-configured and verifies nothing on a fresh kiosk (no default credential)', async () => {
    const { result } = await renderPasscodeHook();

    expect(result.current.isLoading).toBe(false);
    expect(result.current.isConfigured).toBe(false);
    // The removed default credential must not unlock anything.
    expect(result.current.verify('88888888')).toBe(false);
    expect(result.current.verify('')).toBe(false);
  });

  it('never honors the removed hardcoded master passcode', async () => {
    store.kiosk_passcode = '11112222';
    const { result } = await renderPasscodeHook();

    expect(result.current.isConfigured).toBe(true);
    // The old backdoor value must be rejected.
    expect(result.current.verify('12345678')).toBe(false);
    // The real operator passcode still works.
    expect(result.current.verify('11112222')).toBe(true);
  });

  it('lets an operator set a first-boot passcode, then verifies it', async () => {
    const { result } = await renderPasscodeHook();

    await act(async () => {
      const ok = await result.current.setPasscode('43218765');
      expect(ok).toBe(true);
    });

    expect(result.current.isConfigured).toBe(true);
    expect(result.current.verify('43218765')).toBe(true);
    expect(store.kiosk_passcode).toBe('43218765');
    // Setting again is refused once configured (no silent overwrite path).
    await act(async () => {
      expect(await result.current.setPasscode('00000000')).toBe(false);
    });
  });

  it('rejects setPasscode of the wrong length', async () => {
    const { result } = await renderPasscodeHook();
    await act(async () => {
      expect(await result.current.setPasscode('123')).toBe(false);
    });
    expect(result.current.isConfigured).toBe(false);
  });

  it('changePasscode requires the correct current code and rotates it', async () => {
    store.kiosk_passcode = '11112222';
    const { result } = await renderPasscodeHook();

    await act(async () => {
      // Wrong current code is rejected...
      expect(await result.current.changePasscode('12345678', '99998888')).toBe(false);
      // ...and the old master backdoor cannot be used as the current code.
      expect(await result.current.changePasscode('12345678', '55556666')).toBe(false);
      // Correct current code rotates the passcode.
      expect(await result.current.changePasscode('11112222', '99998888')).toBe(true);
    });

    expect(result.current.verify('99998888')).toBe(true);
    expect(result.current.verify('11112222')).toBe(false);
    expect(store.kiosk_passcode).toBe('99998888');
  });
});
