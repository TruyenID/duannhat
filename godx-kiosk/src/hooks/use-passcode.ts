import { useCallback, useEffect, useState } from "react";
import * as SecureStore from "expo-secure-store";
import { PASSCODE_LENGTH, SECURE_STORE_PASSCODE_KEY } from "../lib/constants";

interface UsePasscodeReturn {
  isLoading: boolean;
  /** True once an operator passcode has been persisted to secure store. */
  isConfigured: boolean;
  verify: (code: string) => boolean;
  /** First-boot set: only succeeds while no passcode is configured yet. */
  setPasscode: (newCode: string) => Promise<boolean>;
  changePasscode: (currentCode: string, newCode: string) => Promise<boolean>;
}

export function usePasscode(): UsePasscodeReturn {
  // `null` = no passcode configured yet. There is deliberately no default
  // credential: the settings lockdown stays armed until an operator sets one.
  const [storedPasscode, setStoredPasscode] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    SecureStore.getItemAsync(SECURE_STORE_PASSCODE_KEY).then((value) => {
      if (value) setStoredPasscode(value);
      setIsLoading(false);
    });
  }, []);

  const verify = useCallback(
    (code: string): boolean => {
      // No backdoor and no default: an unconfigured kiosk verifies nothing.
      if (storedPasscode === null) return false;
      return code === storedPasscode;
    },
    [storedPasscode],
  );

  const setPasscode = useCallback(
    async (newCode: string): Promise<boolean> => {
      if (storedPasscode !== null) return false;
      if (newCode.length !== PASSCODE_LENGTH) return false;
      await SecureStore.setItemAsync(SECURE_STORE_PASSCODE_KEY, newCode);
      setStoredPasscode(newCode);
      return true;
    },
    [storedPasscode],
  );

  const changePasscode = useCallback(
    async (currentCode: string, newCode: string): Promise<boolean> => {
      if (storedPasscode === null || currentCode !== storedPasscode) {
        return false;
      }
      if (newCode.length !== PASSCODE_LENGTH) {
        return false;
      }
      await SecureStore.setItemAsync(SECURE_STORE_PASSCODE_KEY, newCode);
      setStoredPasscode(newCode);
      return true;
    },
    [storedPasscode],
  );

  return {
    isLoading,
    isConfigured: storedPasscode !== null,
    verify,
    setPasscode,
    changePasscode,
  };
}
