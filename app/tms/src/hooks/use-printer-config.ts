import { useCallback, useEffect, useState } from 'react';
import AsyncStorage from '@react-native-async-storage/async-storage';

const STORAGE_KEY = 'tms_printer_ip';

function isValidIp(ip: string): boolean {
  const regex = /^(\d{1,3}\.){3}\d{1,3}$/;
  if (!regex.test(ip)) return false;
  return ip.split('.').every(n => Number(n) >= 0 && Number(n) <= 255);
}

interface UsePrinterConfigReturn {
  printerIp: string;
  isLoading: boolean;
  savePrinterIp: (ip: string) => Promise<void>;
}

export function usePrinterConfig(): UsePrinterConfigReturn {
  const [printerIp, setPrinterIp] = useState('');
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    AsyncStorage.getItem(STORAGE_KEY).then(value => {
      if (value) setPrinterIp(value);
      setIsLoading(false);
    });
  }, []);

  const savePrinterIp = useCallback(async (ip: string) => {
    const trimmed = ip.trim();
    if (!isValidIp(trimmed)) {
      throw new Error('invalid_ip');
    }
    await AsyncStorage.setItem(STORAGE_KEY, trimmed);
    setPrinterIp(trimmed);
  }, []);

  return { printerIp, isLoading, savePrinterIp };
}
