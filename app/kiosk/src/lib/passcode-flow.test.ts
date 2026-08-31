import { describe, expect, it } from 'vitest';
import { resolveKioskEntry, resolveSettingsPasscodeGate } from './passcode-flow';

describe('first-pair passcode flow (#2324)', () => {
  it('forces a paired kiosk without a passcode into setup', () => {
    expect(resolveKioskEntry(true, false)).toBe('/settings');
    expect(
      resolveSettingsPasscodeGate({
        isAuthenticated: true,
        isPasscodeConfigured: false,
        isUnlocked: false,
      }),
    ).toBe('setup');
  });

  it('keeps recovery Settings reachable before pairing without arming setup', () => {
    expect(resolveKioskEntry(false, false)).toBe('/login');
    expect(
      resolveSettingsPasscodeGate({
        isAuthenticated: false,
        isPasscodeConfigured: false,
        isUnlocked: false,
      }),
    ).toBe('recovery');
  });

  it('sends a hardened kiosk to idle and verifies its existing passcode', () => {
    expect(resolveKioskEntry(true, true)).toBe('/advertise');
    expect(
      resolveSettingsPasscodeGate({
        isAuthenticated: true,
        isPasscodeConfigured: true,
        isUnlocked: false,
      }),
    ).toBe('verify');
  });

  it('opens Settings after successful setup or verification', () => {
    expect(
      resolveSettingsPasscodeGate({
        isAuthenticated: true,
        isPasscodeConfigured: true,
        isUnlocked: true,
      }),
    ).toBe('open');
  });
});
